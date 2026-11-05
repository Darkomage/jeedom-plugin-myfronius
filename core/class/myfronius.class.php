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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/FroniusSolarApi.class.php';
require_once __DIR__ . '/myfroniusLoadManager.class.php';

class myfronius extends eqLogic
{
    /** Types d'équipement et noms par défaut. */
    const TYPES = [
        'site'     => 'Site PV',
        'battery'  => 'Batterie',
        'inverter' => 'Onduleur',
        'meter'    => 'Smart Meter',
        'charger'  => 'Borne',
        'load'     => 'Charge',
    ];

    const DEFAULT_CYCLE      = 15;
    const MIN_CYCLE          = 5;
    // Au-delà, le démon n'intègre plus les énergies (écart > 120 s) et la consigne batterie
    // expire entre deux cycles (retour automatique 120 s) ; même borne dans demond/core.py
    const MAX_CYCLE          = 60;
    const DEFAULT_SOCKETPORT = 55010;

    /** Widget personnalisé (équipement Site) : Jeedom autorise le layout custom. */
    public static $_widgetPossibility = ['custom' => true];

    private static $_pluginVersion  = null;
    private static $_commandsConfig = null;

    /**
     * Version lue depuis plugin_info/info.json (source unique de vérité).
     */
    public static function getPluginVersion(): string
    {
        if (self::$_pluginVersion === null) {
            self::$_pluginVersion = '?';
            $infoPath = __DIR__ . '/../../plugin_info/info.json';
            if (is_file($infoPath)) {
                $info = json_decode(file_get_contents($infoPath), true);
                if (is_array($info) && !empty($info['pluginVersion'])) {
                    self::$_pluginVersion = $info['pluginVersion'];
                }
            }
        }
        return self::$_pluginVersion;
    }

    /*     * *********************Configuration************************* */

    /**
     * Configuration globale validée : host, cycle (s), socketport. Lève si l'hôte est absent.
     */
    public static function getApiConfig(): array
    {
        $host       = trim((string) config::byKey('host', 'myfronius', ''));
        $cycle      = (int) config::byKey('cycle', 'myfronius', self::DEFAULT_CYCLE);
        $socketport = (int) config::byKey('socketport', 'myfronius', self::DEFAULT_SOCKETPORT);

        if ($host === '') {
            throw new Exception(__('Adresse de l\'onduleur non configurée (Plugins → MyFronius → Configuration)', __FILE__));
        }
        if ($cycle < self::MIN_CYCLE) {
            $cycle = self::MIN_CYCLE;
        }
        if ($cycle > self::MAX_CYCLE) {
            $cycle = self::MAX_CYCLE;
        }
        if ($socketport <= 0) {
            $socketport = self::DEFAULT_SOCKETPORT;
        }
        return ['host' => $host, 'cycle' => $cycle, 'socketport' => $socketport];
    }

    /**
     * Redémarrage du démon sur changement de config. Jeedom sauvegarde TOUTES les clés du
     * formulaire et appelle postConfig_<clé> même si la valeur est inchangée : on compare donc
     * l'ancienne valeur dans preConfig_* et on ne redémarre qu'une fois par requête, en fin de
     * requête : les clés sont enregistrées une à une dans l'ordre du formulaire, un redémarrage
     * au premier postConfig_* lirait encore l'ancienne valeur des suivantes (port, Modbus…).
     */
    private static $_configChanged   = false;
    private static $_daemonRestarted = false;

    private static function restartDaemonIfRunning(): void
    {
        $info = self::deamon_info();
        if (($info['state'] ?? 'nok') === 'ok') {
            log::add('myfronius', 'info', '[config] paramètre modifié, redémarrage du démon');
            self::deamon_start();
        }
    }

    private static function noteConfigChange(string $key, $value): void
    {
        $previous = config::byKey($key, 'myfronius', '', true);
        if ((string) $previous !== (string) $value) {
            self::$_configChanged = true;
        }
    }

    private static function restartOnceIfChanged(): void
    {
        if (self::$_configChanged && !self::$_daemonRestarted) {
            self::$_daemonRestarted = true;
            // ajax::success() termine par un die() : les fonctions d'arrêt passent après la dernière clé
            register_shutdown_function([__CLASS__, 'restartDaemonAtShutdown']);
        }
    }

    /**
     * Redémarrage en fin de requête pour une borne ajoutée, modifiée ou supprimée : lors d'une
     * désactivation du plugin, le core sauvegarde chaque borne AVANT d'arrêter le démon ; un
     * redémarrage immédiat le relancerait une fois par borne.
     */
    private static function restartAtEndOfRequest(): void
    {
        self::$_configChanged = true;
        self::restartOnceIfChanged();
    }

    /** Annule le redémarrage différé (démon lancé entre-temps avec la nouvelle configuration). */
    public static function cancelPendingRestart(): void
    {
        self::$_configChanged = false;
    }

    /** Redémarrage différé : une exception n'atteindrait plus l'interface, on la journalise. */
    public static function restartDaemonAtShutdown(): void
    {
        if (!self::$_configChanged) {
            return;   // annulé, ou déjà fait
        }
        self::$_configChanged = false;
        try {
            self::restartDaemonIfRunning();
        } catch (Throwable $e) {
            log::add('myfronius', 'error', '[config] redémarrage du démon : ' . $e->getMessage());
        }
    }

    public static function preConfig_host($value)           { self::noteConfigChange('host', $value);           return $value; }
    public static function preConfig_cycle($value)          { self::noteConfigChange('cycle', $value);          return $value; }
    public static function preConfig_socketport($value)     { self::noteConfigChange('socketport', $value);     return $value; }
    public static function preConfig_modbus_enabled($value) { self::noteConfigChange('modbus_enabled', $value); return $value; }
    public static function preConfig_modbus_port($value)    { self::noteConfigChange('modbus_port', $value);    return $value; }
    public static function preConfig_modbus_unit($value)    { self::noteConfigChange('modbus_unit', $value);    return $value; }

    public static function postConfig_host($value): void           { self::restartOnceIfChanged(); }
    public static function postConfig_cycle($value): void          { self::restartOnceIfChanged(); }
    public static function postConfig_socketport($value): void     { self::restartOnceIfChanged(); }
    public static function postConfig_modbus_port($value): void    { self::restartOnceIfChanged(); }
    public static function postConfig_modbus_unit($value): void    { self::restartOnceIfChanged(); }

    /** Activation/désactivation du pilotage : crée ou retire les commandes de la Batterie, puis redémarre. */
    public static function postConfig_modbus_enabled($value): void
    {
        if (self::$_configChanged) {
            $battery = self::byLogicalId('battery', 'myfronius');
            if (is_object($battery)) {
                try {
                    $battery->resyncCommandsFromCache();
                } catch (Exception $e) {
                    log::add('myfronius', 'warning', '[config] commandes de pilotage : ' . $e->getMessage());
                }
            }
            // Boutons de pilotage de la batterie affichés ou retirés sur le widget du Site
            self::refreshSiteWidget();
        }
        self::restartOnceIfChanged();
    }

    /*     * *********************Catalogue************************* */

    public static function loadCommandsConfig(): array
    {
        if (self::$_commandsConfig === null) {
            $path = __DIR__ . '/../config/commands.php';
            self::$_commandsConfig = is_file($path) ? (require $path) : [];
        }
        return self::$_commandsConfig;
    }

    /*     * *********************Synchronisation************************* */

    /**
     * Synchronise les équipements. Démon actif → ordre « resync » (la découverte revient par
     * callback, respecte le cycle de lecture). Démon arrêté → découverte PHP immédiate.
     */
    public static function syncEquipments(): array
    {
        $info = self::deamon_info();
        if (($info['state'] ?? 'nok') === 'ok') {
            self::sendToSocket(['action' => 'resync']);
            log::add('myfronius', 'info', '[sync] resynchronisation demandée au démon');
            return ['queued' => true];
        }
        $cfg = self::getApiConfig();
        $api = new FroniusSolarApi($cfg['host']);
        return self::syncFromDiscovery(FroniusSolarApi::discover($api));
    }

