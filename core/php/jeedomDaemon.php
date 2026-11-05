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

/**
 * Callback HTTP du démon MyFronius.
 *
 * Deux formes de corps JSON :
 *   { "_discovery": {...} }                       → (re)création des équipements et commandes
 *   { "site": {cmd: val, ...}, "battery": {...}, "inverter_1": {...}, "meter_0": {...} }
 *                                                 → mise à jour des commandes info par logicalId
 * Aucun calcul ici : le démon a déjà tout dérivé.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../core/php/myfronius.inc.php';

// Seule la clé API du plugin (celle passée au démon) est acceptée, pas jeedom::apiAccess() qui
// accepte aussi le hash API de n'importe quel utilisateur (profil restreint compris) : ce point
// d'entrée écrit les valeurs, crée des équipements et pilote les charges. Mode API réglé pour
// MyFronius respecté (activé, IP blanche, localhost, désactivé), contrôlé AVANT getApiKey() qui
// remettrait un mode « désactivé » à « activé »
$apiKey = init('apikey');
$apiMode = config::byKey('api::myfronius::mode', 'core', 'enable');
if (!jeedom::apiModeResult($apiMode)) {
    // Clé peut-être juste : c'est le mode API de MyFronius qui refuse cette adresse (« Localhost »
    // devient « IP blanche » sous Docker, liste d'IP blanches sans 127.0.0.1…) — le dire tel quel
    log::add('myfronius', 'warning', '[jeedomDaemon] appel du démon refusé par le mode API de MyFronius (« ' . $apiMode
        . ' ») depuis ' . getClientIp() . ' : Réglages → Système → Configuration → API, autoriser cette adresse');
    http_response_code(403);
    die('Forbidden');
}
if (!is_string($apiKey) || trim($apiKey) === '' || !hash_equals((string) jeedom::getApiKey('myfronius'), $apiKey)) {
    log::add('myfronius', 'warning', '[jeedomDaemon] clé API invalide');
    http_response_code(403);
    die('Forbidden');
}

$body = file_get_contents('php://input');
if (empty($body)) {
    log::add('myfronius', 'warning', '[jeedomDaemon] corps de requête vide');
    die();
}

$data = json_decode($body, true);
if (!is_array($data)) {
    log::add('myfronius', 'warning', '[jeedomDaemon] JSON invalide : ' . substr($body, 0, 200));
    die();
}

if (isset($data['_discovery'])) {
    try {
        $res = myfronius::syncFromDiscovery((array) $data['_discovery']);
        log::add('myfronius', 'info', '[jeedomDaemon] découverte traitée — ' . $res['created'] . ' créé(s), ' . $res['updated'] . ' mis à jour');
    } catch (Exception $e) {
        log::add('myfronius', 'error', '[jeedomDaemon] découverte échouée : ' . $e->getMessage());
    }
    unset($data['_discovery']);
}

$updatedEq     = 0;
$updatedValues = 0;

foreach ($data as $logicalId => $values) {
    if (!is_array($values)) {
        continue;
    }
    $eqLogic = myfronius::byLogicalId($logicalId, 'myfronius');
    if (!is_object($eqLogic) || !$eqLogic->getIsEnable()) {
        log::add('myfronius', 'debug', '[jeedomDaemon] équipement ignoré (absent ou désactivé) : ' . $logicalId);
        continue;
    }
    foreach ($values as $key => $value) {
        try {
            $eqLogic->checkAndUpdateCmd($key, $value);
            $updatedValues++;
        } catch (Exception $e) {
            // Une valeur en échec (historique, listener, scénario…) ne doit pas bloquer les autres
            log::add('myfronius', 'warning', '[jeedomDaemon] ' . $logicalId . '::' . $key . ' : ' . $e->getMessage());
        }
    }
    $updatedEq++;
}

log::add('myfronius', 'debug', '[jeedomDaemon] ' . $updatedEq . ' équipement(s), ' . $updatedValues . ' valeur(s)');

// Charges pilotées : décision à chaque cycle du site (spec §13). L'horodatage du dernier lot sert au
// contrôle de fraîcheur (myfroniusLoadManager::siteStale, filet de myfronius::cron) ; clé de cache à
// part, écrite même quand le tour est sauté (verrou pris), sans toucher au cache du Site.
if (isset($data['site'])) {
    try {
        cache::set(myfroniusLoadManager::LAST_SITE_KEY, time());
        myfroniusLoadManager::run();
    } catch (Exception $e) {
        log::add('myfronius', 'error', '[jeedomDaemon] charges pilotées : ' . $e->getMessage());
    }
}
