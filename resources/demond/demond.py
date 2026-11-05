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
Démon MyFronius.

Interroge la Fronius Solar API V1 toutes les ``--cycle`` secondes, calcule surplus, tendances,
énergies du jour et estimations batterie (module ``myfronius``), et pousse les valeurs vers
Jeedom par callback HTTP. Un socket TCP local reçoit l'ordre « resync » (nouvelle découverte de
la structure, envoyée à Jeedom pour recréer équipements et commandes).

Toute la logique est dans ``myfronius/core.py`` (testée hors Jeedom) ; ce fichier ne fait que le
câblage avec la lib template Jeedom (``jeedom/jeedom.py``).
"""

import argparse
import json
import logging
import os
import signal
import sys
import time
import traceback

try:
    from jeedom.jeedom import jeedom_utils, jeedom_socket, JEEDOM_SOCKET_MESSAGE
except ImportError as exc:
    # La lib template importe requests/pyserial/pyudev : paquets apt déclarés dans
    # plugin_info/packages.json (python3-requests, python3-serial, python3-pyudev).
    print('Error: importing module jeedom.jeedom: ' + repr(exc), flush=True)
    sys.exit(1)

from myfronius import logfilter
from myfronius.core import Daemon, bound_cycle
from myfronius.sender import OrderedSender, make_poster
from myfronius.solar_api import SolarApiClient

_IDLE_SLEEP = 0.2   # secondes entre deux vérifications du socket quand rien n'est à faire

_apikey = ''
_pidfile = '/tmp/myfronius_demond.pid'


# ---------------------------------------------------------------------------
# Socket : ordres Jeedom → démon
# ---------------------------------------------------------------------------

def read_socket(daemon):
    """Traite les messages en attente : {"apikey": "...", "action": "resync"}.
    Renvoie True si un cycle doit être lancé immédiatement (resync demandée)."""
    immediate = False
    while not JEEDOM_SOCKET_MESSAGE.empty():
        raw = JEEDOM_SOCKET_MESSAGE.get()
        try:
            msg = json.loads(raw.decode('utf-8').strip())
        except Exception as exc:
            logging.warning('[socket] JSON invalide: %s — données brutes: %s', exc, raw)
            continue
        if not isinstance(msg, dict):
            logging.warning('[socket] message ignoré (objet JSON attendu): %r', raw)
            continue
        if msg.get('apikey') != _apikey:
            logging.warning('[socket] clé API invalide')
            continue
        action = msg.get('action')
        # Une action en erreur ne fait perdre ni les messages suivants ni le cycle immédiat
        try:
            if action == 'resync':
                daemon.request_resync()
                immediate = True
                logging.info('[socket] resynchronisation demandée')
            elif action == 'charger':
                # {"action":"charger","id":"charger_1","cmd":"mode_next","value":null}
                daemon.charger_command(str(msg.get('id') or ''), str(msg.get('cmd') or ''), msg.get('value'))
            elif action == 'battery':
                # {"action":"battery","cmd":"hold|auto|no_charge|force_charge|limit_charge|reserve","value":…}
                daemon.battery_command(str(msg.get('cmd') or ''), msg.get('value'))
            else:
                logging.warning('[socket] action inconnue: %s', action)
        except Exception as exc:
            logging.error('[socket] action %s en erreur: %s', action, exc)
    return immediate


def apply_timezone(tz):
    """Fuseau configuré dans Jeedom (``--tz``) appliqué au processus : minuit (énergies du jour) et
    ``last_update`` suivent l'heure de Jeedom et non celle du système (conteneur Docker en UTC…)."""
    if not tz:
        return
    if not hasattr(time, 'tzset'):
        logging.warning('Fuseau %s non applicable sur cette plateforme', tz)
        return
    os.environ['TZ'] = tz
    time.tzset()
    logging.info('Fuseau     : %s', tz)


def load_chargers(path):
    """Liste des bornes [{logical_id, host, password}] écrite par Jeedom ; [] si absente/illisible.
    Le fichier (mots de passe en clair) est supprimé une fois lu : Jeedom le réécrit à chaque
    démarrage du démon."""
    if not path or not os.path.isfile(path):
        return []
    try:
        with open(path, encoding='utf-8') as fh:
            data = json.load(fh)
    except (OSError, ValueError) as exc:
        logging.warning('[charger] fichier %s illisible: %s', path, exc)
        return []
    finally:
        try:
            os.remove(path)
        except OSError:
            pass
    return data if isinstance(data, list) else []


# ---------------------------------------------------------------------------
# Boucle principale
# ---------------------------------------------------------------------------

def main_loop(daemon):
    next_run = 0.0
    while True:
        try:
            if read_socket(daemon):
                next_run = 0.0   # découverte immédiate, sans attendre la fin du cycle
            now = time.time()
            if now >= next_run:
                delay = daemon.run_cycle(now)
                next_run = time.time() + delay
                logging.debug('[loop] prochain cycle dans %.1fs', delay)
            else:
                time.sleep(_IDLE_SLEEP)
        except Exception as exc:
            logging.error('[loop] erreur: %s', exc)
            logging.debug(traceback.format_exc())
            time.sleep(1.0)


def _on_signal(signum, frame):
    """SIGTERM / SIGINT : on ne fait que sortir de la boucle. Tout l'arrêt (mise en file, flush,
    nettoyage) se fait ensuite dans le ``finally`` du point d'entrée, hors contexte de signal : un
    signal reçu pendant un ``queue.put`` du cycle ne peut plus bloquer le processus sur le verrou
    de la file."""
    raise SystemExit(0)


