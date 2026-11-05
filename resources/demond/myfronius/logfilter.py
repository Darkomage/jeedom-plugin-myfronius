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
Masquage de la clé API dans le log du démon.

Le log ``myfronius_daemon`` est souvent collé sur Community. Or la lib template (intouchable)
journalise la ligne reçue sur le socket, clé comprise (« Message read from socket »), et urllib3
trace les URL de requête. Un filtre posé sur les handlers du logger racine remplace la clé par
``***`` dans tout message, quel que soit le logger d'origine.
"""

import logging

MASK = '***'


class SecretFilter(logging.Filter):
    """Remplace ``secret`` par ``***`` dans le message final d'un enregistrement (jamais rejeté)."""

    def __init__(self, secret):
        super().__init__()
        self._secret = secret or ''

    def filter(self, record):
        if not self._secret:
            return True
        try:
            message = record.getMessage()
        except Exception:
            return True
        if self._secret in message:
            record.msg = message.replace(self._secret, MASK)
            record.args = None
        return True


def install(secret, logger=None):
    """Pose le filtre sur ``logger`` (racine par défaut) et sur chacun de ses handlers.
    Les enregistrements propagés depuis les loggers enfants (urllib3…) ne passent que par les
    filtres des handlers : d'où la pose sur les deux."""
    logger = logger or logging.getLogger()
    flt = SecretFilter(secret)
    logger.addFilter(flt)
    for handler in logger.handlers:
        handler.addFilter(flt)
    return flt
