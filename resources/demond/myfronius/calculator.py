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
Calculateur du site : normalisation des réponses Solar API, tendances, énergies du jour et
estimations batterie.

Deux étapes :

1. :func:`normalize` transforme les quatre réponses brutes en un échantillon plat, avec les
   **conventions de signe humaines** (réseau + = import, batterie + = charge, maison ≥ 0) et les
   énergies encore en Wh. Toute valeur ``null`` est omise : rien n'est inventé.
2. :class:`SiteCalculator.update` reçoit cet échantillon avec l'heure courante et la date locale,
   maintient fenêtres glissantes, intégrations et références de compteurs, et renvoie le lot
   ``{eqLogicLogicalId: {cmdLogicalId: valeur}}`` prêt à être envoyé à Jeedom (W arrondis, kWh à
   3 décimales, % à 1 décimale).

Le temps est toujours passé en paramètre : aucune horloge réelle ici, ce qui rend le module
testable et indépendant de la cadence.
"""

import logging
import time
from collections import deque

from .discovery import MPPT_KEYS

# Fenêtres de tendance (secondes) et bases sur lesquelles elles sont calculées.
# avg1 : les charges pilotées espacent leurs bascules d'au moins cette fenêtre
# (myfroniusLoadManager::SETTLE_DELAY), à garder alignées.
WINDOWS = {'avg1': 60, 'avg5': 300, 'avg15': 900}
SLOPE_WINDOW = 300
TREND_BASES = ('p_pv', 'p_load', 'p_grid', 'p_akku', 'surplus', 'surplus_batt')

# Au-delà de ce trou entre deux échantillons, on considère la série interrompue : les fenêtres
# repartent de zéro et rien n'est intégré sur la période inconnue.
MAX_GAP = 120.0

# Seuil (W) sous lequel la batterie est considérée au repos
BATTERY_IDLE_W = 10.0

# Écart (Wh) au-delà duquel deux lectures successives d'un compteur ne sont pas cohérentes :
# une lecture en recul n'est confirmée que par une lecture qui la prolonge de moins que cet écart.
MAX_STEP_WH = 2000.0

# Accumulateurs intégrés (Wh) remis à zéro à minuit
ACC_KEYS = ('e_day_pv', 'e_day_load', 'e_day_akku_charge', 'e_day_akku_discharge',
            'e_day_grid_import', 'e_day_grid_export')


# ---------------------------------------------------------------------------
# Normalisation
# ---------------------------------------------------------------------------

def _num(value):
    """float ou None (null, chaîne vide, type inattendu)."""
    if value is None or isinstance(value, bool):
        return None if value is None else float(value)
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _node(data, key):
    """Valeur d'un nœud CommonInverterData {"Unit","Value"} ou scalaire."""
    node = (data or {}).get(key)
    if isinstance(node, dict):
        return _num(node.get('Value'))
    return _num(node)


def _put(target, key, value):
    if value is not None:
        target[key] = value


def _bool01(value):
    if value is None:
        return None
    return 1 if value else 0


