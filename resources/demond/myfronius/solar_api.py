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
Client HTTP minimal de la Fronius Solar API V1.

Une méthode par requête utilisée par le plugin. Chaque méthode renvoie le contenu de
``Body.Data`` déjà déballé, ou lève :class:`SolarApiError` si ``Head.Status.Code`` est différent
de 0 ou si la réponse n'est pas du JSON exploitable. Aucune logique métier ici : ce module est le
seul point de contact avec l'onduleur, pour pouvoir le remplacer (Modbus TCP, simulateur…) sans
toucher au reste du démon.

Le transport est injectable (``fetch(url) -> str``) pour les tests ; par défaut ``urllib``.
"""

import json
import urllib.request


class SolarApiError(Exception):
    """Erreur applicative Solar API (Status.Code ≠ 0) ou réponse illisible."""

    def __init__(self, code, reason='', url=''):
        self.code = code
        self.reason = reason
        self.url = url
        super().__init__(f'Solar API erreur {code}: {reason} ({url})')


class SolarApiClient:
    """Accès aux requêtes temps réel de la Solar API V1."""

    def __init__(self, host, fetch=None, timeout=5.0):
        self.timeout = timeout
        self._fetch = fetch or self._default_fetch
        self.base_url = self._normalize_base(host)

    @staticmethod
    def _normalize_base(host):
        host = (host or '').strip()
        if not host.startswith('http://') and not host.startswith('https://'):
            host = 'http://' + host
        return host.rstrip('/') + '/solar_api/v1/'

    @property
    def root_url(self):
        """Racine du serveur (sans /solar_api/v1/), utilisée pour GetAPIVersion."""
        return self.base_url[:-len('solar_api/v1/')]

    def _default_fetch(self, url):
        with urllib.request.urlopen(url, timeout=self.timeout) as resp:
            charset = resp.headers.get_content_charset() or 'utf-8'
            return resp.read().decode(charset, errors='replace')

    # ------------------------------------------------------------------
    # Bas niveau
    # ------------------------------------------------------------------

    def _get_json(self, url):
        text = self._fetch(url)
        try:
            return json.loads(text)
        except (TypeError, ValueError) as exc:
            raise SolarApiError(-1, 'réponse non JSON: ' + str(exc), url) from exc

    def _get_data(self, url):
        """GET + vérification du Common Response Header, renvoie Body.Data (dict)."""
        doc = self._get_json(url)
        if not isinstance(doc, dict):
            raise SolarApiError(-1, 'réponse JSON inattendue', url)
        status = (doc.get('Head') or {}).get('Status') or {}
        code = status.get('Code', -1)
        if code != 0:
            raise SolarApiError(code, status.get('Reason') or status.get('UserMessage') or '', url)
        data = (doc.get('Body') or {}).get('Data')
        return data if isinstance(data, dict) else {}

    # ------------------------------------------------------------------
    # Requêtes
    # ------------------------------------------------------------------

    def api_version(self):
        """GET /solar_api/GetAPIVersion.cgi — pas de Common Response Header."""
        doc = self._get_json(self.root_url + 'solar_api/GetAPIVersion.cgi')
        if not isinstance(doc, dict) or 'APIVersion' not in doc:
            raise SolarApiError(-1, 'GetAPIVersion illisible', self.root_url)
        return doc

    def power_flow(self):
        """Flux de puissance du site (Site, Inverters, Smartloads, SecondaryMeters, Version)."""
        return self._get_data(self.base_url + 'GetPowerFlowRealtimeData.fcgi')

    def inverter(self, device_id):
        """Données communes d'un onduleur (PAC, UAC, UDC/IDC par MPPT, TOTAL_ENERGY, DeviceStatus)."""
        url = (self.base_url + 'GetInverterRealtimeData.cgi'
               + '?Scope=Device&DeviceId=' + str(device_id) + '&DataCollection=CommonInverterData')
        return self._get_data(url)

    def inverter_info(self):
        """Infos statiques des onduleurs (CustomName, DT, PVPower, UniqueID…), clé = index."""
        return self._get_data(self.base_url + 'GetInverterInfo.cgi')

    def logger_info(self):
        """Infos du datalogger (Body.LoggerInfo : ProductID, SWVersion, HWVersion, UniqueID…)."""
        doc = self._get_json(self.base_url + 'GetLoggerInfo.cgi')
        status = ((doc.get('Head') or {}).get('Status') or {}) if isinstance(doc, dict) else {}
        if status.get('Code', -1) != 0:
            raise SolarApiError(status.get('Code', -1), status.get('Reason') or '', self.base_url)
        info = (doc.get('Body') or {}).get('LoggerInfo')
        return info if isinstance(info, dict) else {}

    def meters(self):
        """Tous les compteurs, clé = DeviceId."""
        return self._get_data(self.base_url + 'GetMeterRealtimeData.cgi?Scope=System')

    def storages(self):
        """Tous les stockages, clé = DeviceId (Controller + Modules)."""
        return self._get_data(self.base_url + 'GetStorageRealtimeData.cgi?Scope=System')