    /**
     * Crée/met à jour les équipements et leurs commandes à partir d'une découverte (spec §4).
     */
    public static function syncFromDiscovery(array $d): array
    {
        config::save('discovery', json_encode($d), 'myfronius');
        $created = 0;
        $updated = 0;
        $list    = [];

        $touch = function (myfronius $eq, bool $isNew) use (&$created, &$updated, &$list) {
            $list[] = $eq->getName();
            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        };

        // Site
        $site = $d['site'] ?? [];
        [$eq, $isNew] = self::ensureEqLogic('site', 'site', self::TYPES['site'], [
            'site_mode'         => $site['mode'] ?? '',
            'powerflow_version' => $site['powerflow_version'] ?? '',
        ]);
        self::syncCommands($eq, 'site', self::availabilityFor('site', $d));
        $touch($eq, $isNew);

        // Batterie
        $battery = $d['battery'] ?? [];
        if (!empty($battery['present'])) {
            [$eq, $isNew] = self::ensureEqLogic('battery', 'battery', self::TYPES['battery'], [
                'device_id'    => $battery['storage_id'] ?? '',
                'manufacturer' => $battery['manufacturer'] ?? '',
                'model'        => $battery['model'] ?? '',
                'serial'       => $battery['serial'] ?? '',
                'storage_ok'   => !empty($battery['storage_ok']) ? 1 : 0,
            ]);
            self::syncCommands($eq, 'battery', self::availabilityFor('battery', $d));
            $touch($eq, $isNew);
        }

        // Onduleurs
        foreach ($d['inverters'] ?? [] as $inv) {
            $id = (string) ($inv['device_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $hardware = [
                'device_id'       => $id,
                'device_type'     => $inv['device_type'] ?? '',
                'manufacturer'    => $inv['manufacturer'] ?? '',
                'model'           => $inv['model'] ?? '',
                'custom_name'     => $inv['custom_name'] ?? '',
                'serial'          => $inv['serial'] ?? '',
                'pv_power'        => $inv['pv_power'] ?? '',
                'firmware'        => $inv['firmware'] ?? ($site['firmware'] ?? ''),
                'mppt'            => implode(',', $inv['mppt'] ?? []),
                'identity_source' => $inv['identity_source'] ?? 'solar_api',
            ];
            // Identité Modbus (SunSpec 1, démon) déjà connue : une découverte sans Modbus (PHP,
            // démon arrêté, ou lecture Modbus en échec) ne la remplace pas par la Solar API
            $existing = self::byLogicalId('inverter_' . $id, 'myfronius');
            if (!isset($inv['identity_source']) && is_object($existing) && $existing->getConfiguration('identity_source') === 'modbus') {
                unset($hardware['manufacturer'], $hardware['model'], $hardware['serial'], $hardware['firmware'], $hardware['identity_source']);
            }
            [$eq, $isNew] = self::ensureEqLogic('inverter_' . $id, 'inverter', self::TYPES['inverter'] . ' ' . $id, $hardware);
            self::syncCommands($eq, 'inverter', self::availabilityFor('inverter', $d, $inv));
            $touch($eq, $isNew);
        }

        // Compteurs
        foreach ($d['meters'] ?? [] as $meter) {
            $id = (string) ($meter['device_id'] ?? '');
            if ($id === '') {
                continue;
            }
            [$eq, $isNew] = self::ensureEqLogic('meter_' . $id, 'meter', self::TYPES['meter'] . ' ' . $id, [
                'device_id'    => $id,
                'manufacturer' => $meter['manufacturer'] ?? '',
                'model'        => $meter['model'] ?? '',
                'serial'       => $meter['serial'] ?? '',
                'location'     => $meter['location'] ?? '',
            ]);
            self::syncCommands($eq, 'meter', self::availabilityFor('meter', $d, $meter));
            $touch($eq, $isNew);
        }

        // Bornes et charges pilotées : créées à la main, mais leurs commandes suivent le catalogue
        foreach (eqLogic::byType('myfronius') as $eq) {
            $type = $eq->getConfiguration('type', '');
            if ($type === 'charger' || $type === 'load') {
                self::syncCommands($eq, $type, self::availabilityFor($type, []));
            }
        }

        log::add('myfronius', 'info', '[sync] terminé — ' . $created . ' créé(s), ' . $updated . ' mis à jour : ' . implode(', ', $list));
        return ['created' => $created, 'updated' => $updated, 'list' => $list];
    }

    /**
     * Crée ou retrouve un eqLogic par logicalId ; met à jour type + infos matérielles.
     * Retourne [eqLogic, isNew]. Le nom est préservé s'il existe déjà.
     */
    private static function ensureEqLogic(string $logicalId, string $type, string $defaultName, array $hardware): array
    {
        $eq    = self::byLogicalId($logicalId, 'myfronius');
        $isNew = !is_object($eq);
        if ($isNew) {
            $eq = new myfronius();
            $eq->setEqType_name('myfronius');
            $eq->setLogicalId($logicalId);
            $eq->setName($defaultName);
            $eq->setIsEnable(1);
            $eq->setIsVisible(1);
            $eq->setConfiguration('cmd_mode', config::byKey('cmd_mode_default', 'myfronius', 'standard'));
            if ($type === 'site') {
                $eq->setCategory('energy', 1);
                // Largeur par défaut du core (230 px) trop étroite pour le widget (min-width 290 px)
                $eq->setDisplay('width', '320px');
            }
        }
        $eq->setConfiguration('type', $type);
        foreach ($hardware as $key => $value) {
            $eq->setConfiguration($key, $value);
        }
        $eq->save();
        log::add('myfronius', 'debug', '[sync] ' . ($isNew ? 'création' : 'mise à jour') . ' ' . $logicalId . ' (' . $eq->getName() . ')');
        return [$eq, $isNew];
    }

    /**
     * Disponibilités servant à évaluer la clé 'requires' du catalogue pour un équipement.
     */
    public static function availabilityFor(string $type, array $d, array $device = []): array
    {
        return [
            'storage_ok'    => !empty($d['battery']['storage_ok']),
            'meter_primary' => isset($d['primary_meter']) && $d['primary_meter'] !== '', // ID « 0 » valide
            'modbus'        => (string) config::byKey('modbus_enabled', 'myfronius', '0') === '1',
            'channels'      => $device['channels'] ?? [],
            'mppt'          => $device['mppt'] ?? [],
        ];
    }

    /** Évalue une condition 'requires' (null | storage_ok | meter_primary | modbus | channel:X | mppt:n). */
    public static function requiresMet($requires, array $availability): bool
    {
        if ($requires === null || $requires === '') {
            return true;
        }
        if ($requires === 'storage_ok') {
            return !empty($availability['storage_ok']);
        }
        if ($requires === 'modbus') {
            return !empty($availability['modbus']);
        }
        if ($requires === 'meter_primary') {
            return !empty($availability['meter_primary']);
        }
        if (strpos($requires, 'channel:') === 0) {
            return in_array(substr($requires, 8), $availability['channels'] ?? [], true);
        }
        if (strpos($requires, 'mppt:') === 0) {
            return in_array((int) substr($requires, 5), array_map('intval', $availability['mppt'] ?? []), true);
        }
        return false;
    }

    /** Types génériques invalides (absents du core) posés par les premières versions, à retirer. */
    const LEGACY_GENERIC_TYPES = ['PRODUCTION', 'DAILY_PRODUCTION', 'DAILY_CONSUMPTION'];

    /**
     * Crée les commandes manquantes d'un équipement selon le catalogue, son mode et la
     * disponibilité des canaux. Préserve nom, visibilité, historisation et ordre des commandes
     * existantes. En mode standard, supprime les commandes expert existantes ; pilotage Modbus
     * désactivé, supprime les commandes de pilotage (requires 'modbus').
     */
    /**
     * Réglages d'historique par défaut d'une commande info NOUVELLE (catalogue : historized, repeat,
     * smooth, purge). Ne jamais appeler sur une commande existante : ses réglages sont ceux de
     * l'utilisateur (voir migrateHistoryDefaults, qui ne fait qu'activer).
     */
    public static function applyHistoryDefaults(cmd $cmd, array $def): void
    {
        if (($def['type'] ?? 'info') !== 'info') {
            return;
        }
        $cmd->setIsHistorized(!empty($def['historized']) ? 1 : 0);
        if (($def['repeat'] ?? '') !== '') {
            $cmd->setConfiguration('repeatEventManagement', $def['repeat']);
        }
        if (!empty($def['historized'])) {
            self::applyHistorySettings($cmd, $def);
        }
    }

    /** Lissage et purge du catalogue (commande dont l'historisation vient d'être activée). */
    private static function applyHistorySettings(cmd $cmd, array $def): void
    {
        if (($def['smooth'] ?? '') !== '') {
            $cmd->setConfiguration('historizeMode', $def['smooth']);
        }
        if (($def['purge'] ?? '') !== '') {
            $cmd->setConfiguration('historyPurge', $def['purge']);
        }
    }

    public static function syncCommands(myfronius $eq, string $type, array $availability): void
    {
        $catalog = self::loadCommandsConfig()[$type] ?? [];
        $cmdMode = $eq->getConfiguration('cmd_mode', 'standard');

        $existing = [];
        $maxOrder = 0;
        foreach (($eq->getCmd() ?: []) as $cmd) {
            $existing[$cmd->getLogicalId()] = $cmd;
            $maxOrder = max($maxOrder, (int) $cmd->getOrder());
        }

        $createdCount = 0;
        foreach ($catalog as $logicalId => $def) {
            $isExpert = !empty($def['expert']);
            if ($isExpert && $cmdMode !== 'expert') {
                if (isset($existing[$logicalId])) {
                    $existing[$logicalId]->remove();
                    log::add('myfronius', 'debug', '[syncCmd] ' . $eq->getName() . ' :: suppression (expert) ' . $logicalId);
                }
                continue;
            }
            if (!self::requiresMet($def['requires'] ?? null, $availability)) {
                // Pilotage Modbus désactivé (choix de configuration) : ses commandes sont retirées.
                // Les autres conditions (découverte dégradée) gardent les commandes et leur historique.
                if (($def['requires'] ?? null) === 'modbus' && isset($existing[$logicalId])) {
                    $existing[$logicalId]->remove();
                    unset($existing[$logicalId]);
                    log::add('myfronius', 'debug', '[syncCmd] ' . $eq->getName() . ' :: suppression (pilotage désactivé) ' . $logicalId);
                }
                continue;
            }

            $cmd   = $existing[$logicalId] ?? null;
            $isNew = !is_object($cmd);
            if ($isNew) {
                $cmd = new myfroniusCmd();
                $cmd->setEqLogic_id($eq->getId());
                $cmd->setLogicalId($logicalId);
                $cmd->setName($def['name']);
                $cmd->setIsVisible(!empty($def['visible']) ? 1 : 0);
                self::applyHistoryDefaults($cmd, $def);
                $cmd->setOrder(++$maxOrder);
                $createdCount++;
            }
            // Historisation, répétition et purge : posées à la création seulement. Une commande
            // existante garde les réglages de l'utilisateur (les chaînes s'historisent dans le core 4.4,
            // en frise chronologique)
            $cmd->setType($def['type'] ?? 'info');
            $cmd->setSubType($def['subType']);
            if (($def['unit'] ?? '') !== '') {
                $cmd->setUnite($def['unit']);
            }
            if (!empty($def['generic'])) {
                $cmd->setGeneric_type($def['generic']);
            } elseif (in_array((string) $cmd->getGeneric_type(), self::LEGACY_GENERIC_TYPES, true)) {
                $cmd->setGeneric_type(null);   // types inexistants dans le core, posés par les premières versions
            }
            if (!empty($def['listValue'])) {
                $cmd->setConfiguration('listValue', $def['listValue']);
            }
            // Action liée à une commande info (sélecteur positionné sur la valeur courante)
            if (!empty($def['value']) && isset($existing[$def['value']]) && $existing[$def['value']]->getId()) {
                $cmd->setValue($existing[$def['value']]->getId());
            }
            if (isset($def['min'])) {
                $cmd->setConfiguration('minValue', $def['min']);
            }
            if (isset($def['max'])) {
                $cmd->setConfiguration('maxValue', $def['max']);
            }
            try {
                $cmd->save();
                $existing[$logicalId] = $cmd;   // disponible pour les actions liées créées ensuite
            } catch (Exception $e) {
                // Typiquement : nom déjà utilisé par une commande renommée par l'utilisateur.
                // On continue avec les autres commandes plutôt que d'interrompre la synchro.
                if ($isNew) {
                    $createdCount--;
                }
                log::add('myfronius', 'warning', '[syncCmd] ' . $eq->getName() . ' :: ' . $logicalId . ' non sauvegardée : ' . $e->getMessage());
            }
        }
        log::add('myfronius', 'info', '[syncCmd] ' . $eq->getName() . ' (' . $type . ', ' . $cmdMode . ') : ' . $createdCount . ' commande(s) créée(s)');
    }

    /**
     * Supprime toutes les commandes (sauf « Rafraîchir ») et les recrée depuis la dernière
     * découverte connue. Le cache est vérifié AVANT toute suppression (sinon perte d'historique
     * sans possibilité de recréer). $cmdMode : mode à appliquer (sélection du formulaire), sinon
     * celui déjà enregistré.
     */
    public static function regenerateCommands(int $eqLogicId, ?string $cmdMode = null): void
    {
        $eq = self::byId($eqLogicId);
        if (!is_object($eq) || $eq->getEqType_name() !== 'myfronius') {
            throw new Exception(__('Équipement introuvable', __FILE__) . ' : ' . $eqLogicId);
        }
        [$d, $device] = $eq->discoveryContext();   // lève si le cache est absent ou incomplet
        $type         = $eq->getConfiguration('type', '');
        $availability = self::availabilityFor($type, $d, $device);

        // Découverte dégradée (stockage en erreur, canal manquant) : refus plutôt que de supprimer
        // des commandes et leur historique sans pouvoir les recréer
        $existingNames = [];
        foreach (($eq->getCmd() ?: []) as $cmd) {
            $existingNames[$cmd->getLogicalId()] = $cmd->getName();
        }
        $lost = self::commandsLostOnRegenerate(self::loadCommandsConfig()[$type] ?? [], array_keys($existingNames),
                                               $cmdMode ?? $eq->getConfiguration('cmd_mode', 'standard'), $availability);
        if (!empty($lost)) {
            throw new Exception(__('Ces commandes ne seraient pas recréées (indisponibles dans la dernière découverte) : relancez « Synchroniser » avant de régénérer', __FILE__)
                                . ' : ' . implode(', ', array_map(function ($id) use ($existingNames) { return $existingNames[$id]; }, $lost)));
        }

        if ($cmdMode !== null && $cmdMode !== $eq->getConfiguration('cmd_mode', 'standard')) {
            $eq->setConfiguration('cmd_mode', $cmdMode);
            $eq->save();   // postUpdate resynchronise déjà, la régénération ci-dessous repart de zéro
        }
        foreach (($eq->getCmd() ?: []) as $cmd) {
            if ($cmd->getLogicalId() !== 'refresh') {
                $cmd->remove();
            }
        }
        self::syncCommands($eq, $type, $availability);
        log::add('myfronius', 'info', '[regen] ' . $eq->getName() . ' : commandes recréées');
    }

    /**
     * Commandes existantes du catalogue que la régénération supprimerait sans les recréer :
     * condition 'requires' non satisfaite par la découverte. Exclues : commandes expert hors mode
     * expert et pilotage Modbus désactivé (retraits voulus par la configuration).
     */
    public static function commandsLostOnRegenerate(array $catalog, array $existingLogicalIds, string $cmdMode, array $availability): array
    {
        $lost = [];
        foreach ($existingLogicalIds as $logicalId) {
            $def = $catalog[$logicalId] ?? null;
            if ($def === null || (!empty($def['expert']) && $cmdMode !== 'expert') || ($def['requires'] ?? null) === 'modbus') {
                continue;
            }
            if (!self::requiresMet($def['requires'] ?? null, $availability)) {
                $lost[] = $logicalId;
            }
        }
        return $lost;
    }

    /**
     * Découverte en cache + description de l'appareil de cet équipement : [découverte, appareil].
     * Lève si le cache est absent, ou si l'appareil (onduleur/meter) n'y figure pas ou n'avait
     * répondu à aucun canal lors de la découverte (sinon on recréerait un équipement vide).
     * Batterie ou canaux dégradés : vérifiés par regenerateCommands (commandsLostOnRegenerate).
     */
    public function discoveryContext(): array
    {
        if (in_array($this->getConfiguration('type', ''), ['charger', 'load'], true)) {
            return [[], []];   // bornes et charges ne dépendent pas de la découverte Solar API
        }
        // config::byKey décode lui-même le JSON stocké ; on accepte les deux formes par sécurité.
        $d = config::byKey('discovery', 'myfronius', '');
        if (is_string($d)) {
            $d = json_decode($d, true);
        }
        if (!is_array($d) || empty($d)) {
            throw new Exception(__('Aucune découverte en cache : lancez « Synchroniser »', __FILE__));
        }
        $type    = $this->getConfiguration('type', '');
        $id      = (string) $this->getConfiguration('device_id', '');
        $device  = [];
        $listKey = $type === 'inverter' ? 'inverters' : ($type === 'meter' ? 'meters' : null);
        if ($listKey !== null) {
            foreach ($d[$listKey] ?? [] as $candidate) {
                if ((string) ($candidate['device_id'] ?? '') === $id) {
                    $device = $candidate;
                    break;
                }
            }
            if (empty($device) || empty($device['channels'])) {
                throw new Exception(__('Appareil absent ou muet dans la dernière découverte : relancez « Synchroniser » avant de régénérer', __FILE__));
            }
        }
        return [$d, $device];
    }

    /** Recrée/complète les commandes de cet équipement depuis la découverte mise en cache. */
    public function resyncCommandsFromCache(): void
    {
        [$d, $device] = $this->discoveryContext();
        $type = $this->getConfiguration('type', '');
        self::syncCommands($this, $type, self::availabilityFor($type, $d, $device));
    }

    /*     * *********************Bornes Wattpilot (spec §11)************************* */

    /**
     * Crée un équipement borne (type charger) vide de configuration réseau : l'utilisateur
     * renseigne ensuite adresse et mot de passe dans la page de l'équipement.
     */
    public static function createCharger(): myfronius
    {
        $index = 1;
        while (is_object(self::byLogicalId('charger_' . $index, 'myfronius'))) {
            $index++;
        }
        $eq = new myfronius();
        $eq->setEqType_name('myfronius');
        $eq->setLogicalId('charger_' . $index);
        $eq->setName(self::TYPES['charger'] . ' ' . $index);
        $eq->setIsEnable(1);
        $eq->setIsVisible(1);
        $eq->setConfiguration('type', 'charger');
        $eq->setConfiguration('device_id', (string) $index);
        $eq->setConfiguration('cmd_mode', config::byKey('cmd_mode_default', 'myfronius', 'standard'));
        $eq->setCategory('energy', 1);
        $eq->setDisplay('width', '320px');   // widget Borne : largeur minimale confortable (défaut du core : 230 px)
        $eq->save();
        self::syncCommands($eq, 'charger', self::availabilityFor('charger', []));
        self::refreshSiteWidget();   // tuile ajoutée, une fois ses commandes créées
        log::add('myfronius', 'info', '[charger] borne créée : ' . $eq->getName());
        return $eq;
    }

    /**
     * Crée un équipement charge pilotée (type load) ; l'utilisateur renseigne ensuite les commandes
     * externes et la puissance nominale (spec §13).
     */
    public static function createLoad(): myfronius
    {
        $index = 1;
        while (is_object(self::byLogicalId('load_' . $index, 'myfronius'))) {
            $index++;
        }
        $eq = new myfronius();
        $eq->setEqType_name('myfronius');
        $eq->setLogicalId('load_' . $index);
        $eq->setName(self::TYPES['load'] . ' ' . $index);
        $eq->setIsEnable(1);
        $eq->setIsVisible(1);
        $eq->setConfiguration('type', 'load');
        $eq->setConfiguration('device_id', (string) $index);
        $eq->setConfiguration('cmd_mode', 'standard');
        foreach (myfroniusLoadManager::DEFAULTS as $key => $value) {
            $eq->setConfiguration($key, $value);
        }
        $eq->setCategory('energy', 1);
        $eq->save();
        self::syncCommands($eq, 'load', self::availabilityFor('load', []));
        self::refreshSiteWidget();   // tuile ajoutée, une fois ses commandes créées
        log::add('myfronius', 'info', '[load] charge créée : ' . $eq->getName());
        return $eq;
    }

    /** Charges pilotées activées, par priorité (pour le widget). */
    public static function loadList(): array
    {
        $loads = [];
        foreach (eqLogic::byType('myfronius') as $eq) {
            if ($eq->getConfiguration('type', '') === 'load' && $eq->getIsEnable()) {
                $loads[] = $eq;
            }
        }
        usort($loads, function ($a, $b) {
            return (int) $a->getConfiguration('priority', 10) <=> (int) $b->getConfiguration('priority', 10);
        });
        return $loads;
    }

    /** Bornes activées et configurées : [['logical_id','host','password'], …] pour le démon. */
    public static function chargerList(): array
    {
        $list = [];
        foreach (eqLogic::byType('myfronius') as $eq) {
            if ($eq->getConfiguration('type', '') !== 'charger' || !$eq->getIsEnable()) {
                continue;
            }
            $host     = trim((string) $eq->getConfiguration('host', ''));
            $password = (string) $eq->getConfiguration('password', '');
            if ($host === '' || $password === '') {
                continue;
            }
            $list[] = ['logical_id' => $eq->getLogicalId(), 'host' => $host, 'password' => $password];
        }
        return $list;
    }

    /*     * *********************Hooks eqLogic************************* */

    private $_previousCmdMode  = null;
    private $_previousCapacity = null;
    private $_previousCharger  = null;

    public function preInsert() {}
    public function postInsert() {}
    public function preRemove() {}

    /**
     * Mot de passe des bornes chiffré en base et dans les sauvegardes (hooks appelés par le core
     * autour de chaque écriture / lecture ; une valeur encore en clair est lue telle quelle).
     */
    public function encrypt()
    {
        $password = (string) $this->getConfiguration('password', '');
        if ($password !== '') {
            $this->setConfiguration('password', utils::encrypt($password));
        }
    }

    public function decrypt()
    {
        $password = (string) $this->getConfiguration('password', '');
        if ($password !== '') {
            // null si la clé de chiffrement a changé (restauration sur une autre box) : à ressaisir
            $this->setConfiguration('password', (string) utils::decrypt($password));
        }
    }

    /**
     * Normalise les clés d'identité renvoyées par le formulaire (défense : un type altéré par
     * l'UI, par exemple avec des espaces, ferait disparaître l'équipement de la liste et
     * désactiverait le widget).
     */
    public function preSave()
    {
        foreach (['type', 'device_id', 'manufacturer', 'model', 'serial', 'device_type', 'mppt', 'location', 'host'] as $key) {
            $value = $this->getConfiguration($key, null);
            if (is_string($value)) {
                $this->setConfiguration($key, trim($value));
            }
        }
        // Charge pilotée : Jeedom n'applique pas les min / max des champs, bornage ici (marge 50–500 %,
        // délais, tolérance et nominale ≥ 0, priorités ≥ 1) pour que la page affiche la valeur retenue
        if ($this->getConfiguration('type', '') === 'load') {
            $raw = [];
            foreach (array_keys(myfroniusLoadManager::DEFAULTS) as $key) {
                $raw[$key] = $this->getConfiguration($key, myfroniusLoadManager::DEFAULTS[$key]);
            }
            foreach (myfroniusLoadManager::normalizeConfig($raw) as $key => $value) {
                if ($value === $raw[$key]) {
                    continue;
                }
                $this->setConfiguration($key, $value);
                if (trim((string) $raw[$key]) !== (string) $value) {
                    log::add('myfronius', 'warning', '[load] ' . $this->getName() . ' : ' . $key . ' = « ' . $raw[$key]
                        . ' » invalide ou hors plage, remplacé par ' . ($value === '' ? 'vide' : $value));
                }
            }
        }
    }
    public function preUpdate()
    {
        // Lecture directe en DB : l'objet en mémoire porte déjà les nouvelles valeurs.
        $row = DB::Prepare('SELECT configuration, isEnable FROM `eqLogic` WHERE id = :id', [':id' => $this->getId()], DB::FETCH_TYPE_ROW);
        if (is_array($row) && isset($row['configuration'])) {
            $cfg = json_decode($row['configuration'], true) ?: [];
            $this->_previousCmdMode  = $cfg['cmd_mode'] ?? 'standard';
            $this->_previousCapacity = (string) ($cfg['capacity_wh'] ?? '');
            // Mot de passe chiffré en base (encrypt()) : comparé en clair
            $this->_previousCharger  = (int) ($row['isEnable'] ?? 0) . '|' . (string) ($cfg['host'] ?? '') . '|' . (string) utils::decrypt((string) ($cfg['password'] ?? ''));
        }
    }

    public function postUpdate()
    {
        $newMode = $this->getConfiguration('cmd_mode', 'standard');
        if ($this->_previousCmdMode !== null && $this->_previousCmdMode !== $newMode) {
            try {
                $this->resyncCommandsFromCache();
                log::add('myfronius', 'info', '[postUpdate] mode ' . $this->_previousCmdMode . ' → ' . $newMode . ' : commandes resynchronisées pour ' . $this->getName());
            } catch (Exception $e) {
                log::add('myfronius', 'warning', '[postUpdate] ' . $e->getMessage());
            }
        }
        // La capacité batterie est passée au démon en argument : le redémarrer si elle change.
        $newCapacity = (string) $this->getConfiguration('capacity_wh', '');
        if ($this->getConfiguration('type', '') === 'battery'
            && $this->_previousCapacity !== null && $this->_previousCapacity !== $newCapacity) {
            log::add('myfronius', 'info', '[postUpdate] capacité batterie modifiée (' . $newCapacity . ' Wh)');
            self::restartDaemonIfRunning();
        }
        // Activation, adresse ou mot de passe d'une borne : la liste des bornes (activées seules)
        // est lue au démarrage du démon.
        $newCharger = (int) $this->getIsEnable() . '|' . (string) $this->getConfiguration('host', '') . '|' . (string) $this->getConfiguration('password', '');
        if ($this->getConfiguration('type', '') === 'charger'
            && $this->_previousCharger !== null && $this->_previousCharger !== $newCharger) {
            log::add('myfronius', 'info', '[postUpdate] connexion ou activation de la borne ' . $this->getName() . ' modifiée, redémarrage du démon en fin de requête');
            self::restartAtEndOfRequest();
        }
    }

    public function postRemove()
    {
        if ($this->getConfiguration('type', '') === 'charger') {
            self::restartAtEndOfRequest();
        }
        $this->refreshSiteWidgetIfTile();
    }

    /**
     * Le widget du Site affiche des tuiles tirées d'autres équipements (charges, bornes, batterie) :
     * leur ajout, renommage, activation ou suppression doit le redessiner, sinon il garde l'ancien
     * état jusqu'au rechargement de la page.
     */
    private function refreshSiteWidgetIfTile(): void
    {
        if (in_array($this->getConfiguration('type', ''), ['load', 'charger', 'battery'], true)) {
            self::refreshSiteWidget();
        }
    }

    private static function refreshSiteWidget(): void
    {
        $site = self::byLogicalId('site', 'myfronius');
        if (is_object($site)) {
            $site->refreshWidget();
        }
    }

    /**
     * Commande « Rafraîchir » (pattern Virtual) : demande une resynchronisation au démon.
     */
    public function postSave()
    {
        $refresh = $this->getCmd(null, 'refresh');
        if (!is_object($refresh)) {
            $refresh = cmd::byEqLogicIdCmdName($this->getId(), __('Rafraîchir', __FILE__));
        }
        if (!is_object($refresh)) {
            $refresh = new myfroniusCmd();
            $refresh->setLogicalId('refresh');
            $refresh->setName(__('Rafraîchir', __FILE__));
            $refresh->setIsVisible(1);
        }
        $refresh->setType('action');
        $refresh->setSubType('other');
        $refresh->setEqLogic_id($this->getId());
        $refresh->save();
        $this->refreshSiteWidgetIfTile();
    }

    /*     * *********************Widget Site (spec §9)************************* */

    /**
     * Widget personnalisé pour l'équipement Site ; les autres types gardent le rendu standard.
     */
    public function toHtml($_version = 'dashboard')
    {
        $type = $this->getConfiguration('type', '');
        if ($type !== 'site' && $type !== 'charger') {
            return parent::toHtml($_version);
        }
        // Sans cache : le widget Site référence des commandes d'autres équipements (batterie, bornes,
        // charges) créées après le dernier rendu, que son cache ne verrait pas ; la borne référence
        // ses réglages, créés à la synchronisation suivant une mise à jour.
        $replace = $this->preToHtml($_version, [], true);
        if (!is_array($replace)) {
            return $replace;
        }
        $version  = jeedom::versionAlias($_version);
        $template = getTemplate('core', $version, $type, 'myfronius');
        if ($template === '') {
            return parent::toHtml($_version);
        }
        // Corps partagé dashboard / mobile, inséré avant le remplacement des placeholders
        $template = str_replace('#mfr_body#', self::widgetBody($type === 'site' ? 'body' : 'charger'), $template);
        $replace  = array_merge($replace, $type === 'site' ? $this->widgetReplace() : $this->chargerWidgetReplace());
        // Rempli d'ordinaire par eqLogic::toHtml, non appelé ici (disposition propre au widget)
        $replace['#eqLogic_class#'] = 'eqLogic_layout_default';
        return $this->postToHtml($_version, template_replace($replace, $template));
    }

    /** Corps d'un widget (tuiles + script), commun au dashboard et au mobile : body (Site) ou charger. */
    private static function widgetBody(string $name = 'body'): string
    {
        return (string) file_get_contents(__DIR__ . '/../template/widget/' . $name . '.html');
    }

    /**
     * Placeholders du widget Borne (spec §11.4) : références des infos (id + valeur), ids des
     * actions (mode_set avec code, forçages, réglages) ; bloc réglages masqué si aucune action de
     * réglage n'existe (bornes créées avant la mise à jour, pas encore resynchronisées).
     */
    private function chargerWidgetReplace(): array
    {
        $data = ['cmds' => [], 'actions' => []];
        $this->widgetAddCmds($data, $this, [
            'p', 'car', 'mode_code', 'amp', 'ama', 'acu', 'phases', 'frc', 'model_status', 'model_status_text',
            'e_session', 'fte', 'trip_km', 'ftt', 'cco', 'connected',
        ]);
        $settings = 0;
        foreach (['mode_set', 'force_neutral', 'force_on', 'force_off',
                  'amp_set', 'trip_energy_set', 'trip_km_set', 'trip_time_set', 'cco_set'] as $logicalId) {
            $action = $this->getCmd('action', $logicalId);
            if (is_object($action)) {
                $data['actions'][$logicalId] = (int) $action->getId();
                $settings += substr($logicalId, -4) === '_set' && $logicalId !== 'mode_set' ? 1 : 0;
            }
        }
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return [
            '#mfr_data#'           => str_replace('#', '\\u0023', $json),
            '#mfc_settings_class#' => $settings === 0 ? 'mfc-hidden' : '',
            '#mfr_css_version#'    => (string) @filemtime(__DIR__ . '/../template/dashboard/site.css'),
        ];
    }

    /**
     * Données du widget Site : références de commandes (id + valeur), tuiles dynamiques (charges,
     * bornes, batterie) et nombre de pilotables.
     * Retour : ['data' => [...], 'consumers' => html, 'control' => html, 'control_count' => int]
     */
    private function widgetData(): array
    {
        $data = ['cmds' => [], 'consumers' => [], 'chargers' => [], 'battery' => false];
        $this->widgetAddCmds($data, $this, [
            'p_pv', 'p_load', 'p_load_ex_ev', 'p_grid', 'p_akku', 'surplus', 'surplus_batt', 'rel_autonomy',
            'rel_selfconsumption', 'e_day_pv', 'e_day_load', 'e_day_export', 'mode', 'backup_mode', 'comm_ok', 'last_update',
        ]);
        // Fraîcheur des données (pastille « données figées ») : âge du dernier lot du site, posé par
        // jeedomDaemon.php, calculé ici pour ne pas dépendre du fuseau ni de l'horloge du navigateur
        $lastSite = myfroniusLoadManager::lastSite();
        $data['stale_after'] = myfroniusLoadManager::staleAfter();
        $data['age'] = $lastSite > 0 ? max(0, time() - $lastSite) : null;

        // Charges pilotées (spec §13) : une tuile par charge activée, + reste maison
        $consumersHtml = '';
        foreach (self::loadList() as $i => $load) {
            $key = 'ld' . $i;
            $data['loads'][] = ['name' => $load->getName()];
            $this->widgetAddCmds($data, $load, ['p', 'state', 'mode'], $key . '_');
            $prev = $load->getCmd('action', 'mode_prev');
            $next = $load->getCmd('action', 'mode_next');
            $controllable = is_object(myfroniusLoadManager::externalCmd($load->getConfiguration('cmd_on', ''))) && is_object(myfroniusLoadManager::externalCmd($load->getConfiguration('cmd_off', '')));
            $modeHtml = '';
            if ($controllable && is_object($prev) && is_object($next)) {
                $modeHtml = '<div class="mfr-mode"><span class="mfr-btn" data-cmd="' . $prev->getId() . '">&minus;</span>'
                    . '<span class="m" data-k="' . $key . '_mode">—</span><span class="mfr-btn" data-cmd="' . $next->getId() . '">+</span></div>';
            }
            $consumersHtml .= '<div class="mfr-item"><div class="mfr-flow mfr-v" data-f="' . $key . '" style="--mfr-c:var(--mfr-cons)"></div>'
                . '<div class="mfr-tile mfr-small mfr-load" data-ld="' . $i . '"><span class="s"><span class="mfr-dot" data-k="' . $key . '_dot"></span>'
                . htmlspecialchars($load->getName()) . '</span><span class="v" data-k="' . $key . '_p">—</span>' . $modeHtml . '</div></div>';
        }
        if ($consumersHtml !== '') {
            $consumersHtml .= '<div class="mfr-item"><div class="mfr-flow mfr-v"></div>'
                . '<div class="mfr-tile mfr-small mfr-rest"><span class="s">' . __('Reste maison', __FILE__) . '</span>'
                . '<span class="v" data-k="rest">—</span></div></div>';
        }

        // Pilotables : bornes (type charger, phase 3) de part et d'autre de la batterie
        $chargers = [];
        foreach (eqLogic::byType('myfronius') as $eq) {
            if ($eq->getConfiguration('type', '') === 'charger' && $eq->getIsEnable()) {
                $chargers[] = $eq;
            }
        }
        $battery = self::byLogicalId('battery', 'myfronius');
        $hasBattery = is_object($battery) && $battery->getIsEnable();
        $chargerTiles = [];
        foreach ($chargers as $i => $eq) {
            $key = 'ch' . $i;
            $this->widgetAddCmds($data, $eq, ['p', 'car', 'mode', 'connected'], $key . '_');
            $prev = $eq->getCmd('action', 'mode_prev');
            $next = $eq->getCmd('action', 'mode_next');
            $data['chargers'][] = ['name' => $eq->getName()];
            $chargerTiles[] = '<div class="mfr-item"><div class="mfr-flow mfr-v" data-f="' . $key . '" style="--mfr-c:var(--mfr-ev)"></div>'
                . '<div class="mfr-tile mfr-ctrl mfr-charger" data-ch="' . $i . '">'
                . '<span class="s">' . htmlspecialchars($eq->getName()) . ' · <span data-k="' . $key . '_car"></span></span>'
                . '<span class="v" data-k="' . $key . '_p">—</span>'
                . '<div class="mfr-mode">'
                . (is_object($prev) ? '<span class="mfr-btn" data-cmd="' . $prev->getId() . '">&minus;</span>' : '')
                . '<span class="m" data-k="' . $key . '_mode">—</span>'
                . (is_object($next) ? '<span class="mfr-btn" data-cmd="' . $next->getId() . '">+</span>' : '')
                . '</div></div></div>';
        }
        $batteryTile = '';
        if ($hasBattery) {
            $data['battery'] = true;
            $data['cmds']['soc']      = $this->widgetCmdRef($battery->getCmd('info', 'soc'));
            $data['cmds']['bat_mode'] = $this->widgetCmdRef($battery->getCmd('info', 'mode'));
            // Pilotage Modbus (spec §12) : ligne − mode + si les actions existent
            $ctrlHtml = '';
            $ctrlIds  = [];
            foreach (['auto' => 'ctrl_auto', 'hold' => 'ctrl_hold', 'no_charge' => 'ctrl_no_charge'] as $mode => $logicalId) {
                $action = $battery->getCmd('action', $logicalId);
                if (is_object($action)) {
                    $ctrlIds[$mode] = (int) $action->getId();
                }
            }
            if (count($ctrlIds) === 3 && (string) config::byKey('modbus_enabled', 'myfronius', '0') === '1') {
                $data['battery_ctrl'] = $ctrlIds;
                $data['cmds']['ctrl_mode']   = $this->widgetCmdRef($battery->getCmd('info', 'ctrl_mode'));
                $data['cmds']['ctrl_active'] = $this->widgetCmdRef($battery->getCmd('info', 'ctrl_active'));
                $ctrlHtml = '<div class="mfr-mode"><span class="mfr-btn" data-bctl="prev">&minus;</span>'
                    . '<span class="m" data-k="ctrl_label">—</span><span class="mfr-btn" data-bctl="next">+</span></div>';
            }
            $batteryTile = '<div class="mfr-item"><div class="mfr-flow mfr-v" data-f="bat" style="--mfr-c:var(--mfr-bat)"></div>'
                . '<div class="mfr-tile mfr-ctrl mfr-battery"><span class="s">' . __('Batterie', __FILE__) . '</span>'
                . '<span class="v" data-k="soc">—</span><div class="mfr-bar"><div class="mfr-bar-fill" data-k="socbar"></div></div>'
                . '<span class="s"><span data-k="p_bat">—</span> W · <span data-k="bat_mode">—</span></span>' . $ctrlHtml . '</div></div>';
        }
        $half = (int) ceil(count($chargerTiles) / 2);
        $controlHtml = implode('', array_slice($chargerTiles, 0, $half)) . $batteryTile . implode('', array_slice($chargerTiles, $half));
        $controlCount = count($chargerTiles) + ($hasBattery ? 1 : 0);

        return ['data' => $data, 'consumers' => $consumersHtml, 'control' => $controlHtml, 'control_count' => $controlCount];
    }

    /** Placeholders du corps du widget (données, tuiles dynamiques, rangées masquées). */
    private function widgetReplace(): array
    {
        $w = $this->widgetData();
        // Clé absente ou vide = afficher (défaut) ; '0' = masquer
        $cls = function (string $key): string {
            return (string) $this->getConfiguration($key, '') === '0' ? 'mfr-hidden' : '';
        };
        $json = json_encode($w['data'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return [
            '#mfr_data#'              => str_replace('#', '\\u0023', $json),   // '#' est réservé aux placeholders
            '#mfr_consumers#'         => $w['consumers'],
            '#mfr_consumers_class#'   => $w['consumers'] === '' ? 'mfr-hidden' : '',
            '#mfr_control#'           => $w['control'],
            '#mfr_control_class#'     => $w['control_count'] === 0 ? 'mfr-hidden' : '',
            '#mfr_control_bus_class#' => $w['control_count'] === 0 ? 'mfr-hidden' : ($w['control_count'] === 1 ? 'single' : ''),
            '#mfr_kpi_class#'         => $cls('widget_kpi'),
            '#mfr_day_class#'         => $cls('widget_day'),
            '#mfr_chips_class#'       => $cls('widget_chips'),
            // Feuille servie par <link> sans include_file : date du fichier en paramètre pour
            // qu'une mise à jour du plugin ne garde pas l'ancienne en cache navigateur
            '#mfr_css_version#'       => (string) @filemtime(__DIR__ . '/../template/dashboard/site.css'),
        ];
    }

    /** Ajoute au tableau widget les références (id + valeur courante) des commandes info listées. */
    private function widgetAddCmds(array &$data, eqLogic $eq, array $logicalIds, string $prefix = ''): void
    {
        foreach ($logicalIds as $logicalId) {
            $data['cmds'][$prefix . $logicalId] = $this->widgetCmdRef($eq->getCmd('info', $logicalId));
        }
    }

    /** ['id' => int|null, 'value' => mixed|null] pour une commande (objet, id numérique ou null). */
    private function widgetCmdRef($cmd): array
    {
        if (is_numeric($cmd)) {
            $cmd = cmd::byId((int) $cmd);
        }
        if (!is_object($cmd)) {
            return ['id' => null, 'value' => null];
        }
        return ['id' => (int) $cmd->getId(), 'value' => $cmd->execCmd()];
    }

    /*     * *********************Démon************************* */

    private static function pidFile(): string
    {
        return jeedom::getTmpFolder('myfronius') . '/demond.pid';
    }

    /** Mode API réglé pour MyFronius (enable, whiteip, localhost, disable). */
    private static function apiMode(): string
    {
        return (string) config::byKey('api::myfronius::mode', 'core', 'enable');
    }

    /**
     * Clé API du plugin (portée limitée), jamais la clé maître du core. Le mode courant est passé à
     * jeedom::getApiKey(), qui sinon remet un mode « disable » à « enable » : le réglage
     * « Désactivé » de l'utilisateur est conservé (il coupe le démon, cf. deamon_info).
     */
    private static function apiKey(): string
    {
        return (string) jeedom::getApiKey('myfronius', self::apiMode());
    }

    /** Liste des bornes passée au démon (mots de passe en clair) : consommée par le démon. */
    public static function chargersFile(): string
    {
        return jeedom::getTmpFolder('myfronius') . '/chargers.json';
    }

    public static function deamon_info(): array
    {
        $return = ['log' => 'myfronius_daemon', 'state' => 'nok', 'launchable' => 'ok'];
        $pidFile = self::pidFile();
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid !== '' && posix_getsid((int) $pid) !== false) {
                $return['state'] = 'ok';
            }
        }
        try {
            self::getApiConfig();
        } catch (Exception $e) {
            $return['launchable']         = 'nok';
            $return['launchable_message'] = $e->getMessage();
        }
        // API MyFronius « Désactivé » : callback refusé (403), le démon ne transmettrait rien
        if ($return['launchable'] === 'ok' && self::apiMode() === 'disable') {
            $return['launchable']         = 'nok';
            $return['launchable_message'] = __('API MyFronius désactivée (Réglages → Système → Configuration → API) : le démon ne peut pas transmettre ses valeurs', __FILE__);
        }
        return $return;
    }

    /**
     * Migration unique (version 1, 2026-09-26) : ACTIVE l'historisation des commandes existantes de la
     * liste du catalogue qui ne l'ont pas, et complète leurs réglages (répétition, lissage, purge)
     * restés vides. Ne désactive jamais rien — dans le core, une commande qui cesse d'être historisée
     * perd tout son historique à l'archivage suivant — et ne remplace aucune valeur choisie par
     * l'utilisateur ; les commandes hors liste ne sont pas touchées. Appelée par la mise à jour du plugin (install.php) et au démarrage du démon,
     * pour un déploiement par copie de fichiers qui ne passe pas par la mise à jour.
     */
    public static function migrateHistoryDefaults(): void
    {
        if ((int) config::byKey('history_defaults', 'myfronius', 0) >= 1) {
            return;
        }
        $catalog = self::loadCommandsConfig();
        $changed = 0;
        foreach (eqLogic::byType('myfronius') as $eq) {
            $defs = $catalog[$eq->getConfiguration('type', '')] ?? [];
            foreach (($eq->getCmd('info') ?: []) as $cmd) {
                $def = $defs[$cmd->getLogicalId()] ?? null;
                if ($def === null || empty($def['historized'])) {
                    continue;
                }
                $dirty = false;
                if ((int) $cmd->getIsHistorized() !== 1) {
                    $cmd->setIsHistorized(1);
                    $dirty = true;
                }
                // Réglages complétés seulement s'ils sont restés vides (défaut du core) : une valeur
                // choisie par l'utilisateur n'est jamais remplacée
                foreach (['repeatEventManagement' => 'repeat', 'historizeMode' => 'smooth', 'historyPurge' => 'purge'] as $key => $field) {
                    if (($def[$field] ?? '') !== '' && (string) $cmd->getConfiguration($key, '') === '') {
                        $cmd->setConfiguration($key, $def[$field]);
                        $dirty = true;
                    }
                }
                if (!$dirty) {
                    continue;
                }
                try {
                    $cmd->save();
                    $changed++;
                } catch (Exception $e) {
                    log::add('myfronius', 'warning', '[migration] historisation de ' . $cmd->getHumanName() . ' : ' . $e->getMessage());
                }
            }
        }
        config::save('history_defaults', 1, 'myfronius');
        log::add('myfronius', 'info', '[migration] historisation activée sur ' . $changed . ' commande(s)');
    }

    /**
     * « Restaurer les défauts d'historisation » (page de configuration du plugin) : remet TOUTES les
     * commandes info du plugin sur les réglages du catalogue — historisation, répétition, lissage,
     * purge — y compris ceux modifiés par l'utilisateur. $apply = false : aperçu sans rien modifier.
     * Retour : nombre de commandes à modifier (ou modifiées) et noms de celles dont l'historisation
     * serait retirée (le core efface leur historique au prochain archivage).
     */
    public static function resetHistoryDefaults(bool $apply): array
    {
        $catalog  = self::loadCommandsConfig();
        $changed  = 0;
        $disabled = [];
        foreach (eqLogic::byType('myfronius') as $eq) {
            $defs = $catalog[$eq->getConfiguration('type', '')] ?? [];
            foreach (($eq->getCmd('info') ?: []) as $cmd) {
                $def = $defs[$cmd->getLogicalId()] ?? null;
                if ($def === null || ($def['type'] ?? 'info') !== 'info') {
                    continue;
                }
                $historized = !empty($def['historized']);
                $target = [
                    'repeatEventManagement' => (string) ($def['repeat'] ?? ''),
                    'historizeMode'         => $historized ? (string) ($def['smooth'] ?? '') : '',
                    'historyPurge'          => $historized ? (string) ($def['purge'] ?? '') : '',
                ];
                $diff = (int) $cmd->getIsHistorized() !== ($historized ? 1 : 0);
                foreach ($target as $key => $value) {
                    $diff = $diff || (string) $cmd->getConfiguration($key, '') !== $value;
                }
                if (!$diff) {
                    continue;
                }
                $changed++;
                if (!$historized && (int) $cmd->getIsHistorized() === 1) {
                    $disabled[] = $cmd->getHumanName();
                }
                if (!$apply) {
                    continue;
                }
                $cmd->setIsHistorized($historized ? 1 : 0);
                foreach ($target as $key => $value) {
                    $cmd->setConfiguration($key, $value);
                }
                try {
                    $cmd->save();
                } catch (Exception $e) {
                    log::add('myfronius', 'warning', '[historisation] ' . $cmd->getHumanName() . ' : ' . $e->getMessage());
                }
            }
        }
        if ($apply) {
            config::save('history_defaults', 1, 'myfronius');
            log::add('myfronius', 'info', '[historisation] défauts restaurés sur ' . $changed . ' commande(s)'
                . ($disabled ? ', historisation retirée : ' . implode(', ', $disabled) : ''));
        }
        return ['changed' => $changed, 'disabled' => $disabled];
    }

    public static function deamon_start(array $_params = []): void
    {
        self::deamon_stop();
        // Migrations uniques qu'un déploiement par copie ne déclenche pas (pas de passage par install.php)
        try {
            self::migrateHistoryDefaults();
        } catch (Exception $e) {
            log::add('myfronius', 'error', '[migration] historisation : ' . $e->getMessage());
        }

        $cfg        = self::getApiConfig();
        $daemonPath = realpath(__DIR__ . '/../../resources/demond');
        $tmpFolder  = jeedom::getTmpFolder('myfronius');
        $pidFile    = self::pidFile();
        $stateFile  = $tmpFolder . '/state.json';
        // Copie persistante de l'état journalier : le dossier temporaire est un tmpfs, vidé au
        // redémarrage de la box ; data/ du plugin est conservé par les mises à jour (créé par le démon)
        $persistFile = dirname(__DIR__, 2) . '/data/state.json';
        $logFile   = log::getPathToLog('myfronius_daemon');

        $monologLevel = log::getLogLevel('myfronius');
        $logLevelMap  = ['100' => 'debug', '200' => 'info', '250' => 'info', '300' => 'warning', '400' => 'error', '500' => 'error', '1000' => 'error'];
        $logLevel     = $logLevelMap[(string) $monologLevel] ?? 'info';

        // Boucle locale (protocole et port de l'accès interne) : un changement d'IP de la box ne
        // coupe plus le callback d'un démon déjà lancé ; cas docker géré par le core
        $callbackUrl = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/myfronius/core/php/jeedomDaemon.php';
        // Clé API du plugin, mode API réglé par l'utilisateur conservé
        $apiKey = self::apiKey();

        // Capacité batterie saisie par l'utilisateur (repli si le stockage ne la fournit pas)
        $capacity = 0;
        $battery  = self::byLogicalId('battery', 'myfronius');
        if (is_object($battery)) {
            $capacity = (int) $battery->getConfiguration('capacity_wh', 0);
        }

        // Bornes Wattpilot : liste (avec mots de passe) dans un fichier lisible par www-data seul,
        // créé directement en 0600 et supprimé par le démon dès qu'il l'a lu
        $chargersFile = self::chargersFile();
        $chargers     = self::chargerList();
        $umask        = umask(0077);
        file_put_contents($chargersFile, json_encode($chargers, JSON_UNESCAPED_UNICODE));
        umask($umask);
        @chmod($chargersFile, 0600);

        $cmd = 'python3 ' . $daemonPath . '/demond.py'
             . ' --loglevel '   . escapeshellarg($logLevel)
             . ' --callback '   . escapeshellarg($callbackUrl)
             . ' --apikey '     . escapeshellarg($apiKey)
             . ' --host '       . escapeshellarg($cfg['host'])
             . ' --cycle '      . (int) $cfg['cycle']
             . ' --socketport ' . (int) $cfg['socketport']
             . ' --pid '        . escapeshellarg($pidFile)
             . ' --statefile '  . escapeshellarg($stateFile)
             . ' --persistfile ' . escapeshellarg($persistFile)
             . ' --capacity '   . (int) $capacity
             . ' --chargers '   . escapeshellarg($chargersFile)
             // Fuseau de Jeedom (réglé par le core) : minuit et heure de lecture du démon alignés
             . ' --tz '         . escapeshellarg(date_default_timezone_get());

        // Pilotage batterie par Modbus TCP (même hôte que la Solar API, port et unit ID configurables)
        if ((string) config::byKey('modbus_enabled', 'myfronius', '0') === '1') {
            $modbusHost = preg_replace('#^https?://#i', '', $cfg['host']);
            $modbusHost = preg_replace('#[:/].*$#', '', $modbusHost);
            $cmd .= ' --modbus-host ' . escapeshellarg($modbusHost)
                  . ' --modbus-port ' . (int) config::byKey('modbus_port', 'myfronius', 502)
                  . ' --modbus-unit ' . (int) config::byKey('modbus_unit', 'myfronius', 1);
        }

        // Clé API masquée dans le log (exportable, souvent collé sur Community)
        log::add('myfronius', 'info', '[deamon_start] lancement : ' . str_replace(escapeshellarg($apiKey), "'***'", $cmd));
        exec($cmd . ' >> ' . $logFile . ' 2>&1 &');

        $attempts = 0;
        while ($attempts < 10 && !file_exists($pidFile)) {
            sleep(1);
            $attempts++;
        }
        if (!file_exists($pidFile)) {
            log::add('myfronius', 'error', '[deamon_start] le démon n\'a pas démarré (pas de PID après 10 s), voir le log myfronius_daemon');
            return;
        }
        log::add('myfronius', 'info', '[deamon_start] démon démarré — PID ' . trim(file_get_contents($pidFile)));
    }

    public static function deamon_stop(): void
    {
        $pidFile = self::pidFile();
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0) {
                log::add('myfronius', 'info', '[deamon_stop] SIGTERM au PID ' . $pid);
                posix_kill($pid, SIGTERM);
                sleep(2);
            }
            @unlink($pidFile);
        }
        exec('pkill -f "myfronius/resources/demond/demond.py" 2>/dev/null');
        sleep(1);
        // Filet : un démon qui ignore encore SIGTERM (arrêt bloqué) ne doit pas garder le port du socket
        exec('pkill -9 -f "myfronius/resources/demond/demond.py" 2>/dev/null');
        // Liste des bornes (mots de passe) jamais laissée sur disque, même si le démon ne l'a pas lue
        @unlink(self::chargersFile());
        log::add('myfronius', 'info', '[deamon_stop] démon arrêté');
    }

    /**
     * Filet de sécurité des charges pilotées (chaque minute) : quand plus aucun lot du site n'arrive
     * (démon arrêté, planté ou bloqué), la décision tourne quand même, en « données indisponibles ».
     */
    public static function cron(): void
    {
        $site = self::byLogicalId('site', 'myfronius');
        if (!is_object($site) || !myfroniusLoadManager::siteStale(time())) {
            return;
        }
        foreach (eqLogic::byType('myfronius', true) as $eq) {
            if ($eq->getConfiguration('type', '') === 'load') {
                try {
                    myfroniusLoadManager::run();
                } catch (Exception $e) {
                    log::add('myfronius', 'error', '[cron] charges pilotées : ' . $e->getMessage());
                }
                return;
            }
        }
    }

    /**
     * Marqueur « ordre de mode batterie non transmis » lu par le démon à chaque cycle (à côté de
     * state.json, même nom que core.BATTERY_RESET_FILE) : la consigne persistée est périmée, il
     * revient en gestion automatique au lieu de la restaurer.
     */
    public static function batteryResetFile(): string
    {
        return jeedom::getTmpFolder('myfronius') . '/battery_reset';
    }

    /** Mode secours actif d'après la dernière valeur remontée par le démon (Site PV). */
    public static function backupActive(): bool
    {
        $site = self::byLogicalId('site', 'myfronius');
        $cmd  = is_object($site) ? $site->getCmd('info', 'backup_mode') : null;
        return is_object($cmd) && (string) $cmd->execCmd() === '1';
    }

    /**
     * Envoie un ordre JSON au démon : {"apikey": "...", "action": "resync"}.
     */
    public static function sendToSocket(array $payload): void
    {
        $socketport = (int) config::byKey('socketport', 'myfronius', self::DEFAULT_SOCKETPORT);
        $payload['apikey'] = self::apiKey();
        $fp = @fsockopen('127.0.0.1', $socketport, $errno, $errstr, 1);
        if ($fp === false) {
            throw new Exception('[socket] connexion impossible (port ' . $socketport . ') : ' . $errstr . ' (' . $errno . ')');
        }
        fwrite($fp, json_encode($payload) . "\n");
        fclose($fp);
    }
}