def normalize(power_flow, inverters, meters, storages):
    """Échantillon plat à signes humains. ``inverters`` = {DeviceId: CommonInverterData}."""
    sample = {}
    site_in = (power_flow or {}).get('Site') or {}
    pf_inverters = (power_flow or {}).get('Inverters') or {}

    # --- Site -----------------------------------------------------------------
    site = {}
    if 'P_PV' in site_in:
        # Exception à « null → omis » : P_PV null = onduleur à l'arrêt (doc Solar API) → 0 W,
        # sinon la production resterait figée sur sa valeur de fin de journée
        p_pv = _num(site_in.get('P_PV'))
        site['p_pv'] = p_pv if p_pv is not None else 0.0
    load = _num(site_in.get('P_Load'))
    _put(site, 'p_load', -load if load is not None else None)
    p_grid = _num(site_in.get('P_Grid'))
    if p_grid is None and site_in.get('BackupMode'):
        # Secours (îlotage) : Smart Meter hors ligne, aucun échange possible → 0 W, sinon le
        # réseau resterait figé sur le dernier import mesuré avant la coupure
        p_grid = 0.0
    _put(site, 'p_grid', p_grid)
    akku = _num(site_in.get('P_Akku'))
    _put(site, 'p_akku', -akku if akku is not None else None)
    _put(site, 'rel_autonomy', _num(site_in.get('rel_Autonomy')))
    _put(site, 'rel_selfconsumption', _num(site_in.get('rel_SelfConsumption')))
    _put(site, 'mode', site_in.get('Mode'))
    _put(site, 'backup_mode', _bool01(site_in.get('BackupMode')))
    _put(site, 'battery_standby', _bool01(site_in.get('BatteryStandby')))
    sample['site'] = site

    # --- Batterie -------------------------------------------------------------
    battery = {}
    for pf_inv in pf_inverters.values():
        if isinstance(pf_inv, dict) and pf_inv.get('SOC') is not None:
            _put(battery, 'soc', _num(pf_inv.get('SOC')))
            _put(battery, 'mode', pf_inv.get('Battery_Mode'))
            break
    _put(battery, 'standby', _bool01(site_in.get('BatteryStandby')))
    for storage in (storages or {}).values():
        controller = (storage or {}).get('Controller') or {}
        if not controller:
            continue
        if 'soc' not in battery:
            _put(battery, 'soc', _num(controller.get('StateOfCharge_Relative')))
        _put(battery, 'capacity_max', _num(controller.get('Capacity_Maximum')))
        _put(battery, 'capacity_designed', _num(controller.get('DesignedCapacity')))
        _put(battery, 'voltage_dc', _num(controller.get('Voltage_DC')))
        _put(battery, 'current_dc', _num(controller.get('Current_DC')))
        _put(battery, 'temperature', _num(controller.get('Temperature_Cell')))
        status = _num(controller.get('Status_BatteryCell'))
        _put(battery, 'status_cell', int(status) if status is not None else None)
        enable = _num(controller.get('Enable'))
        _put(battery, 'enable', int(enable) if enable is not None else None)
        break
    if battery:
        sample['battery'] = battery

    # --- Onduleurs ------------------------------------------------------------
    for device_id, data in (inverters or {}).items():
        inv = {}
        _put(inv, 'pac', _node(data, 'PAC'))
        _put(inv, 'sac', _node(data, 'SAC'))
        _put(inv, 'uac', _node(data, 'UAC'))
        _put(inv, 'iac', _node(data, 'IAC'))
        _put(inv, 'fac', _node(data, 'FAC'))
        for index, (u_key, i_key) in MPPT_KEYS.items():
            u, i = _node(data, u_key), _node(data, i_key)
            if u is not None and i is not None:
                inv['udc_%d' % index] = u
                inv['idc_%d' % index] = i
        _put(inv, 'total_energy_wh', _node(data, 'TOTAL_ENERGY'))
        status = (data or {}).get('DeviceStatus') or {}
        _put(inv, 'state', status.get('InverterState'))
        code = _num(status.get('StatusCode'))
        _put(inv, 'status_code', int(code) if code is not None else None)
        err = _num(status.get('ErrorCode'))
        _put(inv, 'error_code', int(err) if err is not None else None)
        sample['inverter_' + str(device_id)] = inv

    # --- Compteurs ------------------------------------------------------------
    for device_id, data in (meters or {}).items():
        data = data or {}
        m = {}
        _put(m, 'p_sum', _num(data.get('PowerReal_P_Sum')))
        _put(m, 'frequency', _num(data.get('Frequency_Phase_Average')))
        _put(m, 's_sum', _num(data.get('PowerApparent_S_Sum')))
        _put(m, 'q_sum', _num(data.get('PowerReactive_Q_Sum')))
        _put(m, 'pf_sum', _num(data.get('PowerFactor_Sum')))
        for n in (1, 2, 3):
            _put(m, 'p_l%d' % n, _num(data.get('PowerReal_P_Phase_%d' % n)))
            _put(m, 'u_l%d' % n, _num(data.get('Voltage_AC_Phase_%d' % n)))
            _put(m, 'i_l%d' % n, _num(data.get('Current_AC_Phase_%d' % n)))
            _put(m, 's_l%d' % n, _num(data.get('PowerApparent_S_Phase_%d' % n)))
            _put(m, 'q_l%d' % n, _num(data.get('PowerReactive_Q_Phase_%d' % n)))
            _put(m, 'pf_l%d' % n, _num(data.get('PowerFactor_Phase_%d' % n)))
        for pair in ('12', '23', '31'):
            _put(m, 'u_l' + pair, _num(data.get('Voltage_AC_PhaseToPhase_' + pair)))
        visible = _num(data.get('Visible'))
        _put(m, 'visible', int(visible) if visible is not None else None)
        # Visible = 0 : valeurs incomplètes ou périmées (Solar API §3.8.5) → index d'énergie
        # ignorés, ni commande à 0 ni saut de l'énergie du jour
        if visible != 0:
            _put(m, 'e_import_total_wh', _num(data.get('EnergyReal_WAC_Plus_Absolute')))
            _put(m, 'e_export_total_wh', _num(data.get('EnergyReal_WAC_Minus_Absolute')))
            _put(m, 'e_reactive_consumed_wh', _num(data.get('EnergyReactive_VArAC_Sum_Consumed')))
            _put(m, 'e_reactive_produced_wh', _num(data.get('EnergyReactive_VArAC_Sum_Produced')))
        loc = _num(data.get('Meter_Location_Current'))
        _put(m, 'location', int(loc) if loc is not None else None)
        sample['meter_' + str(device_id)] = m

    return sample


