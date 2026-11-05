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
Bornes Fronius Wattpilot : protocole WebSocket (rétro-conçu, identique à l'app Wattpilot.Solar),
client WebSocket minimal en Python standard, liaison par borne en thread, et mapping vers les
commandes Jeedom (spec §11).

Trois couches :

- :class:`WattpilotProtocol` — pur, sans réseau : secret (PBKDF2 ou bcrypt), réponse d'auth,
  signature HMAC des écritures, traitement des messages entrants → ``props``.
- :class:`WebSocketClient` — RFC 6455 côté client sur ``socket`` (handshake HTTP, trames texte,
  ping/pong, masquage). Aucune dépendance à la version du paquet ``websockets``.
- :class:`WattpilotLink` — thread par borne : connexion, reconnexion avec backoff, file de
  commandes, instantané ``snapshot()`` prêt pour Jeedom.
"""

import base64
import hashlib
import hmac
import json
import logging
import os
import queue
import re
import secrets
import socket
import struct
import sys
import threading
import time
import traceback

try:
    import bcrypt as _bcrypt
except ImportError:  # paquet apt python3-bcrypt absent : seules les bornes PBKDF2 marcheront
    _bcrypt = None

# ---------------------------------------------------------------------------
# Constantes et libellés
# ---------------------------------------------------------------------------

MODES = (3, 4, 5)
MODE_LABELS = {3: 'Défaut', 4: 'Éco', 5: 'Prochain trajet'}
CAR_LABELS = {0: 'inconnu', 1: 'absente', 2: 'en charge', 3: 'en attente', 4: 'terminé', 5: 'erreur'}
FORCE_LABELS = {0: 'neutre', 1: 'arrêt forcé', 2: 'marche forcée'}
MODEL_STATUS = {
    0: 'NotChargingBecauseNoChargeCtrlData', 1: 'NotChargingBecauseOvertemperature',
    2: 'NotChargingBecauseAccessControlWait', 3: 'ChargingBecauseForceStateOn',
    4: 'NotChargingBecauseForceStateOff', 5: 'NotChargingBecauseScheduler',
    6: 'NotChargingBecauseEnergyLimit', 7: 'ChargingBecauseAwattarPriceLow',
    8: 'ChargingBecauseAutomaticStopTestLadung', 9: 'ChargingBecauseAutomaticStopNotEnoughTime',
    10: 'ChargingBecauseAutomaticStop', 11: 'ChargingBecauseAutomaticStopNoClock',
    12: 'ChargingBecausePvSurplus', 13: 'ChargingBecauseFallbackGoEDefault',
    14: 'ChargingBecauseFallbackGoEScheduler', 15: 'ChargingBecauseFallbackDefault',
    16: 'NotChargingBecauseFallbackGoEAwattar', 17: 'NotChargingBecauseFallbackAwattar',
    18: 'NotChargingBecauseFallbackAutomaticStop', 19: 'ChargingBecauseCarCompatibilityKeepAlive',
    20: 'ChargingBecauseChargePauseNotAllowed', 22: 'NotChargingBecauseSimulateUnplugging',
    23: 'NotChargingBecausePhaseSwitch', 24: 'NotChargingBecauseMinPauseDuration',
}
FLEX_DEVICE_TYPE = 'wattpilot_flex'
AMP_MIN = 6                 # A : minimum du protocole (IEC 61851)
AMP_MAX_DEFAULT = 32        # A : plafond si la limite d'installation `ama` n'est pas encore connue

# Alphabet base64 de bcrypt.js (encodage du sel à partir du numéro de série)
_BCRYPT_B64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'


# ---------------------------------------------------------------------------
# Secrets
# ---------------------------------------------------------------------------

def pbkdf2_secret(password, serial):
    """Secret PBKDF2 (bornes classiques) : SHA-512, sel = serial, 100 000 itérations, base64[:32]."""
    raw = hashlib.pbkdf2_hmac('sha512', password.encode(), (serial or '').encode(), 100000, 256)
    return base64.b64encode(raw)[:32]


def _bcryptjs_b64(data, length):
    """Port de bcrypt.js encodeBase64 (sel de 16 octets → 22 caractères)."""
    out = []
    off = 0
    while off < length:
        c1 = data[off] & 0xFF
        off += 1
        out.append(_BCRYPT_B64[(c1 >> 2) & 0x3F])
        c1 = (c1 & 0x03) << 4
        if off >= length:
            out.append(_BCRYPT_B64[c1 & 0x3F])
            break
        c2 = data[off] & 0xFF
        off += 1
        c1 |= (c2 >> 4) & 0x0F
        out.append(_BCRYPT_B64[c1 & 0x3F])
        c1 = (c2 & 0x0F) << 2
        if off >= length:
            out.append(_BCRYPT_B64[c1 & 0x3F])
            break
        c2 = data[off] & 0xFF
        off += 1
        c1 |= (c2 >> 6) & 0x03
        out.append(_BCRYPT_B64[c1 & 0x3F])
        out.append(_BCRYPT_B64[c2 & 0x3F])
    return ''.join(out)


def bcrypt_secret(password, serial, cost=8):
    """Secret bcrypt (Wattpilot Flex) : bcrypt(sha256hex(mot de passe), sel dérivé du serial), sans le sel."""
    if _bcrypt is None:
        raise RuntimeError('module Python bcrypt absent (paquet apt python3-bcrypt) : authentification Flex impossible')
    if not serial.isascii():
        raise ValueError('numéro de série non ASCII')
    values = [ord(ch) - ord('0') for ch in serial] if serial.isdigit() else [ord(ch) for ch in serial]
    values = values[-16:]
    salt_bytes = bytes([0] * (16 - len(values)) + values)
    salt = ('$2a$' + ('0' if cost < 10 else '') + str(cost) + '$' + _bcryptjs_b64(salt_bytes, 16)).encode()
    prehash = hashlib.sha256(password.encode('utf-8')).hexdigest().encode('utf-8')
    return _bcrypt.hashpw(prehash, salt)[len(salt):].rstrip()


def auth_response(token1, token2, token3, secret):
    hash1 = hashlib.sha256(token1.encode() + secret).hexdigest()
    return hashlib.sha256((token3 + token2 + hash1).encode()).hexdigest()


def sign_secured(message, secret):
    payload = json.dumps(message)
    digest = hmac.new(bytearray(secret), bytearray(payload.encode()), hashlib.sha256).hexdigest()
    return {'type': 'securedMsg', 'data': payload, 'requestId': str(message['requestId']) + 'sm', 'hmac': digest}


# ---------------------------------------------------------------------------
# Protocole (pur)
# ---------------------------------------------------------------------------

class WattpilotProtocol:
    """Machine à états du dialogue : alimenter :meth:`handle` avec chaque message JSON reçu."""

    def __init__(self, password):
        self.password = password
        self.serial = None
        self.device_type = ''
        self.secured = 0
        self.hash_type = None
        self.secret = None
        self.authenticated = False
        self.ready = False          # fullStatus complet reçu
        self.auth_error = None
        self.props = {}
        self.request_id = 0
        self.pending = {}           # requestId → (clé, valeur avant écriture) des ordres sans réponse

    def handle(self, msg):
        """Traite un message entrant ; renvoie la liste des messages à envoyer en réponse."""
        kind = msg.get('type')
        if kind == 'hello':
            self.serial = str(msg.get('serial') or '')
            self.device_type = str(msg.get('devicetype') or '')
            self.secured = int(msg.get('secured') or 0)
            return []
        if kind == 'authRequired':
            raw = msg.get('hash')
            if raw in ('bcrypt', 'pbkdf2'):
                self.hash_type = raw
            else:
                self.hash_type = 'bcrypt' if self.device_type == FLEX_DEVICE_TYPE else 'pbkdf2'
            self.secret = (bcrypt_secret if self.hash_type == 'bcrypt' else pbkdf2_secret)(self.password, self.serial)
            token1, token2 = msg.get('token1'), msg.get('token2')
            if not token1 or not token2:
                self.auth_error = 'authRequired incomplet'
                return []
            token3 = secrets.token_hex(16)
            return [{'type': 'auth', 'token3': token3, 'hash': auth_response(token1, token2, token3, self.secret)}]
        if kind == 'authSuccess':
            self.authenticated = True
            self.auth_error = None
            return []
        if kind == 'authError':
            self.authenticated = False
            self.auth_error = str(msg.get('message') or 'authentification refusée')
            return []
        if kind == 'fullStatus':
            self.props.update(msg.get('status') or {})
            if not msg.get('partial', False):
                self.ready = True
            return []
        if kind == 'deltaStatus':
            self.props.update(msg.get('status') or {})
            # Avant l'authentification (borne qui diffuse sans jamais envoyer authRequired, 03/10),
            # quelques deltaStatus ne font pas un état complet
            if self.authenticated:
                self.ready = True
            return []
        if kind == 'response':
            # requestId de l'écriture (suffixe « sm » des messages signés ignoré)
            pending = self.pending.pop(str(msg.get('requestId') or '').replace('sm', ''), None)
            if msg.get('success'):
                self.props.update(msg.get('status') or {})
            else:
                if pending is not None:     # refusée : on revient à la valeur d'avant l'écriture
                    self.props[pending[0]] = pending[1]
                logging.warning('[wattpilot %s] commande refusée (requestId=%s): %s',
                                self.serial, msg.get('requestId'), msg.get('message'))
            return []
        return []

    def set_value(self, key, value):
        """Message d'écriture d'une propriété (signé si l'appareil l'exige)."""
        self.request_id += 1
        message = {'type': 'setValue', 'requestId': self.request_id, 'key': key, 'value': value}
        if self.secured > 0:
            return sign_secured(message, self.secret)
        return message

    def assume(self, key, value):
        """Valeur écrite (dernier set_value) supposée appliquée en attendant la réponse de la borne :
        les ordres relatifs suivants partent du mode attendu ; une réponse en échec la remet."""
        self.pending[str(self.request_id)] = (key, self.props.get(key))
        self.props[key] = value


# ---------------------------------------------------------------------------
# Mapping vers les commandes Jeedom
# ---------------------------------------------------------------------------

def cycle_mode(current, step):
    """Mode suivant/précédent, ou None si le mode actuel est inconnu (jamais « Défaut » par défaut)."""
    try:
        index = MODES.index(int(current))
    except (TypeError, ValueError):
        return None
    return MODES[(index + step) % len(MODES)]


def parse_mode(value):
    """Code de mode (3, 4, 5) depuis un code ou un libellé (« Éco »), sinon None."""
    try:
        mode = int(value)
    except (TypeError, ValueError):
        label = str(value if value is not None else '').strip().lower()
        mode = next((code for code, text in MODE_LABELS.items() if text.lower() == label), None)
    return mode if mode in MODES else None


def _num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def parse_time_of_day(value):
    """Secondes depuis minuit depuis « HH:MM », « HHhMM », « HH:MM:SS » ou un nombre de secondes
    (0 à 86399) ; None si invalide."""
    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        seconds = int(value)
    else:
        text = str(value if value is not None else '').strip()
        if text.isdigit():
            seconds = int(text)
        else:
            m = re.match(r'^(\d{1,2})\s*[:hH]\s*(\d{2})(?::(\d{2}))?$', text)
            if not m:
                return None
            hours, minutes, secs = int(m.group(1)), int(m.group(2)), int(m.group(3) or 0)
            if hours > 23 or minutes > 59 or secs > 59:
                return None
            seconds = hours * 3600 + minutes * 60 + secs
    return seconds if 0 <= seconds < 86400 else None


def _int(v):
    n = _num(v)
    return None if n is None else int(n)


def _uptime(ms):
    seconds = _int(ms)
    if seconds is None or seconds < 0:
        return '?'
    seconds //= 1000
    days, rest = divmod(seconds, 86400)
    hours, rest = divmod(rest, 3600)
    if days:
        return '%d j %d h' % (days, hours)
    return '%d h %02d min' % (hours, rest // 60)


def car_transition_message(logical_id, old, new, props):
    """Ligne de log (info) d'un changement d'état voiture, avec le diagnostic brut de la borne au même
    instant. Sert à expliquer après coup un « absente » inattendu : pause du mode Éco (raison
    PhaseSwitch / FallbackAwattar, session conservée), câble réellement retiré (session remise à 0),
    erreur de la borne (`err`), redémarrage (`rbc` / `rbt`) ; `pha` = contacteur et relais par phase."""
    def label(code):
        code = _int(code)
        return 'inconnu' if code is None else CAR_LABELS.get(code, str(code))
    status = _int(props.get('modelStatus'))
    alw = props.get('alw')
    acu = _int(props.get('acu'))
    pha = props.get('pha')
    wh = _num(props.get('wh'))
    trx = props.get('trx')
    return ('[wattpilot %s] voiture : %s → %s (raison %s, charge autorisée %s, courant autorisé %s, erreur %s, '
            'redémarrages %s, démarrée depuis %s, phases %s, transaction %s, session %s)' % (
                logical_id, label(old), label(new),
                '?' if status is None else '%d %s' % (status, MODEL_STATUS.get(status, '?')),
                '?' if alw is None else ('oui' if alw else 'non'),
                '?' if acu is None else '%d A' % acu,
                '?' if props.get('err') is None else props.get('err'),
                '?' if props.get('rbc') is None else props.get('rbc'),
                _uptime(props.get('rbt')),
                ''.join('1' if p else '0' for p in pha) if isinstance(pha, list) else '?',
                '—' if trx is None else trx,
                '?' if wh is None else '%.2f kWh' % (wh / 1000.0)))


def charger_output(props, connected):
    """Dict plat {logicalId: valeur} pour un équipement charger (clés absentes si non disponibles)."""
    out = {'connected': 1 if connected else 0}
    if not connected:
        # Liaison perdue : mesures à zéro et voiture « inconnu » plutôt que la dernière valeur figée
        # (sinon Jeedom garde p = 9 kW « en charge » après le départ de la voiture) ; réglages inchangés.
        out.update({'p': 0, 'phases': 0, 'car': 0, 'car_text': CAR_LABELS[0]})
        for n in range(1, 4):
            out['p_l%d' % n] = 0
            out['i_l%d' % n] = 0.0
        return out
    if not props:
        return out

    def put(key, value):
        if value is not None:
            out[key] = value

    nrg = props.get('nrg') if isinstance(props.get('nrg'), list) else []
    if len(nrg) >= 12:
        put('p', int(round(_num(nrg[11]) or 0)))
        for n in range(3):
            put('u_l%d' % (n + 1), round(_num(nrg[n]) or 0, 1))
            put('i_l%d' % (n + 1), round(_num(nrg[4 + n]) or 0, 2))
            put('p_l%d' % (n + 1), int(round(_num(nrg[7 + n]) or 0)))
        put('phases', sum(1 for n in range(3) if (_num(nrg[4 + n]) or 0) > 0.5))

    car = _int(props.get('car'))
    put('car', car)
    put('car_text', CAR_LABELS.get(car) if car is not None else None)
    mode = _int(props.get('lmo'))
    put('mode_code', mode)
    put('mode', MODE_LABELS.get(mode, str(mode)) if mode is not None else None)
    put('amp', _int(props.get('amp')))
    put('ama', _int(props.get('ama')))
    put('acu', _int(props.get('acu')))
    put('psm', _int(props.get('psm')))
    alw = props.get('alw')
    put('alw', None if alw is None else (1 if alw else 0))
    frc = _int(props.get('frc'))
    put('frc', frc)
    put('force_text', FORCE_LABELS.get(frc) if frc is not None else None)
    status = _int(props.get('modelStatus'))
    put('model_status', status)
    put('model_status_text', MODEL_STATUS.get(status, str(status)) if status is not None else None)
    wh = _num(props.get('wh'))
    put('e_session', None if wh is None else round(wh / 1000.0, 3))
    eto = _num(props.get('eto'))
    put('e_total', None if eto is None else round(eto / 1000.0, 3))
    put('fst', _int(props.get('fst')))
    put('fam', _int(props.get('fam')))
    fup = props.get('fup')
    put('fup', None if fup is None else (1 if fup else 0))
    fte = _num(props.get('fte'))
    put('fte', None if fte is None else round(fte / 1000.0, 3))
    ftt = _int(props.get('ftt'))
    put('ftt', None if ftt is None else '%02d:%02d' % (ftt // 3600, (ftt % 3600) // 60))
    cco = _num(props.get('cco'))
    put('cco', None if cco is None else round(cco, 1))
    # Distance correspondant à l'énergie du prochain trajet (l'app Wattpilot fait la même conversion)
    if fte is not None and cco:
        put('trip_km', int(round(fte / 1000.0 / cco * 100)))
    temps = [t for t in (props.get('tma') or []) if _num(t) is not None] if isinstance(props.get('tma'), list) else []
    put('temperature', round(max(_num(t) for t in temps), 1) if temps else None)
    fwv = props.get('fwv')
    put('fwv', str(fwv) if fwv is not None else None)
    return out


# ---------------------------------------------------------------------------
# WebSocket minimal (RFC 6455, côté client)
# ---------------------------------------------------------------------------

def encode_frame(text, opcode=0x1):
    """Trame client (masquée) pour un texte ou un payload de contrôle."""
    payload = text.encode('utf-8') if isinstance(text, str) else bytes(text)
    head = bytes([0x80 | opcode])
    n = len(payload)
    if n < 126:
        head += bytes([0x80 | n])
    elif n < 65536:
        head += bytes([0x80 | 126]) + struct.pack('>H', n)
    else:
        head += bytes([0x80 | 127]) + struct.pack('>Q', n)
    mask = os.urandom(4)
    masked = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
    return head + mask + masked


def decode_frame(buf):
    """(opcode, payload, octets consommés) pour la première trame complète de buf, sinon None."""
    if len(buf) < 2:
        return None
    opcode = buf[0] & 0x0F
    masked = bool(buf[1] & 0x80)
    n = buf[1] & 0x7F
    pos = 2
    if n == 126:
        if len(buf) < 4:
            return None
        n = struct.unpack('>H', buf[2:4])[0]
        pos = 4
    elif n == 127:
        if len(buf) < 10:
            return None
        n = struct.unpack('>Q', buf[2:10])[0]
        pos = 10
    if masked:
        if len(buf) < pos + 4:
            return None
        mask = buf[pos:pos + 4]
        pos += 4
    if len(buf) < pos + n:
        return None
    payload = bytes(buf[pos:pos + n])
    if masked:
        payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
    return opcode, payload, pos + n


class WebSocketClient:
    """Connexion ws:// texte, bloquante avec timeout, sans dépendance externe.

    Chaque lecture de socket a un timeout, mais une boucle qui reçoit toujours des octets ne l'atteint
    jamais : l'établissement de la connexion, l'attente d'une trame complète et recv_text sont donc
    aussi bornés en durée et en taille (liaison figée sans rien journaliser sinon)."""

    MAX_HEADER = 16 * 1024          # octets : réponse HTTP d'établissement de la connexion
    MAX_FRAME = 4 * 1024 * 1024     # octets : trame (le fullStatus de la borne fait quelques dizaines de Ko)

    def __init__(self, host, path='/ws', timeout=10.0):
        # Adresse copiée depuis le navigateur (« http://192.168.2.102/ ») : schéma et chemin retirés
        host = re.sub(r'^(?:wss?|https?)://', '', (host or '').strip(), flags=re.I)
        self.host = host.split('/', 1)[0]
        self.path = path
        self.timeout = timeout
        self.sock = None
        self._buf = bytearray()
        self._frag = None           # message texte fragmenté en cours (FIN = 0), None sinon

    def connect(self):
        hostname, _, port = self.host.partition(':')
        port = int(port) if port else 80
        self.sock = socket.create_connection((hostname, port), timeout=self.timeout)
        key = base64.b64encode(os.urandom(16)).decode()
        request = ('GET %s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                   'Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n' % (self.path, self.host, key))
        self.sock.sendall(request.encode())
        response = b''
        deadline = time.monotonic() + self.timeout
        while b'\r\n\r\n' not in response:
            if len(response) > self.MAX_HEADER or time.monotonic() > deadline:
                raise ConnectionError('handshake WebSocket sans fin (%d octets reçus)' % len(response))
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError('connexion fermée pendant le handshake')
            response += chunk
        head, _, rest = response.partition(b'\r\n\r\n')
        status = head.split(b'\r\n', 1)[0].decode(errors='replace')
        if ' 101 ' not in status:
            raise ConnectionError('handshake WebSocket refusé: ' + status)
        expected = base64.b64encode(hashlib.sha1((key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
        if expected.encode() not in head:
            raise ConnectionError('Sec-WebSocket-Accept invalide')
        self._buf = bytearray(rest)
        self._frag = None

    def send_text(self, text):
        self.sock.sendall(encode_frame(text, 0x1))

    def ping(self):
        self.sock.sendall(encode_frame(b'', 0x9))

    def recv_text(self):
        """Prochain message texte, ou None sur timeout. Gère ping/pong/close en interne."""
        deadline = time.monotonic() + self.timeout
        while True:
            frame = decode_frame(self._buf)
            if frame is None:
                # Trame incomplète : longueur annoncée incohérente (flux désynchronisé) ou envoi
                # interminable. La main est rendue au bout de timeout secondes même si des octets
                # arrivent encore, pour que la liaison puisse contrôler le silence de la borne.
                if len(self._buf) > self.MAX_FRAME:
                    raise ConnectionError('trame WebSocket trop grande (%d octets en attente)' % len(self._buf))
                if time.monotonic() > deadline:
                    return None
                try:
                    chunk = self.sock.recv(65536)
                except socket.timeout:
                    return None
                if not chunk:
                    raise ConnectionError('connexion fermée par la borne')
                self._buf.extend(chunk)
                continue
            fin = bool(self._buf[0] & 0x80)
            opcode, payload, used = frame
            del self._buf[:used]
            # Messages fragmentés (RFC 6455 §5.4) : le fullStatus de la borne, volumineux, arrive en
            # plusieurs trames ; sans recollage, il était perdu et l'état voiture, le mode… restaient inconnus.
            if opcode in (0x1, 0x2):
                self._frag = bytearray(payload) if opcode == 0x1 else None
            elif opcode == 0x0:
                if self._frag is not None:
                    self._frag.extend(payload)
            if opcode in (0x0, 0x1, 0x2):
                if fin and self._frag is not None:
                    text = bytes(self._frag).decode('utf-8', errors='replace')
                    self._frag = None
                    return text
                if fin:
                    self._frag = None
                # Fragment ou trame binaire : la suite du message sera lue à l'appel suivant
                return None
            if opcode == 0x9:                       # ping → pong
                self.sock.sendall(encode_frame(payload, 0xA))
            elif opcode == 0x8:                     # close
                raise ConnectionError('fermeture demandée par la borne')
            # Trame de contrôle traitée (ping, pong) : rendre la main. Une borne qui n'envoie que des
            # pings (jamais de texte) ne doit pas bloquer la liaison : le contrôle « silence » de
            # _session n'était jamais atteint et le thread restait figé sans rien journaliser.
            return None

    def interrupt(self):
        """Débloque une lecture en cours depuis un autre thread (arrêt) : recv voit la fin de flux,
        la liaison sort de sa boucle et ferme elle-même la connexion (trame close), sans que deux
        threads n'écrivent sur le socket."""
        sock = self.sock
        if sock is not None:
            try:
                sock.shutdown(socket.SHUT_RD)
            except OSError:
                pass

    def close(self):
        try:
            if self.sock:
                try:
                    self.sock.sendall(encode_frame(b'', 0x8))
                except OSError:
                    pass
                self.sock.close()
        finally:
            self.sock = None


# ---------------------------------------------------------------------------
# Liaison par borne (thread)
# ---------------------------------------------------------------------------

class WattpilotLink(threading.Thread):
    """Maintient la connexion à une borne et expose un instantané pour Jeedom.

    ``on_update(logical_id)`` est appelé (dans le thread de la liaison) dès qu'un état
    « événementiel » change (mode, forçage, voiture, autorisation, connexion) ou qu'une réponse à
    un ordre arrive : le démon peut alors pousser la borne à Jeedom sans attendre le cycle.
    """

    RECONNECT_MIN = 5.0
    RECONNECT_MAX = 300.0
    PING_EVERY = 30.0
    SILENCE_MAX = 120.0
    # s : session connectée mais pas établie (authentifiée + état complet) dans ce délai → nouvelle
    # session. Normalement ~1 s ; le 03/10 une borne n'a jamais envoyé authRequired et diffusait ses
    # deltaStatus, la liaison n'était relancée que par le garde-fou du démon (180 s)
    ESTABLISH_TIMEOUT = 30.0
    EVENT_KEYS = ('lmo', 'frc', 'car', 'alw', 'modelStatus', 'psm', 'fup', 'amp', 'fte', 'ftt', 'cco')
    COMMAND_TTL = 60.0          # s : un ordre resté en file plus longtemps (borne hors ligne) est abandonné
    NETWORK_ERRORS_BEFORE_ERROR = 3   # échecs réseau consécutifs avant de journaliser en error

    def __init__(self, logical_id, host, password, client_factory=None, on_update=None):
        super().__init__(name='wattpilot-' + logical_id, daemon=True)
        self.logical_id = logical_id
        self.host = host
        self.password = password
        self._client_factory = client_factory or (lambda: WebSocketClient(host))
        self.on_update = on_update
        self._commands = queue.Queue()
        # Pas « _stop » : Thread._stop() est appelée par join()/is_alive() jusqu'à Python 3.12
        self._halt = threading.Event()
        self._lock = threading.Lock()
        self.protocol = None
        self.connected = False
        self.last_error = None
        self._logged_error = None       # dernière cause journalisée en error (None depuis la liaison établie)
        self._network_failures = 0
        # Surveillance par le démon (Daemon._watch_links) : étape en cours, dernière progression,
        # début de la session et types de messages reçus avant l'établissement (diagnostic)
        self.stage = 'démarrage'
        self.progress = time.monotonic()
        self.session_start = None
        self._received = {}
        self._last_car = None           # état voiture de la session précédente (diagnostic)
        self._client = None             # connexion de la session en cours (débloquée par stop())

    def _step(self, stage):
        self.stage = stage
        self.progress = time.monotonic()

    def _count_received(self, kind):
        with self._lock:
            self._received[kind] = self._received.get(kind, 0) + 1

    def stuck(self, now, limit):
        """Liaison bloquée, hors attente de reconnexion : sans progression depuis limit s (une liaison
        active repasse au moins toutes les timeout secondes dans sa boucle de lecture), ou session
        toujours pas établie limit s après son début — la boucle tourne alors et des messages arrivent,
        si bien que ni la progression ni le silence de la borne ne la trahissent (02/10)."""
        if not self.is_alive() or self._halt.is_set() or self.stage == 'attente':
            return False
        if now - self.progress > limit:
            return True
        return not self.connected and self.session_start is not None and now - self.session_start > limit

    def diagnosis(self):
        """État du dialogue de la session en cours (diagnostic d'une liaison qui ne s'établit pas)."""
        with self._lock:
            proto = self.protocol
            received = dict(self._received)
            last_car = self._last_car
        if proto is None:
            return 'aucun échange'
        hello = ('hello reçu (type %s, n° de série %s, secured %s)'
                 % (proto.device_type or '?', 'présent' if proto.serial else 'absent', proto.secured)
                 if proto.serial is not None else 'hello non reçu')
        counts = ', '.join('%s×%d' % item for item in sorted(received.items())) or 'aucun'
        # Borne au repos ou non (hypothèse d'une veille sans voiture, 03/10) : les deltaStatus reçus
        # avant l'authentification ne contiennent « car » que s'il change, d'où le dernier état connu
        car = _int(proto.props.get('car'))
        if car is not None:
            car_text = CAR_LABELS.get(car, str(car))
        elif last_car is not None:
            car_text = '%s (dernier état connu)' % CAR_LABELS.get(last_car, str(last_car))
        else:
            car_text = '?'
        return ('%s, hash %s, authentifiée : %s, état complet : %s, voiture : %s, '
                'messages avant établissement : %s' % (
                    hello, proto.hash_type or '?', 'oui' if proto.authenticated else 'non',
                    'oui' if proto.ready else 'non', car_text, counts))

    def stack(self):
        """Pile d'appels actuelle du thread (diagnostic d'un blocage)."""
        frame = sys._current_frames().get(self.ident)
        return ''.join(traceback.format_stack(frame)) if frame is not None else '(thread terminé)'

    def _notify(self):
        # Après stop(), le démon a déjà envoyé l'état final (déconnectée) : plus de poussée
        if callable(self.on_update) and not self._halt.is_set():
            try:
                self.on_update(self.logical_id)
            except Exception as exc:
                logging.warning('[wattpilot %s] notification échouée: %s', self.logical_id, exc)

    # --- API démon --------------------------------------------------------------

    def send_command(self, cmd, value=None):
        """Ordre Jeedom : mode_prev, mode_next, mode_set, force_on, force_off, force_neutral,
        amp_set, trip_energy_set, trip_km_set, trip_time_set, cco_set (voir :meth:`_translate`).

        Horodaté : s'il n'a pas pu partir dans les COMMAND_TTL secondes (borne hors ligne), il est
        abandonné au lieu d'être rejoué des heures plus tard."""
        if not self.connected:
            logging.info('[wattpilot %s] %s mis en attente : borne hors ligne', self.logical_id, cmd)
        self._commands.put((time.monotonic(), cmd, value))

    def snapshot(self):
        with self._lock:
            props = dict(self.protocol.props) if self.protocol else {}
            connected = self.connected
        return charger_output(props, connected)

    def stop(self):
        self._halt.set()
        # Déconnectée tout de suite : le lot final de l'arrêt (snapshot) ne dépend pas du thread,
        # qui peut rester bloqué dans recv jusqu'au timeout du socket
        with self._lock:
            self.connected = False
            client = self._client
        # Lecture débloquée : le thread ferme proprement la connexion (trame close) avant la fin du
        # processus. Coupée net, la session restait ouverte côté borne, qui ne redemandait pas
        # l'authentification à la reconnexion suivante (03/10)
        interrupt = getattr(client, 'interrupt', None)
        if callable(interrupt):
            interrupt()

    # --- boucle -----------------------------------------------------------------

    def run(self):
        delay = self.RECONNECT_MIN
        while not self._halt.is_set():
            error = None
            try:
                self._session()
            except Exception as exc:
                error = exc
                self.last_error = str(exc)
            with self._lock:
                was_connected = self.connected
                self.connected = False
            if was_connected:
                delay = self.RECONNECT_MIN   # liaison perdue après avoir fonctionné : on repart du délai minimal
                self._notify()          # « Connexion borne » à faux sans attendre le cycle
            if error is not None and not self._halt.is_set():     # arrêt : lecture interrompue exprès
                logging.log(self._error_level(error), '[wattpilot %s] %s — nouvelle tentative dans %.0fs',
                            self.logical_id, error, delay)
            self._step('attente')
            if self._halt.wait(delay):
                break
            delay = min(delay * 2, self.RECONNECT_MAX)

    def _error_level(self, error):
        """error (visible au niveau de log par défaut) une fois par cause : mot de passe refusé ou
        configuration à chaque nouveau message, panne réseau après quelques échecs consécutifs ;
        warning pour les répétitions. Remis à zéro quand la liaison s'établit."""
        msg = str(error)
        if msg.startswith('authentification refusée') or isinstance(error, (RuntimeError, ValueError)):
            key = msg
        else:
            self._network_failures += 1
            if self._network_failures < self.NETWORK_ERRORS_BEFORE_ERROR:
                return logging.WARNING
            key = 'réseau'
        if key == self._logged_error:
            return logging.WARNING
        self._logged_error = key
        return logging.ERROR

    def _session(self):
        logging.debug('[wattpilot %s] connexion à %s', self.logical_id, self.host)
        self.session_start = time.monotonic()
        with self._lock:
            if self.protocol is not None and _int(self.protocol.props.get('car')) is not None:
                self._last_car = _int(self.protocol.props.get('car'))
            self.protocol = None        # diagnostic de la session en cours, pas de la précédente
            self._received = {}
        self._step('connexion')
        client = self._client_factory()
        client.connect()
        self._step('protocole')
        proto = WattpilotProtocol(self.password)
        with self._lock:
            self.protocol = proto
            self._client = client       # stop() arrivé avant : la boucle ne démarre pas (halt)
        last_rx = time.time()
        last_ping = time.time()
        established_by = time.time() + self.ESTABLISH_TIMEOUT

        def established():
            # État du dialogue, et non self.connected que stop() remet à faux pendant que la boucle
            # lit encore (faux « liaison non établie » à chaque arrêt du démon, 03/10)
            return proto.authenticated and proto.ready

        try:
            while not self._halt.is_set():
                self._step('lecture' if self.connected else 'authentification')
                text = client.recv_text()
                now = time.time()
                if text is not None:
                    last_rx = now
                    try:
                        msg = json.loads(text)
                    except ValueError:
                        logging.debug('[wattpilot %s] message illisible ignoré (%d caractères) : %.80s',
                                      self.logical_id, len(text), text)
                        if not established():
                            self._count_received('illisible')
                        continue
                    if not established():
                        kind = str(msg.get('type')) if isinstance(msg, dict) else type(msg).__name__
                        logging.debug('[wattpilot %s] reçu « %s » avant liaison établie', self.logical_id, kind)
                        self._count_received(kind)
                    before = tuple(proto.props.get(k) for k in self.EVENT_KEYS)
                    was_connected = self.connected
                    for reply in proto.handle(msg):
                        client.send_text(json.dumps(reply))
                    if proto.auth_error:
                        raise ConnectionError('authentification refusée: ' + proto.auth_error)
                    if proto.authenticated and proto.ready:
                        with self._lock:
                            if not self._halt.is_set():     # jamais « reconnectée » après stop()
                                self.connected = True
                        if not was_connected and self.connected:
                            logging.info('[wattpilot %s] liaison établie (%s)', self.logical_id, self.host)
                            self._logged_error = None
                            self._network_failures = 0
                    after = tuple(proto.props.get(k) for k in self.EVENT_KEYS)
                    # Surveillance (2026-10-13) : chaque changement d'état voiture avec le diagnostic de
                    # la borne, pour expliquer les « absente » inattendus (câble, voiture, borne)
                    car_index = self.EVENT_KEYS.index('car')
                    if proto.ready and _int(after[car_index]) != _int(before[car_index]):
                        logging.info(car_transition_message(self.logical_id, before[car_index], after[car_index], proto.props))
                    if msg.get('type') == 'response' or after != before or self.connected != was_connected:
                        self._notify()
                if now - last_rx > self.SILENCE_MAX:
                    raise ConnectionError('silence de la borne depuis %.0fs' % (now - last_rx))
                if not established() and now > established_by:
                    raise ConnectionError('liaison non établie après %.0fs (%s)'
                                          % (self.ESTABLISH_TIMEOUT, self.diagnosis()))
                if now - last_ping > self.PING_EVERY:
                    client.ping()
                    last_ping = now
                # Après le fullStatus seulement : avant, props est vide et un ordre relatif partirait de rien
                if proto.authenticated and proto.ready:
                    self._flush_commands(client, proto)
        finally:
            with self._lock:
                self._client = None
            client.close()

    def _flush_commands(self, client, proto):
        while True:
            try:
                stamp, cmd, value = self._commands.get_nowait()
            except queue.Empty:
                return
            age = time.monotonic() - stamp
            if age > self.COMMAND_TTL:
                logging.warning('[wattpilot %s] ordre %s périmé (%.0fs, borne hors ligne) ignoré',
                                self.logical_id, cmd, age)
                continue
            pairs = self._translate(cmd, value, proto.props)
            if pairs is None:
                logging.warning('[wattpilot %s] ordre inconnu: %s', self.logical_id, cmd)
                continue
            if not pairs:
                if cmd in ('mode_next', 'mode_prev'):
                    logging.warning('[wattpilot %s] mode actuel inconnu, ordre relatif %s ignoré', self.logical_id, cmd)
                elif cmd == 'mode_set':
                    logging.warning('[wattpilot %s] valeur de mode invalide: %r (attendu 3, 4, 5 ou un libellé)',
                                    self.logical_id, value)
                elif cmd == 'trip_km_set' and not _num(proto.props.get('cco')):
                    logging.warning('[wattpilot %s] distance %r ignorée : consommation du véhicule inconnue '
                                    '(réglez-la dans l\'app ou avec « Régler la consommation »)', self.logical_id, value)
                elif cmd == 'trip_time_set':
                    logging.warning('[wattpilot %s] heure invalide: %r (attendu HH:MM)', self.logical_id, value)
                else:
                    logging.warning('[wattpilot %s] valeur invalide pour %s: %r', self.logical_id, cmd, value)
                continue
            for key, new_value in pairs:
                logging.info('[wattpilot %s] %s → %s = %s', self.logical_id, cmd, key, new_value)
                client.send_text(json.dumps(proto.set_value(key, new_value)))
                # Ordres enchaînés : le suivant part de la valeur attendue, sans attendre la réponse
                with self._lock:
                    proto.assume(key, new_value)

    @staticmethod
    def _translate(cmd, value, props):
        """Écritures Wattpilot [(clé, valeur), …] pour un ordre Jeedom : [] si la valeur est refusée,
        None si l'ordre est inconnu. Les réglages sont bornés ici, pas dans Jeedom."""
        if cmd in ('mode_next', 'mode_prev'):
            mode = cycle_mode(props.get('lmo'), +1 if cmd == 'mode_next' else -1)
            return [('lmo', mode)] if mode is not None else []
        if cmd == 'mode_set':
            mode = parse_mode(value)
            return [('lmo', mode)] if mode is not None else []
        if cmd == 'force_on':
            return [('frc', 2)]
        if cmd == 'force_off':
            return [('frc', 1)]
        if cmd == 'force_neutral':
            return [('frc', 0)]
        if cmd == 'amp_set':
            amp = _int(value)
            if amp is None:
                return []
            ama = _int(props.get('ama')) or AMP_MAX_DEFAULT
            return [('amp', max(AMP_MIN, min(ama, amp)))]
        if cmd == 'trip_energy_set':
            kwh = _num(value)
            # esk : unité choisie dans l'app (kWh ou km), affichage seulement ; fte en Wh
            return [('esk', True), ('fte', int(round(kwh * 1000)))] if kwh and kwh > 0 else []
        if cmd == 'trip_km_set':
            km, cco = _num(value), _num(props.get('cco'))
            if not km or km <= 0 or not cco or cco <= 0:
                return []
            return [('esk', False), ('fte', int(round(km * cco * 10)))]   # km × kWh/100 km → Wh
        if cmd == 'trip_time_set':
            seconds = parse_time_of_day(value)
            return [('ftt', seconds)] if seconds is not None else []
        if cmd == 'cco_set':
            cco = _num(value)
            return [('cco', float(cco))] if cco and cco > 0 else []
        return None