class myfroniusCmd extends cmd
{
    /** Actions des bornes routées vers le démon (spec §11). */
    const CHARGER_ACTIONS = ['mode_prev', 'mode_next', 'mode_set', 'force_on', 'force_off', 'force_neutral',
                             'mode_default', 'mode_eco', 'mode_trip',
                             'amp_set', 'trip_energy_set', 'trip_km_set', 'trip_time_set', 'cco_set'];

    /** Boutons de mode directs des bornes → mode_set avec le code Wattpilot (lmo). */
    const CHARGER_MODE_CODES = ['mode_default' => '3', 'mode_eco' => '4', 'mode_trip' => '5'];

    /** Actions de pilotage batterie → ordre du démon (spec §12). */
    const BATTERY_ACTIONS = [
        'ctrl_auto'         => 'auto',
        'ctrl_hold'         => 'hold',
        'ctrl_no_charge'    => 'no_charge',
        'ctrl_force_charge' => 'force_charge',
        'ctrl_limit_charge' => 'limit_charge',
        'ctrl_reserve_set'  => 'reserve',
    ];

    /**
     * « Rafraîchir » → resynchronisation (démon) ou découverte directe.
     * Actions d'une borne → ordre au démon par le socket.
     */
    public function execute($_options = array())
    {
        $logicalId = $this->getLogicalId();
        $eqLogic   = $this->getEqLogic();

        if ($logicalId === 'refresh') {
            try {
                $result = myfronius::syncEquipments();
                log::add('myfronius', 'info', '[refresh] ' . (isset($result['queued']) ? 'resync demandée au démon' : 'synchronisation directe effectuée'));
            } catch (Exception $e) {
                log::add('myfronius', 'error', '[refresh] ' . $e->getMessage());
            }
            return;
        }

        if (isset(self::BATTERY_ACTIONS[$logicalId]) && $eqLogic->getConfiguration('type', '') === 'battery') {
            // Commande restée en place (scénario, resynchro pas encore faite) : échec visible
            if ((string) config::byKey('modbus_enabled', 'myfronius', '0') !== '1') {
                throw new Exception(__('Pilotage Modbus désactivé dans la configuration du plugin', __FILE__));
            }
            $payload = ['action' => 'battery', 'cmd' => self::BATTERY_ACTIONS[$logicalId]];
            // Secours : le démon refuse toute consigne hors retour en automatique ; refus visible ici
            // (scénario, interface) plutôt que dans son seul log
            if ($payload['cmd'] !== 'auto' && myfronius::backupActive()) {
                throw new Exception(__('Mode secours actif : consigne batterie refusée', __FILE__));
            }
            if ($this->getSubType() === 'slider') {
                $payload['value'] = $_options['slider'] ?? null;
            }
            // Ordre de mode : il remplace un ordre perdu, marqueur retiré AVANT l'envoi (sinon le
            // démon pourrait lire le marqueur périmé après avoir reçu ce nouvel ordre)
            $isMode = $payload['cmd'] !== 'reserve';
            $marker = myfronius::batteryResetFile();
            if ($isMode && file_exists($marker)) {
                @unlink($marker);
            }
            try {
                myfronius::sendToSocket($payload);
                // Le socket n'accuse pas réception : consigne transmise, pas encore appliquée
                log::add('myfronius', 'info', '[battery] consigne ' . $payload['cmd'] . (isset($payload['value']) ? ' = ' . $payload['value'] : '') . ' envoyée au démon');
            } catch (Exception $e) {
                // Démon arrêté ou en redémarrage : il restaurerait l'ancienne consigne (state.json).
                // Le marqueur le fait revenir en automatique ; l'échec est visible (scénario, UI).
                if ($isMode) {
                    @file_put_contents($marker, (string) time());
                }
                log::add('myfronius', 'error', '[battery] ' . $e->getMessage());
                throw new Exception(__('Démon injoignable, consigne batterie non transmise', __FILE__) . ' : ' . $e->getMessage());
            }
            return;
        }

        if ($eqLogic->getConfiguration('type', '') === 'load' && in_array($logicalId, ['mode_auto', 'mode_on', 'mode_off', 'mode_prev', 'mode_next'], true)) {
            $order   = ['auto', 'on', 'off'];
            $current = $eqLogic->getConfiguration('mode', 'auto');
            if ($logicalId === 'mode_prev' || $logicalId === 'mode_next') {
                $idx  = array_search($current, $order, true);
                $idx  = ($idx === false) ? 0 : $idx;
                $mode = $order[($idx + ($logicalId === 'mode_next' ? 1 : count($order) - 1)) % count($order)];
            } else {
                $mode = substr($logicalId, 5);   // auto | on | off
            }
            $eqLogic->setConfiguration('mode', $mode);
            $eqLogic->save();
            $eqLogic->checkAndUpdateCmd('mode', $mode);
            log::add('myfronius', 'info', '[load] ' . $eqLogic->getName() . ' : mode ' . $mode);
            // Application immédiate (un forçage ne doit pas attendre le prochain cycle du démon),
            // après le tour éventuellement en cours, qui a lu l'ancien mode
            myfroniusLoadManager::run(true);
            return;
        }

        if (in_array($logicalId, self::CHARGER_ACTIONS, true) && $eqLogic->getConfiguration('type', '') === 'charger') {
            $payload = ['action' => 'charger', 'id' => $eqLogic->getLogicalId(), 'cmd' => $logicalId];
            // Valeur saisie (sélecteur, curseur, texte) transmise telle quelle : le démon la borne et
            // la traduit (wattpilot.py, _translate), et signale une valeur invalide dans son log
            if ($this->getSubType() === 'select') {
                $payload['value'] = $_options['select'] ?? null;
            } elseif ($this->getSubType() === 'slider') {
                $payload['value'] = $_options['slider'] ?? null;
            } elseif ($this->getSubType() === 'message') {
                $payload['value'] = $_options['message'] ?? null;
            } elseif (isset(self::CHARGER_MODE_CODES[$logicalId])) {
                $payload['cmd']   = 'mode_set';
                $payload['value'] = self::CHARGER_MODE_CODES[$logicalId];
            }
            try {
                myfronius::sendToSocket($payload);
                log::add('myfronius', 'info', '[charger] ' . $eqLogic->getName() . ' : ' . $logicalId . (isset($payload['value']) ? ' = ' . $payload['value'] : '') . ' envoyé au démon');
            } catch (Exception $e) {
                // Démon arrêté ou en redémarrage : échec visible (scénario, interface), pas seulement au log
                log::add('myfronius', 'error', '[charger] ' . $e->getMessage());
                throw new Exception(__('Démon injoignable, ordre de la borne non transmis', __FILE__) . ' : ' . $e->getMessage());
            }
        }
    }
}
