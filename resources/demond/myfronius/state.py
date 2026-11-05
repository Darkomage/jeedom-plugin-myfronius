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
Persistance de l'état journalier du calculateur (références de compteurs à minuit, accumulateurs
intégrés) dans un fichier JSON local au démon, pour ne pas perdre l'énergie du jour à un
redémarrage du démon. Le dossier tmp de Jeedom est en général un tmpfs, vidé au redémarrage de la
box : c'est la copie persistante (``--persistfile``, gérée par core.py) qui couvre ce cas.
Écriture atomique (fichier temporaire puis ``os.replace``).
"""

import json
import logging
import os


def save_state(path, data):
    """Écrit ``data`` en JSON dans ``path`` de façon atomique. Crée le dossier parent si besoin."""
    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(data, fh)
    os.replace(tmp, path)


def load_state(path):
    """Relit l'état ; None si le fichier est absent, illisible ou corrompu (log warning)."""
    if not os.path.isfile(path):
        return None
    try:
        with open(path, encoding='utf-8') as fh:
            data = json.load(fh)
    except (OSError, ValueError) as exc:
        logging.warning('[state] fichier %s illisible, état ignoré: %s', path, exc)
        return None
    if not isinstance(data, dict):
        logging.warning('[state] fichier %s inattendu (pas un objet), état ignoré', path)
        return None
    return data
