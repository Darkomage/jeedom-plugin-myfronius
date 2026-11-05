<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function myfronius_setDefaults() {
    $defaults = [
        'cycle'            => 15,
        'socketport'       => 55010,
        'cmd_mode_default' => 'standard',
        'modbus_enabled'   => '0',
        'modbus_port'      => 502,
        'modbus_unit'      => 1,
    ];
    foreach ($defaults as $key => $value) {
        if (config::byKey($key, 'myfronius', '') === '') {
            config::save($key, $value, 'myfronius');
        }
    }
}

// Fonction exécutée automatiquement après l'installation du plugin
function myfronius_install() {
    myfronius_setDefaults();
    // Installation neuve : les commandes recevront les réglages à leur création, pas de migration
    config::save('history_defaults', 1, 'myfronius');
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function myfronius_update() {
    myfronius_setDefaults();
    myfronius::migrateHistoryDefaults();   // aussi lancée au démarrage du démon (déploiement par copie)
    // Page Web View retirée (clé API exposée) : purge du réglage éventuellement enregistré
    config::remove('webwidget_enabled', 'myfronius');
    // Mot de passe des bornes chiffré en base (hook myfronius::encrypt) : réécriture directe (sans
    // redémarrage du démon) des bornes enregistrées en clair par les versions précédentes
    foreach (eqLogic::byType('myfronius') as $eq) {
        if ($eq->getConfiguration('type', '') === 'charger' && (string) $eq->getConfiguration('password', '') !== '') {
            $eq->setChanged(true);
            $eq->save(true);
        } elseif ($eq->getConfiguration('type', '') === 'site' && $eq->getDisplay('width', '') === '') {
            // Site jamais redimensionné : largeur par défaut du core (230 px) trop étroite pour le widget
            $eq->setDisplay('width', '320px');
            $eq->setChanged(true);
            $eq->save(true);
        }
    }
}

// Fonction exécutée automatiquement après la suppression du plugin
function myfronius_remove() {
    // Liste des bornes (mots de passe) laissée par un démon qui ne l'aurait pas lue
    @unlink(jeedom::getTmpFolder('myfronius') . '/chargers.json');
}