def shutdown(jeedom_sock=None):
    # Un second signal (pkill de deamon_stop) n'interrompt pas le nettoyage
    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            signal.signal(sig, signal.SIG_IGN)
        except Exception:
            pass
    logging.info('Arrêt du démon')
    try:
        if 'daemon' in globals():
            daemon.stop()
    except Exception:
        pass
    try:
        if 'sender' in globals():
            sender.flush(2.0)       # derniers lots (« Connexion borne » à 0…) avant de quitter
    except Exception:
        pass
    try:
        if jeedom_sock:
            jeedom_sock.close()
    except Exception:
        pass
    try:
        os.remove(_pidfile)
    except Exception:
        pass
    sys.stdout.flush()
    os._exit(0)


# ---------------------------------------------------------------------------
# Point d'entrée
# ---------------------------------------------------------------------------

parser = argparse.ArgumentParser(description='MyFronius daemon — Fronius Solar API V1')
parser.add_argument('--loglevel',   help='Niveau de log (debug/info/warning/error)', type=str, default='error')
parser.add_argument('--callback',   help='URL de callback Jeedom',                   type=str, default='')
parser.add_argument('--apikey',     help='Clé API Jeedom',                           type=str, default='')
parser.add_argument('--socketport', help='Port TCP du socket',                        type=int, default=55010)
parser.add_argument('--cycle',      help='Intervalle de lecture en secondes',         type=float, default=15.0)
parser.add_argument('--host',       help='IP ou hôte de l\'onduleur (port 80)',       type=str, default='')
parser.add_argument('--pid',        help='Fichier PID',                              type=str, default=_pidfile)
parser.add_argument('--statefile',  help='Fichier JSON d\'état journalier',           type=str, default='/tmp/myfronius_state.json')
parser.add_argument('--persistfile', help='Copie persistante de l\'état journalier (hors tmpfs, écrite rarement)', type=str, default='')
parser.add_argument('--capacity',   help='Capacité batterie (Wh) si le stockage ne la donne pas', type=float, default=0.0)
parser.add_argument('--chargers',   help='Fichier JSON des bornes Wattpilot [{logical_id, host, password}]', type=str, default='')
parser.add_argument('--modbus-host', help='Hôte Modbus TCP de l\'onduleur (vide = pilotage batterie désactivé)', type=str, default='')
parser.add_argument('--modbus-port', help='Port Modbus TCP (défaut 502)', type=int, default=502)
parser.add_argument('--tz',         help='Fuseau horaire de Jeedom (ex. Europe/Paris)', type=str, default='')
parser.add_argument('--modbus-unit', help='Unit ID Modbus de l\'onduleur (défaut 1)', type=int, default=1)
args = parser.parse_args()

_apikey = args.apikey
_pidfile = args.pid
cycle = bound_cycle(args.cycle)

jeedom_utils.set_log_level(args.loglevel)
# Clé API masquée dans tout le log (ligne du socket journalisée par le template, URL d'urllib3…)
logfilter.install(_apikey)
logging.getLogger('urllib3').setLevel(logging.WARNING)
if cycle != args.cycle:
    logging.warning('Cadence %.1fs hors bornes, ramenée à %.1fs', args.cycle, cycle)

logging.info('=== Démarrage MyFronius daemon ===')
logging.info('Log level  : %s', args.loglevel)
logging.info('Host       : %s', args.host)
logging.info('Cycle      : %.1fs', cycle)
logging.info('Socket port: %s', args.socketport)
logging.info('PID file   : %s', _pidfile)
logging.info('State file : %s', args.statefile)
logging.info('Persist    : %s', args.persistfile or 'aucune')
logging.info('Callback   : %s', args.callback)
apply_timezone(args.tz)

if not args.host:
    logging.error('Paramètre --host obligatoire')
    sys.exit(1)

chargers = load_chargers(args.chargers)
logging.info('Bornes      : %s', ', '.join(c.get('logical_id', '?') + '@' + c.get('host', '?') for c in chargers) or 'aucune')

modbus_client = None
if args.modbus_host:
    from myfronius.modbus import ModbusTcp
    modbus_client = ModbusTcp(args.modbus_host, port=args.modbus_port, unit=args.modbus_unit)
    logging.info('Modbus      : %s:%s unit %s', args.modbus_host, args.modbus_port, args.modbus_unit)
else:
    logging.info('Modbus      : désactivé')

# Envoi ordonné : send_change_immediate du template lance un thread par lot, ce qui laisse Jeedom
# appliquer deux lots rapprochés dans le désordre. Une file à thread unique poste les lots avec
# l'envoi du plugin (mêmes tentatives que le template, clé API absente des messages d'erreur).
sender = OrderedSender(make_poster(args.callback, _apikey))
client = SolarApiClient(args.host)
daemon = Daemon(client=client, send=sender.send, cycle=cycle,
                statefile=args.statefile, capacity_wh=args.capacity or None, chargers=chargers,
                modbus_client=modbus_client, persistfile=args.persistfile or None)
# Lot non livré (Jeedom injoignable) : le filtre de variation oublie ce qu'il croyait envoyé
sender.on_failure = daemon.on_send_failure

jeedom_utils.write_pid(str(_pidfile))

jeedom_sock = jeedom_socket(port=args.socketport, address='localhost')
signal.signal(signal.SIGINT,  _on_signal)
signal.signal(signal.SIGTERM, _on_signal)
try:
    jeedom_sock.open()
    main_loop(daemon)
except Exception as exc:
    logging.error('Erreur fatale: %s', exc)
    logging.debug(traceback.format_exc())
finally:
    shutdown(jeedom_sock)
