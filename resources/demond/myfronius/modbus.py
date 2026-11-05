# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

"""
Pilotage de la batterie par Modbus TCP SunSpec (spec §12) — écriture seule, la lecture des
données reste sur la Solar API.

- :class:`ModbusTcp` : client minimal en Python standard (MBAP, fonctions 03 et 16).
- :func:`find_model` : parcours de la chaîne SunSpec pour localiser un modèle (124 = stockage),
  quelle que soit la configuration int+SF ou float de l'onduleur.
- :class:`BatteryControl` : mode désiré (auto, hold, no_charge, force_charge, limit_charge) et
  réserve, appliqués à chaque cycle avec relance du timer de retour automatique de l'onduleur.
"""

import logging
import socket
import struct

STORAGE_MODEL = 124
SUNS_BASE = 40000
REVERT_SECONDS = 120

# Offsets des points du modèle 124 (après ID et L)
OFF_WCHAMAX, OFF_STORCTL, OFF_MINRSV, OFF_CHASTATE = 0, 3, 5, 6
OFF_OUTWRTE, OFF_INWRTE, OFF_RVRTTMS, OFF_CHAGRISET = 10, 11, 13, 15
OFF_WCHAMAX_SF, OFF_MINRSV_SF, OFF_CHASTATE_SF, OFF_INOUT_SF = 16, 19, 20, 23
MODEL_LENGTH = 24
# Échelle des écritures (% × 100) : les SF lus doivent valoir -2, sinon écriture refusée
WRITE_SF = -2

MODES = ('auto', 'hold', 'no_charge', 'force_charge', 'limit_charge')


class ModbusError(Exception):
    """`relocate` : l'erreur met en cause l'adresse du modèle (en-tête incohérent, nombre de
    registres inattendu) → modèle relocalisé au cycle suivant."""

    def __init__(self, code, message='', relocate=False):
        self.code = code
        self.relocate = relocate
        super().__init__('Modbus erreur %s: %s' % (code, message))


# ---------------------------------------------------------------------------
# Trames
# ---------------------------------------------------------------------------

def build_request(transaction_id, unit, function, address, data):
    """Trame MBAP + PDU. function 3 : data = nombre de registres ; 16 : data = liste de valeurs."""
    if function == 3:
        pdu = struct.pack('>BHH', 3, address, int(data))
    elif function == 16:
        values = [int(v) & 0xFFFF for v in data]
        pdu = struct.pack('>BHHB', 16, address, len(values), 2 * len(values)) + struct.pack('>%dH' % len(values), *values)
    else:
        raise ValueError('fonction Modbus non supportée: %s' % function)
    return struct.pack('>HHHB', transaction_id, 0, len(pdu) + 1, unit) + pdu


