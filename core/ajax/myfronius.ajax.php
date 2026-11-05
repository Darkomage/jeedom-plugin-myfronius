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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../../core/php/myfronius.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    switch (init('action')) {

        /**
         * Teste l'accès à la Solar API, sauvegarde l'adresse, décrit le site et démarre le démon.
         */
        case 'testConnection':
            $host = trim(init('host')) ?: trim((string) config::byKey('host', 'myfronius', ''));
            if ($host === '') {
                throw new Exception(__('Adresse de l\'onduleur non renseignée', __FILE__));
            }
            $api     = new FroniusSolarApi($host);
            $version = $api->apiVersion();
            $pf      = $api->powerFlow();
            $site    = $pf['Site'] ?? [];

            $hasSoc = false;
            foreach ($pf['Inverters'] ?? [] as $inv) {
                if (is_array($inv) && isset($inv['SOC'])) {
                    $hasSoc = true;
                }
            }
            $meters = [];
            try {
                $meters = $api->meters();
            } catch (Exception $e) {
                log::add('myfronius', 'debug', '[testConnection] compteurs : ' . $e->getMessage());
            }
            $storageOk = false;
            if (isset($site['P_Akku']) || $hasSoc) {
                try {
                    $storages  = $api->storages();
                    // Même règle que la découverte : au moins un contrôleur non vide
                    $storageOk = FroniusSolarApi::firstStorageWithController($storages) !== null;
                } catch (Exception $e) {
                    log::add('myfronius', 'debug', '[testConnection] stockage : ' . $e->getMessage());
                }
            }

            config::save('host', $host, 'myfronius');

            $daemonStarted = false;
            $daemonInfo    = myfronius::deamon_info();
            if ($daemonInfo['state'] === 'nok' && $daemonInfo['launchable'] === 'ok') {
                try {
                    myfronius::deamon_start();
                    // Lancé avec la nouvelle adresse : pas de redémarrage différé par config::save
                    myfronius::cancelPendingRestart();
                    $daemonStarted = true;
                } catch (Exception $e) {
                    log::add('myfronius', 'warning', '[testConnection] démarrage du démon impossible : ' . $e->getMessage());
                }
            }

            ajax::success([
                'apiVersion'       => $version['APIVersion'] ?? '?',
                'compatibility'    => $version['CompatibilityRange'] ?? '',
                'mode'             => $site['Mode'] ?? '?',
                'powerflowVersion' => $pf['Version'] ?? '?',
                'inverters'        => count($pf['Inverters'] ?? []),
                'meters'           => count($meters),
                'battery'          => isset($site['P_Akku']) || $hasSoc,
                'storageOk'        => $storageOk,
                'daemonStarted'    => $daemonStarted,
            ]);
            break;

        /**
         * Synchronise les équipements (démon : resync différée ; sinon découverte directe).
         */
        case 'syncEquipments':
            ajax::success(myfronius::syncEquipments());
            break;

        /**
         * Test Modbus en lecture seule : carte SunSpec, modèle stockage, registres de contrôle.
         */
        case 'testModbus':
            $host = trim(init('host')) ?: trim((string) config::byKey('host', 'myfronius', ''));
            $port = (int) (init('port') ?: config::byKey('modbus_port', 'myfronius', 502));
            $unit = (int) (init('unit') ?: config::byKey('modbus_unit', 'myfronius', 1));
            if ($host === '') {
                throw new Exception(__('Adresse de l\'onduleur non renseignée', __FILE__));
            }
            $modbus = new FroniusModbus($host, $port > 0 ? $port : 502, $unit > 0 ? $unit : 1);
            ajax::success($modbus->probe());
            break;

        /**
         * Crée un équipement borne Wattpilot vide (adresse et mot de passe à renseigner ensuite).
         */
        case 'addCharger':
            $eq = myfronius::createCharger();
            ajax::success(['id' => $eq->getId(), 'name' => $eq->getName()]);
            break;

        /**
         * Crée un équipement charge pilotée vide (commandes externes à renseigner ensuite).
         */
        case 'addLoad':
            $eq = myfronius::createLoad();
            ajax::success(['id' => $eq->getId(), 'name' => $eq->getName()]);
            break;

        /**
         * Supprime et recrée les commandes d'un équipement depuis la découverte en cache.
         */
        case 'regenerateCommands':
            $cmdMode = in_array(init('cmdMode'), ['standard', 'expert'], true) ? init('cmdMode') : null;
            myfronius::regenerateCommands((int) init('eqLogicId'), $cmdMode);
            ajax::success();
            break;

        /**
         * Restaure les défauts d'historisation (historisation, répétition, lissage, purge) de toutes
         * les commandes : apply=0 aperçu (rien modifié), apply=1 application après confirmation.
         */
        case 'resetHistoryDefaults':
            ajax::success(myfronius::resetHistoryDefaults(init('apply') === '1'));
            break;
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
