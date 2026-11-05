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
Filtre de variation minimale : ne renvoie que les valeurs qui ont changé au-delà d'un seuil
propre à leur famille, pour ne pas saturer la base Jeedom à chaque cycle avec du bruit de mesure.
Toutes les valeurs sont renvoyées d'office la première fois, puis périodiquement
(``force_every`` secondes) pour que Jeedom garde une date de mise à jour fraîche. L'échéance est
tenue par commande (équipement, clé) : un lot partiel (poussée d'une borne, ordre batterie) ne
réarme que les valeurs qu'il contient, les autres restent forcées au lot suivant.
"""

# Seuils par préfixe de clé (le premier préfixe correspondant l'emporte)
THRESHOLDS = (
    ('e_', 0.001),          # énergies en kWh
    ('total_energy', 0.001),
    ('capacity_', 0.001),
    ('fte', 0.001),         # énergie du prochain trajet d'une borne (kWh)
    ('cco', 0.1),           # consommation du véhicule d'une borne (kWh/100 km)
    ('soc', 0.1),
    ('rel_', 0.1),
    ('rate_', 0.1),
    ('temperature', 0.1),
    ('u_', 0.1), ('udc_', 0.1), ('uac', 0.1), ('voltage', 0.1),
    ('i_', 0.01), ('idc_', 0.01), ('iac', 0.01), ('current', 0.01),
    ('fac', 0.01), ('frequency', 0.01), ('pf_', 0.01),
)
DEFAULT_THRESHOLD = 1.0     # puissances (W), tendances, durées (min)


def threshold_for(key):
    for prefix, value in THRESHOLDS:
        if key.startswith(prefix):
            return value
    return DEFAULT_THRESHOLD


class Deadband:
    """Mémorise la dernière valeur envoyée par (équipement, commande) et filtre les répétitions."""

    def __init__(self, force_every=300.0):
        self.force_every = float(force_every)
        self._last = {}
        self._sent_at = {}          # (équipement, commande) → horodatage du dernier envoi

    def reset(self):
        """Oublie tout : le prochain lot est envoyé intégralement."""
        self._last = {}
        self._sent_at = {}

    def mark(self, payload):
        """Mémorise des valeurs envoyées hors filtre : la valeur réelle suivante repartira si elle diffère."""
        for eq_id, values in payload.items():
            if isinstance(values, dict):
                for key, value in values.items():
                    self._last[(eq_id, key)] = value

    def filter(self, payload, now):
        out = {}
        for eq_id, values in payload.items():
            if not isinstance(values, dict):
                continue
            kept = {}
            for key, value in values.items():
                ref = (eq_id, key)
                sent_at = self._sent_at.get(ref)
                force = sent_at is None or (now - sent_at) >= self.force_every
                if force or self._changed(ref, key, value):
                    kept[key] = value
                    self._last[ref] = value
                    self._sent_at[ref] = now
            if kept:
                out[eq_id] = kept
        return out

    def _changed(self, ref, key, value):
        if ref not in self._last:
            return True
        previous = self._last[ref]
        if isinstance(value, str) or isinstance(previous, str) or value is None or previous is None:
            return value != previous
        try:
            return abs(float(value) - float(previous)) >= threshold_for(key)
        except (TypeError, ValueError):
            return value != previous