# ---------------------------------------------------------------------------
# Fenêtre glissante
# ---------------------------------------------------------------------------

class TimeWindow:
    """Échantillons (t, v) sur les ``seconds`` dernières secondes, pondérés par le temps."""

    def __init__(self, seconds):
        self.seconds = float(seconds)
        self._points = deque()

    def clear(self):
        self._points.clear()

    def add(self, t, value):
        self._points.append((float(t), float(value)))
        limit = t - self.seconds
        while len(self._points) > 1 and self._points[0][0] < limit:
            self._points.popleft()

    def span(self):
        if len(self._points) < 2:
            return 0.0
        return self._points[-1][0] - self._points[0][0]

    def full(self):
        """Vrai quand la fenêtre couvre toute sa durée, à 5 % ou à un échantillon près.

        Le point le plus ancien sort dès qu'il dépasse la durée : avec une cadence lente
        (60 s + durée des requêtes), le span plafonne à la durée moins un intervalle.
        """
        span = self.span()
        if span >= self.seconds * 0.95:
            return True
        pts = self._points
        return len(pts) >= 2 and span + (pts[-1][0] - pts[-2][0]) >= self.seconds

    def mean(self):
        pts = self._points
        if not pts:
            return None
        if len(pts) == 1:
            return pts[0][1]
        integral = 0.0
        for (t0, v0), (t1, v1) in zip(pts, list(pts)[1:]):
            integral += (v0 + v1) / 2.0 * (t1 - t0)
        span = pts[-1][0] - pts[0][0]
        return integral / span if span > 0 else pts[-1][1]

    def slope_per_minute(self):
        """Pente par régression linéaire, en unité/minute. 0 si moins de deux points."""
        pts = self._points
        n = len(pts)
        if n < 2:
            return 0.0
        t_first = pts[0][0]
        xs = [t - t_first for t, _ in pts]
        ys = [v for _, v in pts]
        x_mean = sum(xs) / n
        y_mean = sum(ys) / n
        var = sum((x - x_mean) ** 2 for x in xs)
        if var <= 0:
            return 0.0
        cov = sum((x - x_mean) * (y - y_mean) for x, y in zip(xs, ys))
        return cov / var * 60.0


# ---------------------------------------------------------------------------
# Arrondis de sortie
# ---------------------------------------------------------------------------

