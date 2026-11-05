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
 * Actions supplémentaires d'une charge pilotée, lancées en arrière-plan par
 * myfroniusLoadManager::runOne() après la commande Allumer / Éteindre : exécutées dans l'ordre hors
 * du callback du démon, pour qu'un wait, un sleep ou une commande lente ne bloque pas les envois.
 *
 * Usage (CLI uniquement) : php myfroniusActions.php eqLogic_id=<id> action=on|off
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden');
}

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../core/php/myfronius.inc.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    $parts = explode('=', $arg, 2);
    if (count($parts) === 2) {
        $args[$parts[0]] = $parts[1];
    }
}
$action = $args['action'] ?? '';
$eq     = eqLogic::byId((int) ($args['eqLogic_id'] ?? 0));
if (!is_object($eq) || $eq->getEqType_name() !== 'myfronius' || !in_array($action, ['on', 'off'], true)) {
    log::add('myfronius', 'error', '[load] actions supplémentaires : arguments invalides (' . implode(' ', array_slice($argv, 1)) . ')');
    exit(1);
}
try {
    myfroniusLoadManager::executeActions($eq, $action);
} catch (Exception $e) {
    log::add('myfronius', 'error', '[load] ' . $eq->getName() . ' : actions supplémentaires « ' . $action . ' » : ' . $e->getMessage());
    exit(1);
}
