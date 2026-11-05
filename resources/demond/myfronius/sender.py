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
Envoi ordonné des lots vers Jeedom.

``jeedom_com.send_change_immediate`` lance un thread par lot : deux lots émis à quelques
millisecondes d'écart (cycle du site et poussée d'une borne) partent en parallèle et Jeedom peut
appliquer le plus ancien en dernier (« Connexion borne » revenue à 0 juste après la connexion).
Comme le filtre de variation croit la valeur livrée, l'erreur restait affichée jusqu'au renvoi
complet suivant. Ici, un seul thread poste les lots un par un, dans l'ordre d'émission.

Si Jeedom est lent (sauvegarde, base saturée), les lots accumulés pendant un envoi sont fusionnés
en un seul (la valeur la plus récente de chaque commande l'emporte) au lieu d'être rejoués en
rafale avec des valeurs de plusieurs minutes. Un envoi en échec est signalé par ``on_failure`` : le
démon oublie alors ce qu'il croyait livré et le cycle suivant renvoie tout.

L'envoi lui-même (``make_poster``) ne passe plus par ``jeedom_com`` : le template met la clé API
dans l'URL et journalise le texte des exceptions requests, qui contient cette URL (« Max retries
exceeded with url: …?apikey=<clé> ») dès que Jeedom est injoignable.
"""

import logging
import queue
import threading
import time

import requests


def make_poster(url, apikey, retry=3, pause=0.5):
    """Fonction d'envoi d'un lot au callback Jeedom : ``retry`` tentatives espacées de ``pause`` s,
    comme le template. Aucun message de log ne contient la clé. Rend True si Jeedom a répondu 200."""
    def _mask(text):
        return text.replace(apikey, '***') if apikey else text

    def post(change):
        logging.debug('Send to jeedom: %s', change)
        for i in range(retry):
            try:
                r = requests.post(url, params={'apikey': apikey}, json=change,
                                  timeout=(0.5, 120), verify=False)
                if r.status_code == requests.codes.ok:
                    return True
                logging.warning('[sender] Jeedom a répondu %s (tentative %i/%i)', r.status_code, i + 1, retry)
            except Exception as exc:
                logging.error('[sender] envoi à Jeedom échoué (tentative %i/%i): %s', i + 1, retry, _mask(str(exc)))
            if i + 1 < retry:
                time.sleep(pause)
        return False

    return post


MERGE_WARNING = 5   # lots fusionnés d'un coup au-delà desquels on signale un Jeedom lent


def _mergeable(lot):
    return isinstance(lot, dict) and all(isinstance(v, dict) for v in lot.values())


def _copy(lot):
    """Copie sur deux niveaux : la fusion ne modifie jamais un dict reçu par ``send``."""
    if not _mergeable(lot):
        return lot
    return {k: (v if k.startswith('_') else dict(v)) for k, v in lot.items()}


class OrderedSender:
    """File d'envoi : ``send(lot)`` rend la main tout de suite, un thread unique poste dans l'ordre.

    ``on_failure()`` est appelé (depuis le thread d'envoi) quand un lot n'a pas été livré : ``post``
    a rendu False ou levé une exception."""

    def __init__(self, post, on_failure=None):
        self._post = post
        self.on_failure = on_failure
        self._queue = queue.Queue()
        self._thread = threading.Thread(target=self._run, name='jeedom-sender', daemon=True)
        self._thread.start()

    def send(self, change):
        self._queue.put(change)

    def flush(self, timeout=2.0):
        """Attend (au plus ``timeout`` s) que les lots en file soient postés — utile à l'arrêt."""
        done = threading.Event()
        self._queue.put(done)
        return done.wait(timeout)

    def _run(self):
        while True:
            item = self._queue.get()
            if isinstance(item, threading.Event):
                item.set()
                continue
            events = []
            for lot in self._drain(item, events):
                try:
                    ok = self._post(lot)
                except Exception as exc:  # un lot en échec ne doit pas arrêter les suivants
                    logging.error('[sender] envoi à Jeedom échoué: %s', exc)
                    ok = False
                if ok is False:
                    self._failed()
            # flush() posés pendant la fusion : libérés une fois les lots fusionnés postés
            for event in events:
                event.set()

    def _drain(self, item, events):
        """Vide la file sans attendre et fusionne les lots consécutifs de la forme {équipement: {…}} :
        la dernière valeur de chaque commande l'emporte, une clé technique (``_discovery``) est
        remplacée en bloc. Rend les lots à poster, dans l'ordre ; les ``Event`` de flush retirés
        sont rendus dans ``events``."""
        lots = [_copy(item)]
        count = 1
        while True:
            try:
                nxt = self._queue.get_nowait()
            except queue.Empty:
                break
            if isinstance(nxt, threading.Event):
                events.append(nxt)
                continue
            count += 1
            last = lots[-1]
            if _mergeable(last) and _mergeable(nxt):
                for key, value in nxt.items():
                    if key.startswith('_'):
                        last[key] = value
                    elif key in last:
                        last[key].update(value)
                    else:
                        last[key] = dict(value)
            else:
                lots.append(_copy(nxt))
        if count >= MERGE_WARNING:
            logging.warning('[sender] Jeedom lent : %d lots en attente fusionnés en %d envoi(s)', count, len(lots))
        elif count > len(lots):
            logging.debug('[sender] %d lots en attente fusionnés en %d envoi(s)', count, len(lots))
        return lots

    def _failed(self):
        if self.on_failure is None:
            return
        try:
            self.on_failure()
        except Exception as exc:
            logging.error("[sender] traitement de l'échec d'envoi en erreur: %s", exc)