def _round_value(key, value):
    """Arrondi selon la famille de la clé (voir spec §3, unités)."""
    if isinstance(value, str) or value is None:
        return value
    if key.endswith('_wh'):
        return round(value / 1000.0, 3)
    if key.startswith('capacity_'):
        return round(value / 1000.0, 3)
    if key.startswith('e_') or key.startswith('total_energy'):
        return round(value / 1000.0, 3) if key.endswith('_wh') else round(value, 3)
    if key.startswith(('u_', 'udc_', 'uac', 'voltage')):
        return round(value, 1)
    if key.startswith(('i_', 'idc_', 'iac', 'current')):
        return round(value, 2)
    if key.startswith(('fac', 'frequency', 'pf_')):
        return round(value, 2)
    if key.startswith(('soc', 'rel_', 'rate_', 'temperature')):
        return round(value, 1)
    if key in ('backup_mode', 'battery_standby', 'standby', 'charging', 'window_complete',
               'comm_ok', 'visible', 'enable', 'status_cell', 'status_code', 'error_code',
               'time_to_full', 'time_to_empty'):
        return int(round(value))
    # puissances, surplus, tendances, apparent/réactif : entier
    return int(round(value))


def _out_key(key):
    return key[:-3] if key.endswith('_wh') else key


# ---------------------------------------------------------------------------
# Calculateur
# ---------------------------------------------------------------------------