def parse_response(frame, transaction_id, function, expected=None):
    """Registres lus (fonction 3) ou None (fonction 16). Lève ModbusError sur exception/incohérence,
    dont un nombre de registres différent de `expected` (nombre demandé) quand il est donné."""
    if len(frame) < 9:
        raise ModbusError(-1, 'réponse tronquée')
    tid, _, length, _unit, fn = struct.unpack('>HHHBB', frame[:8])
    if tid != transaction_id:
        raise ModbusError(-1, 'identifiant de transaction inattendu (%s ≠ %s)' % (tid, transaction_id))
    if fn == (function | 0x80):
        raise ModbusError(frame[8], _EXCEPTIONS.get(frame[8], 'exception %d' % frame[8]))
    if fn != function:
        raise ModbusError(-1, 'fonction inattendue %s' % fn)
    if function == 3:
        count = frame[8]
        regs = frame[9:9 + count]
        if len(regs) < count:
            raise ModbusError(-1, 'données tronquées')
        if expected is not None and count != 2 * int(expected):
            raise ModbusError(-1, 'nombre de registres inattendu (%d octets pour %d registres)' % (count, int(expected)),
                              relocate=True)
        return list(struct.unpack('>%dH' % (count // 2), regs[:count // 2 * 2]))
    return None


_EXCEPTIONS = {1: 'ILLEGAL FUNCTION', 2: 'ILLEGAL DATA ADDRESS', 3: 'ILLEGAL DATA VALUE',
               4: 'SLAVE DEVICE FAILURE', 6: 'SLAVE DEVICE BUSY'}


class ModbusTcp:
    """Une connexion par requête : simple, sans état, robuste aux coupures."""

    def __init__(self, host, port=502, unit=1, timeout=5.0):
        self.host = host
        self.port = int(port)
        self.unit = int(unit)
        self.timeout = timeout
        self._tid = 0

    @staticmethod
    def to_signed(value):
        return value - 0x10000 if value >= 0x8000 else value

    @staticmethod
    def to_unsigned(value):
        return int(value) & 0xFFFF

    def _exchange(self, function, address, data):
        self._tid = (self._tid + 1) & 0xFFFF
        request = build_request(self._tid, self.unit, function, address, data)
        with socket.create_connection((self.host, self.port), timeout=self.timeout) as sock:
            sock.sendall(request)
            head = self._recv_exact(sock, 6)
            length = struct.unpack('>H', head[4:6])[0]
            body = self._recv_exact(sock, length)
        return parse_response(head + body, self._tid, function, expected=data if function == 3 else None)

    @staticmethod
    def _recv_exact(sock, size):
        buf = b''
        while len(buf) < size:
            chunk = sock.recv(size - len(buf))
            if not chunk:
                raise ModbusError(-1, 'connexion fermée par l\'onduleur')
            buf += chunk
        return buf

    def read(self, address, count):
        return self._exchange(3, address, count)

    def write(self, address, values):
        self._exchange(16, address, list(values))


# ---------------------------------------------------------------------------
# SunSpec
# ---------------------------------------------------------------------------

def find_model(client, model_id, max_models=50):
    """{'base': adresse du premier point, 'length': L} du modèle demandé, ou None s'il est absent."""
    head = client.read(SUNS_BASE, 2)
    if head[:2] != [0x5375, 0x6E53]:
        raise ModbusError(-1, 'pas de carte SunSpec en %d (Modbus TCP activé ? unit ID ?)' % SUNS_BASE)
    pos = SUNS_BASE + 2
    for _ in range(max_models):
        ident, length = client.read(pos, 2)
        if ident == 0xFFFF:
            return None
        if ident == model_id:
            return {'base': pos + 2, 'length': int(length)}
        pos += 2 + int(length)
    raise ModbusError(-1, 'chaîne SunSpec trop longue')


def _regs_to_str(regs):
    raw = b''.join(struct.pack('>H', r & 0xFFFF) for r in regs)
    return raw.split(b'\0', 1)[0].decode('ascii', errors='replace').strip()


def read_common_info(client):
    """Modèle SunSpec 1 (Common) : fabricant, modèle, options, version, numéro de série. {} si absent."""
    model = find_model(client, 1)
    if model is None:
        return {}
    regs = client.read(model['base'], 64)
    return {
        'manufacturer': _regs_to_str(regs[0:16]),
        'model':        _regs_to_str(regs[16:32]),
        'options':      _regs_to_str(regs[32:40]),
        'version':      _regs_to_str(regs[40:48]),
        'serial':       _regs_to_str(regs[48:64]),
    }


# ---------------------------------------------------------------------------
# Pilotage
# ---------------------------------------------------------------------------

class BatteryControl:
    """Consigne désirée + application par cycle. `client` doit fournir read(addr, n) et write(addr, values)."""

    def __init__(self, client, state=None, revert_seconds=REVERT_SECONDS):
        self.client = client
        self.revert_seconds = int(revert_seconds)
        self.mode = 'auto'
        self.setpoint = 0            # W (force_charge / limit_charge)
        self.reserve = None          # % désiré, None = ne pas toucher
        self.model = None            # {'base', 'length'}
        self.wchamax = None
        self.last_error = None
        self._needs_reset = False    # revenir aux valeurs par défaut au prochain apply (retour en auto)
        self._grid_warned = False    # avertissement ChaGriSet = 0 déjà émis pour cette consigne
        self._foreign_warned = False # StorCtl_Mod ≠ 0 posé par un autre maître, déjà signalé
        if state:
            self._import_state(state)

    # --- état persistant ---------------------------------------------------------

    def export_state(self):
        return {'mode': self.mode, 'setpoint': self.setpoint, 'reserve': self.reserve,
                'needs_reset': self._needs_reset}

    def _import_state(self, state):
        mode = state.get('mode')
        if mode in MODES:
            self.mode = mode
        try:
            self.setpoint = max(0, int(state.get('setpoint') or 0))
        except (TypeError, ValueError):
            self.setpoint = 0
        reserve = state.get('reserve')
        self.reserve = int(reserve) if isinstance(reserve, (int, float)) else None
        # Remise à zéro en attente persistée : un retour en auto dont l'écriture a échoué est
        # retenté après un redémarrage (sinon StorCtl_Mod ≠ 0 passerait pour un autre maître)
        self._needs_reset = self.mode != 'auto' or bool(state.get('needs_reset'))

    # --- ordres Jeedom -----------------------------------------------------------

    def request(self, cmd, value=None, backup_active=False):
        """Enregistre une consigne ; False si refusée (inconnue, valeur invalide, secours actif)."""
        if cmd == 'reserve':
            if backup_active:
                # Ignorée par l'onduleur en secours (constaté le 23/10/2026) et conservée après :
                # refusée comme les modes, la consigne en place est gardée
                logging.warning('[battery] réserve %s refusée : secours actif', value)
                return False
            try:
                self.reserve = max(0, min(100, int(round(float(value)))))
            except (TypeError, ValueError):
                return False
            return True
        if cmd not in MODES:
            return False
        if backup_active and cmd != 'auto':
            logging.warning('[battery] mode %s refusé : secours actif', cmd)
            return False
        if cmd in ('force_charge', 'limit_charge'):
            try:
                self.setpoint = max(0, int(round(float(value))))
            except (TypeError, ValueError):
                return False
        else:
            self.setpoint = 0
        if cmd == 'auto' and self.mode != 'auto':
            self._needs_reset = True
        self.mode = cmd
        self._grid_warned = False
        return True

    # --- cycle -------------------------------------------------------------------

    def apply(self, backup_active=False):
        """Lit l'état de contrôle, écrit la consigne si nécessaire, renvoie les valeurs pour Jeedom."""
        out = {'ctrl_mode': self.mode, 'ctrl_setpoint': int(self.setpoint), 'ctrl_active': 0, 'modbus_ok': 0}
        if self.reserve is not None:
            out['ctrl_reserve'] = int(self.reserve)
        try:
            if self.model is None:
                self.model = find_model(self.client, STORAGE_MODEL)
                if self.model is None:
                    raise ModbusError(-1, 'modèle SunSpec 124 (stockage) absent')
            base = self.model['base']
            # En-tête (ID, L) relu à chaque cycle : la chaîne SunSpec peut se décaler (type de
            # modèle int+SF ↔ float, firmware) sans redémarrage du démon → jamais d'écriture à
            # une adresse qui n'est plus celle du modèle 124
            block = self.client.read(base - 2, MODEL_LENGTH + 2)
            if len(block) < MODEL_LENGTH + 2 or block[0] != STORAGE_MODEL or block[1] < MODEL_LENGTH:
                raise ModbusError(-1, 'chaîne SunSpec modifiée (modèle 124 déplacé) : relocalisation au prochain cycle',
                                  relocate=True)
            regs = block[2:]
            sf = lambda off: 10 ** ModbusTcp.to_signed(regs[off])
            self.wchamax = int(round(regs[OFF_WCHAMAX] * sf(OFF_WCHAMAX_SF)))
            out['wchamax'] = self.wchamax
            out['ctrl_reserve'] = int(round(regs[OFF_MINRSV] * sf(OFF_MINRSV_SF)))
            # ChaGriSet (0 = PV seul, 1 = réseau) : réglé dans le web UI Fronius, jamais écrit
            if regs[OFF_CHAGRISET] in (0, 1):
                out['grid_charge_allowed'] = int(regs[OFF_CHAGRISET])

            if backup_active and self.mode != 'auto':
                logging.warning('[battery] secours actif : retour en automatique')
                self.mode, self.setpoint, self._needs_reset = 'auto', 0, True
                out['ctrl_mode'], out['ctrl_setpoint'] = 'auto', 0

            self._check_scale_factors(regs)

            if self.mode == 'force_charge' and regs[OFF_CHAGRISET] == 0 and not self._grid_warned:
                logging.warning('[battery] charge réseau interdite dans le web UI Fronius (ChaGriSet=0) : '
                                'force_charge limité au PV')
                self._grid_warned = True

            if self.mode != 'auto':
                self._write_mode(base, regs)
                # Réponse FC16 sans exception = acquittement Modbus : ctrl_active signifie « consigne
                # écrite sans erreur ce cycle » (pas de relecture de contrôle)
                out['ctrl_active'] = 1
            elif self._needs_reset:
                self._write_defaults(base)
                self._needs_reset = False
            elif regs[OFF_STORCTL] != 0:
                # Gestion automatique : aucune écriture hors transition, la consigne d'un autre
                # maître Modbus (evcc, EMS…) n'est pas écrasée
                if not self._foreign_warned:
                    logging.warning('[battery] StorCtl_Mod=%d posé par un autre maître Modbus : laissé tel quel '
                                    'en gestion automatique', regs[OFF_STORCTL])
                    self._foreign_warned = True
            else:
                self._foreign_warned = False

            if self.reserve is not None:
                target = int(self.reserve * 100)
                if regs[OFF_MINRSV] != target:
                    self.client.write(base + OFF_MINRSV, [target])
                out['ctrl_reserve'] = int(self.reserve)

            out['modbus_ok'] = 1
            self.last_error = None
        except (ModbusError, OSError, IndexError, ValueError, struct.error) as exc:
            # Réponse incohérente (nombre de registres, trame) comprise : le canal Modbus ne fait
            # jamais échouer le cycle Solar API. Modèle relocalisé au cycle suivant seulement si
            # son adresse est en cause (en-tête relu à chaque cycle de toute façon) : écriture
            # refusée, timeout ou facteur d'échelle inattendu ne relancent pas le parcours SunSpec.
            if isinstance(exc, ModbusError):
                relocate = exc.relocate or exc.code == 2        # 2 = ILLEGAL DATA ADDRESS
            else:
                relocate = not isinstance(exc, OSError)         # données incohérentes
            if relocate:
                self.model = None
            self.last_error = str(exc)
            out['ctrl_active'] = 0
            out['modbus_ok'] = 0
            logging.warning('[battery] Modbus : %s', exc)
        return out

    def _check_scale_factors(self, regs):
        """Écritures en % × 100 : refusées (ModbusError, aucune écriture) si les SF concernés ≠ -2."""
        if self.mode != 'auto' or self._needs_reset:
            inout_sf = ModbusTcp.to_signed(regs[OFF_INOUT_SF])
            if inout_sf != WRITE_SF:
                raise ModbusError(-1, "facteur d'échelle InOutWRte_SF=%d inattendu (attendu %d) : écriture refusée" % (inout_sf, WRITE_SF))
        if self.reserve is not None:
            minrsv_sf = ModbusTcp.to_signed(regs[OFF_MINRSV_SF])
            if minrsv_sf != WRITE_SF:
                raise ModbusError(-1, "facteur d'échelle MinRsvPct_SF=%d inattendu (attendu %d) : écriture refusée" % (minrsv_sf, WRITE_SF))

    def _percent(self):
        if not self.wchamax:
            return 0
        return max(0, min(10000, int(round(self.setpoint / float(self.wchamax) * 10000))))

    def _write_mode(self, base, regs):
        # Relance du timer de retour automatique, puis taux, puis mode (le mode active les limites)
        self.client.write(base + OFF_RVRTTMS, [self.revert_seconds])
        if self.mode == 'hold':
            self.client.write(base + OFF_OUTWRTE, [0])
            self.client.write(base + OFF_STORCTL, [2])
        elif self.mode == 'no_charge':
            self.client.write(base + OFF_INWRTE, [0])
            self.client.write(base + OFF_STORCTL, [1])
        elif self.mode == 'force_charge':
            self.client.write(base + OFF_OUTWRTE, [ModbusTcp.to_unsigned(-self._percent())])
            self.client.write(base + OFF_STORCTL, [2])
        elif self.mode == 'limit_charge':
            self.client.write(base + OFF_INWRTE, [self._percent()])
            self.client.write(base + OFF_STORCTL, [1])

    def _write_defaults(self, base):
        self.client.write(base + OFF_OUTWRTE, [10000])
        self.client.write(base + OFF_INWRTE, [10000])
        self.client.write(base + OFF_STORCTL, [0])
        logging.info('[battery] retour en gestion automatique Fronius')
