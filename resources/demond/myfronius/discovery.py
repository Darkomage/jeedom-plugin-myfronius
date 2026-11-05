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
Découverte de la structure réelle de l'installation.

Interroge une fois les quatre requêtes et décrit ce qui existe vraiment (onduleurs, entrées MPPT
non nulles, compteurs et leur emplacement, stockage exploitable ou non). Le résultat est un dict
sérialisable envoyé à Jeedom (clé ``_discovery``) pour créer les équipements et uniquement les
commandes dont les canaux existent.
"""

import html
import logging

from .solar_api import SolarApiError

# Index MPPT -> (canal tension, canal courant) dans CommonInverterData
MPPT_KEYS = {
    1: ('UDC', 'IDC'),
    2: ('UDC_2', 'IDC_2'),
    3: ('UDC_3', 'IDC_3'),
    4: ('UDC_4', 'IDC_4'),
}


def _value(node):
    """Extrait la valeur d'un noeud CommonInverterData ({"Unit":..,"Value":..}) ou d'un scalaire."""
    if isinstance(node, dict):
        return node.get('Value')
    return node


def _present_channels(data):
    """Liste des canaux dont la valeur n'est pas null (hors Details)."""
    channels = []
    for key, node in (data or {}).items():
        if key == 'Details':
            continue
        if key == 'DeviceStatus':
            channels.append(key)
            continue
        if _value(node) is not None:
            channels.append(key)
    return sorted(channels)


def _to_int(value, default=None):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def primary_meter_id(meters):
    """DeviceId du meter primaire : emplacement 0 (point d'injection) uniquement.

    Un meter côté conso (1) mesure les charges et non l'échange réseau : il ne sert pas à
    import/export, déduits alors de ∫p_grid (spec §3.4).
    """
    for device_id, meter in (meters or {}).items():
        if _to_int((meter or {}).get('Meter_Location_Current')) == 0:
            return str(device_id)
    return None


def _safe_call(label, func):
    """Appelle func() ; renvoie {} et journalise si la Solar API ou le transport échoue."""
    try:
        return func() or {}
    except SolarApiError as exc:
        logging.warning('[discovery] %s indisponible (code %s): %s', label, exc.code, exc.reason)
    except Exception as exc:  # transport (timeout, DNS, refus de connexion...)
        logging.warning('[discovery] %s injoignable: %s', label, exc)
    return {}


def discover(client):
    """Renvoie la description de l'installation (voir spec section 4)."""
    power_flow = client.power_flow()
    site = power_flow.get('Site') or {}
    pf_inverters = power_flow.get('Inverters') or {}

    result = {
        'site': {
            'mode': site.get('Mode'),
            'powerflow_version': _to_int(power_flow.get('Version'), 0),
            'meter_location': site.get('Meter_Location'),
        },
        'battery': {
            'present': False,
            'storage_ok': False,
            'storage_id': None,
            'manufacturer': None,
            'model': None,
            'serial': None,
            'capacity_max': None,
            'capacity_designed': None,
        },
        'inverters': [],
        'meters': [],
        'primary_meter': None,
    }

    # --- Batterie : présente si P_Akku non null ou si un onduleur expose un SOC ---------------
    has_soc = any(isinstance(inv, dict) and inv.get('SOC') is not None for inv in pf_inverters.values())
    result['battery']['present'] = site.get('P_Akku') is not None or has_soc

    # --- Infos statiques (optionnelles) : GetInverterInfo, GetLoggerInfo -----------------------
    infos = _safe_call('GetInverterInfo', getattr(client, 'inverter_info', lambda: {}))
    logger = _safe_call('GetLoggerInfo', getattr(client, 'logger_info', lambda: {}))
    if logger:
        result['site']['firmware'] = str(logger.get('SWVersion') or '')
        result['site']['product'] = str(logger.get('ProductID') or '')
        result['site']['logger_id'] = str(logger.get('UniqueID') or '')

    # --- Onduleurs ----------------------------------------------------------------------------
    for device_id, pf_inv in sorted(pf_inverters.items(), key=lambda kv: str(kv[0])):
        info = (infos or {}).get(str(device_id)) or {}
        entry = {
            'device_id': str(device_id),
            'device_type': _to_int((pf_inv or {}).get('DT')),
            'manufacturer': 'Fronius',
            # CustomName = nom donné dans l'onduleur, pas le modèle (fourni par Modbus, core.py)
            'model': None,
            'custom_name': html.unescape(str(info.get('CustomName') or '')).strip() or None,
            'serial': str(info.get('UniqueID') or '') or None,
            'pv_power': _to_int(info.get('PVPower')) or None,  # 0 sur GEN24 (invalide)
            'mppt': [],
            'channels': [],
        }
        data = _safe_call('onduleur ' + str(device_id), lambda: client.inverter(device_id))
        entry['channels'] = _present_channels(data)
        for index, (u_key, i_key) in MPPT_KEYS.items():
            if _value(data.get(u_key)) is not None and _value(data.get(i_key)) is not None:
                entry['mppt'].append(index)
        result['inverters'].append(entry)

    # --- Compteurs ----------------------------------------------------------------------------
    meters = _safe_call('compteurs', client.meters)
    for device_id, meter in sorted(meters.items(), key=lambda kv: str(kv[0])):
        meter = meter or {}
        details = meter.get('Details') or {}
        result['meters'].append({
            'device_id': str(device_id),
            'manufacturer': details.get('Manufacturer'),
            'model': details.get('Model'),
            'serial': details.get('Serial'),
            'location': _to_int(meter.get('Meter_Location_Current')),
            'channels': _present_channels(meter),
        })
    result['primary_meter'] = primary_meter_id(meters)
    if result['primary_meter'] is None and any(m['location'] == 1 for m in result['meters']):
        logging.info('[discovery] meter côté conso (emplacement 1) : import/export déduits de ∫p_grid')

    # --- Stockage -----------------------------------------------------------------------------
    if result['battery']['present']:
        storages = _safe_call('stockage', client.storages)
        for device_id, storage in sorted(storages.items(), key=lambda kv: str(kv[0])):
            controller = (storage or {}).get('Controller') or {}
            if not controller:
                continue
            details = controller.get('Details') or {}
            result['battery'].update({
                'storage_ok': True,
                'storage_id': str(device_id),
                'manufacturer': details.get('Manufacturer'),
                'model': details.get('Model'),
                'serial': details.get('Serial'),
                'capacity_max': controller.get('Capacity_Maximum'),
                'capacity_designed': controller.get('DesignedCapacity'),
            })
            break  # un seul stockage supporté par le plugin

    logging.info('[discovery] mode=%s version=%s onduleurs=%s meters=%s primaire=%s batterie=%s stockage=%s',
                 result['site']['mode'], result['site']['powerflow_version'],
                 [i['device_id'] for i in result['inverters']],
                 [m['device_id'] for m in result['meters']], result['primary_meter'],
                 result['battery']['present'], result['battery']['storage_ok'])
    return result