class SiteCalculator:
    """État du site : fenêtres, intégrations, références de compteurs, estimations batterie."""

    def __init__(self, capacity_wh=None, primary_meter=None, state=None):
        self.capacity_wh = _num(capacity_wh)
        self.primary_meter = str(primary_meter) if primary_meter is not None else None
        self._windows = {}
        for base in TREND_BASES:
            self._windows[base] = {name: TimeWindow(sec) for name, sec in WINDOWS.items()}
            self._windows[base]['slope5'] = TimeWindow(SLOPE_WINDOW)
        self._day = None            # date ISO du jour courant
        self._acc = {k: 0.0 for k in ACC_KEYS}
        self._refs = {}             # clé compteur -> valeur de référence à minuit
        self._last_delta = {}       # clé compteur -> dernier Δ du jour (Wh), repris si lecture absente
        self._backward = {}         # clé compteur -> lecture en recul pas encore confirmée
        self._prev_refs = {}        # clé compteur -> référence d'avant un recul confirmé
        self._carry = {}            # clé compteur -> Wh du jour gardés à la 1re lecture (meter changé)
        self._rolled_over = False   # journée commencée par ce calculateur : Δ onduleur absent = 0
        self._last_t = None
        self._last_powers = {}      # dernières puissances (W) pour les trapèzes
        self.window_complete = False
        if state:
            self._import_state(state)

    def reconfigure(self, capacity_wh=None, primary_meter=None):
        """Paramètres structurels issus d'une nouvelle découverte (resync) : fenêtres de tendance,
        accumulateurs et références conservés. Changement de meter primaire : ses références
        (index d'un autre compteur) sont abandonnées, recalées à la prochaine lecture en gardant
        l'énergie du jour déjà publiée (meter retrouvé après un secours : ∫ p_grid jusque-là)."""
        self.capacity_wh = _num(capacity_wh)
        primary = str(primary_meter) if primary_meter is not None else None
        if primary != self.primary_meter:
            for key, acc_key in (('meter_import', 'e_day_grid_import'), ('meter_export', 'e_day_grid_export')):
                kept = self._last_delta.get(key)
                self._carry[key] = kept if kept is not None else self._acc[acc_key]
                self._refs.pop(key, None)
                self._last_delta.pop(key, None)
                self._backward.pop(key, None)
                self._prev_refs.pop(key, None)
            logging.info('[calc] meter primaire %s → %s : références import/export recalées', self.primary_meter, primary)
        self.primary_meter = primary

    # --- état persistant -------------------------------------------------------

    def export_state(self):
        return {
            'day': self._day,
            'acc': dict(self._acc),
            'refs': dict(self._refs),
            'last_delta': dict(self._last_delta),
            'last_t': self._last_t,
            'last_powers': dict(self._last_powers),
        }

    def _import_state(self, state):
        try:
            self._day = state.get('day')
            acc = state.get('acc') or {}
            self._acc = {k: float(acc.get(k, 0.0) or 0.0) for k in ACC_KEYS}
            self._refs = {str(k): float(v) for k, v in (state.get('refs') or {}).items() if v is not None}
            self._last_delta = {str(k): float(v) for k, v in (state.get('last_delta') or {}).items() if v is not None}
            last_t = state.get('last_t')
            self._last_t = float(last_t) if last_t is not None else None
            self._last_powers = {str(k): float(v) for k, v in (state.get('last_powers') or {}).items()}
        except (TypeError, ValueError):
            self._day = None
            self._acc = {k: 0.0 for k in ACC_KEYS}
            self._refs = {}
            self._last_delta = {}
            self._last_t = None
            self._last_powers = {}

    # --- cycle ------------------------------------------------------------------

    def update(self, sample, now, today, ev_power=0.0):
        """``ev_power`` : puissance totale des bornes de recharge (W), déduite de la maison dans
        ``p_load_ex_ev`` (la consommation maison brute et ses tendances restent inchangées)."""
        now = float(now)
        today_iso = today.isoformat() if hasattr(today, 'isoformat') else str(today)
        site_in = sample.get('site') or {}
        out = {}

        # 1. Discontinuité temporelle : trou trop long → fenêtres remises à zéro, pas d'intégration
        gap = None
        if self._last_t is not None:
            gap = now - self._last_t
            if gap > MAX_GAP or gap <= 0:
                self._reset_windows()
                self._last_powers = {}

        # 2. Rollover minuit : accumulateurs et références repartent de zéro
        if self._day != today_iso:
            self._day = today_iso
            self._acc = {k: 0.0 for k in ACC_KEYS}
            self._refs = {}
            self._last_delta = {}
            self._backward = {}
            self._prev_refs = {}
            self._carry = {}
            self._last_powers = {}
            self._rolled_over = True

        # 3. Flux dérivés du site (W, non arrondis pour les calculs)
        p_grid = site_in.get('p_grid')
        p_akku = site_in.get('p_akku')
        derived = {}
        if p_grid is not None:
            derived['p_grid_import'] = max(p_grid, 0.0)
            derived['p_grid_export'] = max(-p_grid, 0.0)
            derived['surplus'] = derived['p_grid_export']
        if p_akku is not None:
            derived['p_akku_charge'] = max(p_akku, 0.0)
            derived['p_akku_discharge'] = max(-p_akku, 0.0)
        if p_grid is not None and p_akku is not None:
            derived['surplus_batt'] = derived['p_grid_export'] + derived['p_akku_charge']
        elif p_grid is not None:
            derived['surplus_batt'] = derived['p_grid_export']

        # 4. Intégration des puissances (trapèzes) si l'intervalle est exploitable
        powers = {
            'e_day_pv': site_in.get('p_pv'),
            'e_day_load': site_in.get('p_load'),
            'e_day_akku_charge': derived.get('p_akku_charge'),
            'e_day_akku_discharge': derived.get('p_akku_discharge'),
            'e_day_grid_import': derived.get('p_grid_import'),
            'e_day_grid_export': derived.get('p_grid_export'),
        }
        if self._last_powers and gap is not None and 0 < gap <= MAX_GAP:
            for key, value in powers.items():
                prev = self._last_powers.get(key)
                if value is not None and prev is not None:
                    self._acc[key] += (prev + value) / 2.0 * gap / 3600.0
        self._last_powers = {k: v for k, v in powers.items() if v is not None}
        self._last_t = now

        # 5. Fenêtres glissantes
        trend_values = {
            'p_pv': site_in.get('p_pv'),
            'p_load': site_in.get('p_load'),
            'p_grid': p_grid,
            'p_akku': p_akku,
            'surplus': derived.get('surplus'),
            'surplus_batt': derived.get('surplus_batt'),
        }
        trends = {}
        for base, value in trend_values.items():
            if value is None:
                continue
            for name, window in self._windows[base].items():
                window.add(now, value)
                if name == 'slope5':
                    trends[base + '_slope5'] = window.slope_per_minute()
                else:
                    trends[base + '_' + name] = window.mean()
        self.window_complete = self._windows['p_pv']['avg15'].full()

        # 6. Compteurs : import/export du meter primaire, énergie AC des onduleurs.
        # Une lecture manquante (compteurs ou onduleur non lus sur ce cycle) garde le dernier Δ :
        # pas de bascule sur l'intégration ni de 0 publié.
        meter = (sample.get('meter_' + self.primary_meter) if self.primary_meter else None) or {}
        e_import = self._meter_day('meter_import', meter.get('e_import_total_wh'), 'e_day_grid_import')
        e_export = self._meter_day('meter_export', meter.get('e_export_total_wh'), 'e_day_grid_export')

        for eq_id, data in sample.items():
            if not eq_id.startswith('inverter_'):
                continue
            inv_out = {}
            for key, value in data.items():
                inv_out[_out_key(key)] = _round_value(key, value)
            for index in MPPT_KEYS:
                u, i = data.get('udc_%d' % index), data.get('idc_%d' % index)
                if u is not None and i is not None:
                    inv_out['pdc_%d' % index] = int(round(u * i))
            if data.get('total_energy_wh') is not None:
                e_day_ac = self._counter_delta(eq_id + '_total', data['total_energy_wh'])
                if e_day_ac is not None:
                    inv_out['e_day_ac'] = round(e_day_ac / 1000.0, 3)
            out[eq_id] = inv_out
        # somme des derniers Δ connus : un onduleur non lu sur ce cycle garde sa contribution
        inverter_days = [v for k, v in self._last_delta.items()
                         if k.startswith('inverter_') and k.endswith('_total')]

        # 7. Sortie site
        site_out = {}
        for key, value in site_in.items():
            site_out[key] = _round_value(key, value)
        for key, value in derived.items():
            site_out[key] = _round_value(key, value)
        for key, value in trends.items():
            site_out[key] = _round_value(key, value)
        e_pv = self._acc['e_day_pv']
        e_load = self._acc['e_day_load']
        if site_in.get('p_load') is not None:
            site_out['p_load_ex_ev'] = int(round(max(site_in['p_load'] - max(float(ev_power or 0.0), 0.0), 0.0)))
        site_out['e_day_pv'] = round(e_pv / 1000.0, 3)
        site_out['e_day_load'] = round(e_load / 1000.0, 3)
        site_out['e_day_akku_charge'] = round(self._acc['e_day_akku_charge'] / 1000.0, 3)
        site_out['e_day_akku_discharge'] = round(self._acc['e_day_akku_discharge'] / 1000.0, 3)
        # None (Δ inconnu, ex. état ancien sans dernier Δ) : clés omises, Jeedom garde la valeur
        if e_import is not None:
            site_out['e_day_import'] = round(e_import / 1000.0, 3)
            site_out['rate_day_autonomy'] = round(max(0.0, (e_load - e_import) / e_load * 100.0), 1) if e_load > 0 else 0.0
        if e_export is not None:
            site_out['e_day_export'] = round(e_export / 1000.0, 3)
            site_out['rate_day_selfconsumption'] = round(max(0.0, (e_pv - e_export) / e_pv * 100.0), 1) if e_pv > 0 else 0.0
        # journée commencée ici sans onduleur encore lu : 0 (une clé omise garderait la veille)
        if inverter_days or self._rolled_over:
            site_out['e_day_inverter'] = round(sum(inverter_days) / 1000.0, 3)
        site_out['window_complete'] = 1 if self.window_complete else 0
        site_out['last_update'] = time.strftime('%H:%M:%S', time.localtime(now))
        out['site'] = site_out

        # 8. Batterie
        battery_in = sample.get('battery')
        if battery_in is not None or p_akku is not None:
            out['battery'] = self._battery_output(battery_in or {}, p_akku, trends.get('p_akku_avg5'))

        # 9. Compteurs
        for eq_id, data in sample.items():
            if not eq_id.startswith('meter_'):
                continue
            m_out = {}
            for key, value in data.items():
                if key == 'location':
                    continue
                m_out[_out_key(key)] = _round_value(key, value)
            out[eq_id] = m_out

        return out

    # --- helpers ------------------------------------------------------------------

    def _reset_windows(self):
        for windows in self._windows.values():
            for window in windows.values():
                window.clear()
        self.window_complete = False

    def _meter_day(self, ref_key, current, acc_key):
        """Énergie du jour (Wh) du meter primaire. Repli sur l'intégration de ``p_grid`` seulement
        sans meter primaire, ou tant qu'il n'a fourni aucun index depuis minuit."""
        if self.primary_meter is None or (current is None and ref_key not in self._refs):
            return self._acc[acc_key]
        return self._counter_delta(ref_key, current)

    def _counter_delta(self, ref_key, current):
        """Delta (Wh) depuis la référence du jour, ou dernier delta connu (None s'il n'y en a pas)
        quand la lecture manque ou n'est pas plausible.

        Un recul n'est suivi que s'il est confirmé au cycle suivant par une lecture non nulle qui
        prolonge la lecture en attente (compteur remplacé ou remis à zéro) : la référence est alors
        recalée en gardant l'énergie déjà comptée. Une lecture isolée trop basse ou à 0 (valeur
        invalide passagère) est ignorée. Si l'index repasse ensuite au-dessus de l'ancienne
        référence, le recul était aberrant : l'ancienne référence est rétablie. Une référence prise
        sur une lecture à 0 (index légitime d'un compteur neuf ou jamais sollicité, ou valeur
        invalide) est recalée si l'index saute ensuite de plus de MAX_STEP_WH d'un coup.
        """
        last = self._last_delta.get(ref_key)
        if current is None:
            return last
        ref = self._refs.get(ref_key)
        if ref is None:
            kept = self._carry.pop(ref_key, 0.0)
            self._refs[ref_key] = current - kept
            self._last_delta[ref_key] = kept
            return kept
        if current < ref:
            pending = self._backward.get(ref_key)
            if pending is None or not (0 < pending <= current <= pending + MAX_STEP_WH):
                if pending is None:
                    logging.warning('[calculator] compteur %s recule (%s -> %s) : lecture ignorée tant que non confirmée',
                                    ref_key, ref, current)
                self._backward[ref_key] = current
                return last
            del self._backward[ref_key]
            kept = last or 0.0
            self._prev_refs.setdefault(ref_key, ref)
            self._refs[ref_key] = current - kept
            self._last_delta[ref_key] = kept
            logging.warning('[calculator] compteur %s recule (%s -> %s) : référence reprise, %.0f Wh du jour conservés',
                            ref_key, ref, current, kept)
            return kept
        self._backward.pop(ref_key, None)
        prev_ref = self._prev_refs.get(ref_key)
        if prev_ref is not None and current >= prev_ref:
            # l'ancien index est revenu : le recul « confirmé » était une paire de lectures aberrantes
            del self._prev_refs[ref_key]
            self._refs[ref_key] = ref = prev_ref
            logging.warning('[calculator] compteur %s revenu à %s : référence antérieure au recul rétablie',
                            ref_key, current)
        if ref == 0 and current - (last or 0.0) > MAX_STEP_WH:
            # le 0 était une valeur invalide : l'index réel apparaît, l'énergie déjà comptée est gardée
            kept = last or 0.0
            self._refs[ref_key] = ref = current - kept
            logging.warning('[calculator] compteur %s passe de 0 à %s : référence reprise, %.0f Wh du jour conservés',
                            ref_key, current, kept)
        delta = current - ref
        self._last_delta[ref_key] = delta
        return delta

    def _battery_output(self, battery_in, p_akku, p_akku_avg5):
        out = {}
        for key, value in battery_in.items():
            out[key] = _round_value(key, value)
        if p_akku is not None:
            out['p'] = int(round(p_akku))
            if p_akku > BATTERY_IDLE_W:
                out['direction'] = 'charge'
            elif p_akku < -BATTERY_IDLE_W:
                out['direction'] = 'decharge'
            else:
                out['direction'] = 'repos'
            out['charging'] = 1 if p_akku > BATTERY_IDLE_W else 0

        capacity = battery_in.get('capacity_max') or battery_in.get('capacity_designed') or self.capacity_wh
        soc = battery_in.get('soc')
        avg = p_akku_avg5
        out['time_to_full'] = -1
        out['time_to_empty'] = -1
        if capacity and soc is not None and avg is not None:
            if avg > BATTERY_IDLE_W:
                remaining_wh = capacity * (100.0 - soc) / 100.0
                out['time_to_full'] = int(round(remaining_wh / avg * 60.0))
            elif avg < -BATTERY_IDLE_W:
                stored_wh = capacity * soc / 100.0
                out['time_to_empty'] = int(round(stored_wh / (-avg) * 60.0))
        return out
