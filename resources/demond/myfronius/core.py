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
Cœur du démon, indépendant de la lib Jeedom et de l'horloge : découverte au démarrage (retentée
tant qu'elle échoue), cycle de lecture, calcul, filtrage, envoi, persistance, backoff.

``demond.py`` ne fait qu'instancier :class:`Daemon` avec le vrai client, le vrai envoi
(l'envoi ordonné ``OrderedSender``, cf. sender.py) et boucler sur :meth:`Daemon.run_cycle`.
"""

import datetime
import logging
import os
import threading
import time

from .calculator import SiteCalculator, normalize
from .deadband import Deadband
from .discovery import discover
from .state import load_state, save_state

BACKOFF_AFTER_FAILURES = 3   # nombre d'échecs consécutifs avant d'allonger le cycle
BACKOFF_MAX = 60.0           # secondes (≥ MAX_CYCLE : jamais plus court que la cadence)
# Fin du secours sans Smart Meter connu (découverte faite pendant la coupure) : redécouverte
# toutes les METER_RETRY s pendant METER_WATCH s, le compteur pouvant revenir après l'onduleur
METER_RETRY = 60.0
METER_WATCH = 600.0

# Bornes de la cadence (s), identiques à myfronius::MIN_CYCLE / MAX_CYCLE côté PHP. Le maximum
# garde deux cycles sous MAX_GAP (intégration des énergies) et sous REVERT_SECONDS (retour
# automatique de la consigne batterie).
MIN_CYCLE = 5.0
MAX_CYCLE = 60.0

# Marqueur déposé par Jeedom (à côté de state.json) quand un ordre de mode batterie n'a pas pu être
# transmis au démon : la consigne persistée est alors périmée, le démon revient en automatique
BATTERY_RESET_FILE = 'battery_reset'

# Copie persistante de l'état journalier (hors tmpfs, survit au redémarrage de la box) : écrite au
# plus toutes les PERSIST_INTERVAL secondes (usure de la mémoire flash), au changement de jour, à
# chaque consigne batterie et à l'arrêt. Au pire, un redémarrage de la box perd ce délai d'intégration.
# La consigne batterie n'en est jamais reprise, mais son mode y est lu au redémarrage de la box pour
# remettre à zéro le StorCtl_Mod laissé par le plugin : d'où l'écriture forcée à chaque consigne.
PERSIST_INTERVAL = 300.0

# Liaison de borne sans progression, ou session non établie, au-delà de ce délai (s) : jugée bloquée
# (Daemon._watch_links).
# Supérieur au silence toléré (120 s) plus un timeout de lecture, pour ne jamais doubler la
# reconnexion normale ; l'attente entre deux tentatives n'est pas comptée.
LINK_STUCK_AFTER = 180.0
# Attente globale (s), à l'arrêt, que les liaisons ferment leur connexion (trame close) avant la fin
# du processus : deamon_stop envoie SIGKILL 3 s après SIGTERM, et le flush des derniers lots dure
# jusqu'à 2 s.
LINK_CLOSE_TIMEOUT = 1.0


def bound_cycle(value):
    """Cadence ramenée dans [MIN_CYCLE, MAX_CYCLE]."""
    return min(max(float(value), MIN_CYCLE), MAX_CYCLE)


class Daemon:
    """Une instance = un site. ``send(payload)`` est appelé par le cycle, et aussi hors cycle
    (liaisons des bornes, ``battery_command``, arrêt), toujours sous ``_send_lock``."""

    def __init__(self, client, send, cycle, statefile, capacity_wh=None, today_fn=None,
                 chargers=None, link_factory=None, modbus_client=None, persistfile=None):
        self.client = client
        self.send = send
        self.cycle = float(cycle)
        self.statefile = statefile
        self.persistfile = persistfile or None
        self._persist_at = None      # horodatage et jour de la dernière copie persistante
        self._persist_day = None
        self.capacity_wh = capacity_wh
        self.today_fn = today_fn or datetime.date.today
        self.discovery = None
        self.calculator = None
        self.deadband = Deadband()
        self.failures = 0
        self.comm_ok = None          # None = jamais signalé, sinon bool
        self._resync = False
        self._storage_warned = False
        self._backup_active = False
        self._meter_watch_until = None   # échéance de la recherche du Smart Meter après un secours
        self._meter_retry_at = 0.0
        # Pilotage batterie par Modbus (spec §12) : consigne persistée avec l'état journalier, mais
        # reprise UNIQUEMENT depuis le dossier temporaire (jamais la copie persistante). Il est vidé
        # avec le marqueur battery_reset au redémarrage de la box : la batterie revient alors en
        # automatique, plutôt que de rétablir une consigne ancienne ou un ordre « auto » perdu.
        self.battery_control = None
        if modbus_client is not None:
            from .modbus import BatteryControl
            saved = load_state(self.statefile) or {}
            self.battery_control = BatteryControl(modbus_client, state=saved.get('battery_control'))
            if saved.get('battery_control') is None and self.persistfile:
                # Box redémarrée pendant un mode ≠ auto (ou un retour en auto non écrit) : consigne
                # non reprise, mais le StorCtl_Mod laissé par le plugin est remis à zéro une fois
                # (sinon pris pour celui d'un autre maître Modbus et jamais nettoyé)
                persisted = (load_state(self.persistfile) or {}).get('battery_control')
                if isinstance(persisted, dict) and (persisted.get('mode', 'auto') != 'auto'
                                                    or persisted.get('needs_reset')):
                    self.battery_control._needs_reset = True
            self._battery_reset_file = os.path.join(os.path.dirname(os.path.abspath(statefile)), BATTERY_RESET_FILE)
            logging.info('[battery] pilotage Modbus actif (mode restauré : %s)', self.battery_control.mode)
        # Le deadband et l'envoi sont partagés avec les threads des bornes
        self._send_lock = threading.Lock()
        # Bornes Wattpilot : une liaison (thread) par borne, démarrée tout de suite
        self.links = {}
        factory = self._link_factory = link_factory or self._default_link_factory
        for charger in chargers or []:
            logical_id = str(charger.get('logical_id') or '')
            host = str(charger.get('host') or '')
            if not logical_id or not host:
                logging.warning('[charger] entrée ignorée (logical_id ou host manquant): %s', charger)
                continue
            link = factory(logical_id, host, str(charger.get('password') or ''))
            self.links[logical_id] = link
            link.start()
            logging.info('[charger] liaison démarrée pour %s (%s)', logical_id, host)

    def _default_link_factory(self, logical_id, host, password):
        from .wattpilot import WattpilotLink
        return WattpilotLink(logical_id, host, password, on_update=self.push_charger)

    def push_charger(self, logical_id, now=None):
        """Pousse immédiatement l'état d'une borne (appelé par sa liaison sur événement)."""
        link = self.links.get(logical_id)
        if link is None:
            return
        with self._send_lock:
            payload = self.deadband.filter({logical_id: link.snapshot()}, time.time() if now is None else now)
            if payload:
                self.send(payload)

    def _charger_power(self):
        """Somme des puissances de charge des bornes connectées (W)."""
        total = 0.0
        for link in self.links.values():
            snap = link.snapshot()
            if snap.get('connected') and snap.get('p') is not None:
                total += float(snap['p'])
        return total

    # ------------------------------------------------------------------

    def request_resync(self):
        """Demande (depuis le socket) une nouvelle découverte au prochain cycle."""
        self._resync = True

    def charger_command(self, logical_id, cmd, value=None):
        """Route un ordre Jeedom vers la borne. False si la borne est inconnue."""
        link = self.links.get(logical_id)
        if link is None:
            logging.warning('[charger] ordre pour une borne inconnue: %s', logical_id)
            return False
        link.send_command(cmd, value)
        return True

    def battery_command(self, cmd, value=None):
        """Consigne batterie depuis Jeedom ; appliquée au prochain cycle (ou tout de suite)."""
        if self.battery_control is None:
            logging.warning('[battery] ordre %s ignoré : pilotage Modbus désactivé', cmd)
            return False
        ok = self.battery_control.request(cmd, value, backup_active=self._backup_active)
        if ok:
            logging.info('[battery] consigne %s%s', cmd, '' if value is None else ' = %s' % value)
            # Application immédiate pour ne pas attendre le cycle, puis remontée à Jeedom
            out = self.battery_control.apply(backup_active=self._backup_active)
            with self._send_lock:
                payload = self.deadband.filter({'battery': out}, time.time())
                if payload:
                    self.send(payload)
            self._save_state(force_persist=True)
        return ok

    def _check_battery_reset(self):
        """Ordre de mode perdu côté Jeedom (marqueur) : retour en automatique, marqueur consommé."""
        if self.battery_control is None or not os.path.exists(self._battery_reset_file):
            return
        try:
            os.remove(self._battery_reset_file)
        except OSError as exc:
            logging.warning('[battery] marqueur %s non supprimé: %s', self._battery_reset_file, exc)
        logging.warning('[battery] ordre de pilotage non transmis par Jeedom (démon injoignable) : '
                        'consigne %s abandonnée, retour en gestion automatique', self.battery_control.mode)
        self.battery_control.request('auto')
        self._save_state(force_persist=True)

    def _load_state(self):
        """État du dossier temporaire (réécrit à chaque cycle) ; à défaut (tmpfs vidé par un
        redémarrage de la box), la copie persistante. None si aucun des deux n'est lisible."""
        state = load_state(self.statefile)
        if state is None and self.persistfile:
            state = load_state(self.persistfile)
            if state is not None:
                logging.info('[state] état restauré depuis la copie persistante %s', self.persistfile)
        return state

    def _save_state(self, now=None, force_persist=False):
        if self.calculator is None:
            # Avant la première découverte : seule la consigne batterie change, le reste de l'état
            # sur disque (énergies du jour) est conservé
            if self.battery_control is None:
                return
            state = self._load_state() or {}
            state['battery_control'] = self.battery_control.export_state()
        else:
            state = self.calculator.export_state()
            if self.battery_control is not None:
                state['battery_control'] = self.battery_control.export_state()
        try:
            save_state(self.statefile, state)
        except OSError as exc:
            logging.warning('[state] sauvegarde impossible: %s', exc)
        self._save_persistent(state, time.time() if now is None else now, force_persist)

    def _save_persistent(self, state, now, force=False):
        """Copie persistante, écrite rarement (voir PERSIST_INTERVAL)."""
        if not self.persistfile:
            return
        if (not force and self._persist_at is not None and 0 <= now - self._persist_at < PERSIST_INTERVAL
                and state.get('day') == self._persist_day):
            return
        try:
            save_state(self.persistfile, state)
        except OSError as exc:
            logging.warning('[state] copie persistante impossible: %s', exc)
            return
        self._persist_at = now
        self._persist_day = state.get('day')

    def stop(self):
        """Arrête les liaisons des bornes et envoie leur dernier état (« Connexion borne » à 0,
        mesures à zéro) hors filtre : les threads des liaisons, bloqués dans recv, ne le feraient
        pas avant la fin du processus. La copie persistante de l'état est mise à jour. Les liaisons
        ont ensuite LINK_CLOSE_TIMEOUT s pour fermer proprement leur connexion."""
        try:
            self._save_state(force_persist=True)
        except Exception as exc:
            logging.warning("[state] sauvegarde à l'arrêt impossible: %s", exc)
        for link in self.links.values():
            link.stop()
        if self.links:
            from .wattpilot import charger_output
            with self._send_lock:
                self.send({logical_id: charger_output({}, False) for logical_id in self.links})
        deadline = time.monotonic() + LINK_CLOSE_TIMEOUT
        for link in self.links.values():
            try:
                link.join(max(0.0, deadline - time.monotonic()))
            except Exception:           # liaison jamais démarrée
                pass

    def on_send_failure(self):
        """Lot non livré à Jeedom (appelé par le thread d'envoi) : le filtre de variation oublie ce
        qu'il croyait envoyé, le lot suivant repart complet. Pas d'interblocage : les détenteurs du
        verrou ne font qu'une mise en file non bloquante."""
        with self._send_lock:
            self.deadband.reset()

    def _watch_links(self):
        """Liaison de borne bloquée (sans progression, ou session non établie, depuis LINK_STUCK_AFTER s,
        hors attente de reconnexion) : état du dialogue et pile du thread journalisés pour le
        diagnostic, puis nouvelle liaison. Un thread Python ne peut pas être tué : l'ancien, arrêté,
        se termine s'il se débloque un jour."""
        for logical_id, link in list(self.links.items()):
            stuck = getattr(link, 'stuck', None)
            if not callable(stuck) or not stuck(time.monotonic(), LINK_STUCK_AFTER):
                continue
            diagnosis = getattr(link, 'diagnosis', None)
            logging.error('[charger] liaison %s bloquée à l\'étape « %s » (sans progression ou non établie '
                          'depuis plus de %.0fs) : nouvelle liaison. État : %s. Pile du thread :\n%s',
                          logical_id, link.stage, LINK_STUCK_AFTER,
                          diagnosis() if callable(diagnosis) else '?', link.stack())
            link.stop()
            fresh = self._link_factory(logical_id, link.host, link.password)
            self.links[logical_id] = fresh
            fresh.start()

    def run_cycle(self, now):
        """Exécute un cycle complet. Renvoie le délai (s) avant le prochain cycle."""
        self._watch_links()
        self._check_battery_reset()
        self._watch_meter_return(now)
        try:
            if self.discovery is None or self._resync:
                self._do_discovery()
            self._do_read(now)
            self.failures = 0
            self._set_comm(True, now)
            return self.cycle
        except Exception as exc:  # toute erreur = cycle en échec, le démon continue
            self.failures += 1
            logging.error('[cycle] échec %d: %s', self.failures, exc)
            logging.debug('[cycle] détail', exc_info=True)
            self._set_comm(False, now)
            # Perte de communication signalée à chaque cycle en échec (hors filtre) : Jeedom relance
            # ainsi la décision des charges pilotées, qui ne doivent pas rester sur des flux figés.
            # Les bornes sont indépendantes de l'onduleur : on les remonte quand même.
            with self._send_lock:
                payload = {'site': {'comm_ok': 0}}
                payload.update(self.deadband.filter(self._charger_outputs(), now))
                if self.battery_control is not None:
                    # Consigne plus entretenue (apply() suit la lecture Solar API) : l'onduleur
                    # revient seul en automatique après RvrtTms. Mémorisé comme envoyé pour que
                    # la vraie valeur reparte au retour, même après un ordre pendant la panne.
                    battery = {'ctrl_active': 0}
                    payload['battery'] = battery
                    self.deadband.mark({'battery': battery})
                self.send(payload)
            return self._backoff_delay()

    def _charger_outputs(self):
        return {logical_id: link.snapshot() for logical_id, link in self.links.items()}

    # ------------------------------------------------------------------

    def _do_discovery(self):
        discovery = discover(self.client)
        # Identité exacte de l'onduleur par Modbus (modèle SunSpec 1) quand le pilotage est activé
        if self.battery_control is not None and discovery.get('inverters'):
            try:
                from .modbus import read_common_info
                common = read_common_info(self.battery_control.client)
                if common:
                    inv = discovery['inverters'][0]
                    for key in ('manufacturer', 'model', 'serial'):
                        if common.get(key):
                            inv[key] = common[key]
                    if common.get('version'):
                        inv['firmware'] = common['version']
                    # Marqueur : la découverte PHP (démon arrêté) conserve cette identité
                    inv['identity_source'] = 'modbus'
                    logging.info('[discovery] identité Modbus : %s %s (%s)', common.get('manufacturer'), common.get('model'), common.get('serial'))
            except Exception as exc:
                logging.warning('[discovery] identité Modbus indisponible : %s', exc)
        self.discovery = discovery
        self._resync = False
        battery = discovery.get('battery') or {}
        capacity = battery.get('capacity_max') or battery.get('capacity_designed') or self.capacity_wh
        if self.calculator is not None:
            # Resync (« Rafraîchir », Synchroniser) : calculateur conservé, fenêtres de tendance et
            # window_complete compris ; seuls capacité et meter primaire sont mis à jour
            self.calculator.reconfigure(capacity_wh=capacity, primary_meter=discovery.get('primary_meter'))
        else:
            previous_state = self._load_state()
            if isinstance(previous_state, dict):
                previous_state = {k: v for k, v in previous_state.items() if k != 'battery_control'}
            self.calculator = SiteCalculator(capacity_wh=capacity,
                                             primary_meter=discovery.get('primary_meter'),
                                             state=previous_state)
        self.deadband.reset()
        self._storage_warned = False
        self.send({'_discovery': discovery})
        logging.info('[discovery] envoyée à Jeedom')

    def _do_read(self, now):
        d = self.discovery
        power_flow = self.client.power_flow()

        inverters = {}
        for inv in d.get('inverters') or []:
            device_id = inv['device_id']
            try:
                inverters[device_id] = self.client.inverter(device_id)
            except Exception as exc:
                logging.warning('[cycle] onduleur %s non lu: %s', device_id, exc)

        meters = {}
        if d.get('meters'):
            try:
                meters = self.client.meters()
            except Exception as exc:
                logging.warning('[cycle] compteurs non lus: %s', exc)

        storages = None
        if (d.get('battery') or {}).get('storage_ok'):
            try:
                storages = self.client.storages()
                self._storage_warned = False
            except Exception as exc:
                if not self._storage_warned:
                    logging.warning('[cycle] stockage non lu (les détails batterie sont figés): %s', exc)
                    self._storage_warned = True

        sample = normalize(power_flow, inverters, meters, storages)
        output = self.calculator.update(sample, now, self.today_fn(), ev_power=self._charger_power())
        output.setdefault('site', {})['comm_ok'] = 1
        was_backup, self._backup_active = self._backup_active, bool(output['site'].get('backup_mode', 0))
        if was_backup and not self._backup_active and not d.get('meters'):
            logging.info('[backup] fin du secours : recherche du Smart Meter')
            self._meter_watch_until = now + METER_WATCH
            self._meter_retry_at = now
        elif self._backup_active and self._meter_watch_until is not None:
            # Fausse fin (onduleur qui redémarre : 12-14 s hors secours) : recherche abandonnée,
            # relancée à la vraie fin du secours
            logging.info('[backup] retour en secours : recherche du Smart Meter suspendue')
            self._meter_watch_until = None
        if self.battery_control is not None:
            output.setdefault('battery', {}).update(self.battery_control.apply(backup_active=self._backup_active))
        with self._send_lock:
            # Instantané des bornes pris sous le verrou, après les E/S Modbus : une poussée plus
            # récente de la liaison (reconnexion) n'est pas écrasée par un état périmé
            output.update(self._charger_outputs())
            payload = self.deadband.filter(output, now)
            if payload:
                self.send(payload)
        self._save_state(now)

    def _watch_meter_return(self, now):
        """Après un secours, redécouvre jusqu'au retour du Smart Meter (ou abandon à l'échéance)."""
        if self._meter_watch_until is None:
            return
        if (self.discovery or {}).get('meters'):
            logging.info('[backup] Smart Meter retrouvé')
            self._meter_watch_until = None
        elif now >= self._meter_watch_until:
            logging.info('[backup] aucun Smart Meter %.0f min après le secours : synchroniser à son retour',
                         METER_WATCH / 60)
            self._meter_watch_until = None
        elif now >= self._meter_retry_at:
            self._meter_retry_at = now + METER_RETRY
            self._resync = True

    def _set_comm(self, ok, now):
        if self.comm_ok == ok:
            return
        self.comm_ok = ok
        if not ok:
            # Le lot nominal n'est pas parti (comm_ok = 0 envoyé par run_cycle à chaque échec)
            self.deadband.reset()   # au retour, tout sera renvoyé

    def _backoff_delay(self):
        if self.failures < BACKOFF_AFTER_FAILURES:
            return self.cycle
        factor = 2 ** (self.failures - BACKOFF_AFTER_FAILURES + 1)
        return min(self.cycle * factor, BACKOFF_MAX)
