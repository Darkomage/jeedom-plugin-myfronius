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
 * Gestion des charges pilotées sur le surplus (spec §13).
 *
 * La décision est une fonction pure (decide) sans dépendance à Jeedom, testable en CLI ;
 * run() fait le tour des équipements de type load, lit leurs commandes externes, applique les
 * ordres et met à jour leurs commandes info.
 */
class myfroniusLoadManager
{
    const DEFAULTS = [
        'priority'         => 10,
        'idle_priority'    => '',
        'start_factor'     => 130,
        'start_delay'      => 60,
        'stop_delay'       => 120,
        'min_on'           => 300,
        'min_off'          => 300,
        'import_tolerance' => 50,
        'use_battery'      => 0,
        'nominal_w'        => 0,
        'mode'             => 'auto',
    ];

    const REASONS = [
        'forced_on'     => 'forcé marche',
        'forced_off'    => 'forcé arrêt',
        'not_allowed'   => 'interdit',
        'no_surplus'    => 'surplus insuffisant',
        'start_delay'   => 'délai de confirmation',
        'min_off'       => 'arrêt mini',
        'min_on'        => 'marche mini',
        'stop_delay'    => 'délai avant extinction',
        'running'       => 'en marche (surplus)',
        'importing'     => 'import réseau ou décharge batterie',
        'read_only'     => 'lecture seule',
        'waiting_cycle' => 'stabilisation après une autre bascule',
        'bad_config'    => 'commande introuvable (vérifier la configuration)',
        'no_nominal'    => 'puissance nominale manquante',
        'ev_priority'   => 'priorité à la borne (voiture branchée)',
        'no_data'       => 'données du site indisponibles',
    ];

    /** Âge minimal (s) du dernier lot du site au-delà duquel ses données sont jugées figées. */
    const STALE_MIN = 180;

    /**
     * Clé du cache Jeedom de l'horodatage du dernier lot du site (posé par jeedomDaemon.php). Hors du
     * cache du Site : eqLogic::setCache relit puis réécrit tout le blob de l'équipement, et cette
     * écriture faite hors du verrou du tour effacerait load_last_auto_switch ou load_ev_hold_until
     * posés au même moment par un tour concurrent (changement de mode, cron).
     */
    const LAST_SITE_KEY = 'myfronius::load_last_site';

    /**
     * Délai (s) entre deux bascules automatiques : au moins la fenêtre de la moyenne 1 min
     * (WINDOWS['avg1'] de calculator.py), sinon les moyennes lues n'ont pas encore vu la bascule
     * précédente et une deuxième charge s'allumerait (ou s'éteindrait) sur un surplus périmé.
     */
    const SETTLE_DELAY = 60;

    /** Durée par défaut de la pause des charges au branchement d'une voiture (s). */
    const EV_HOLD_DEFAULT = 300;

    /**
     * Délai (s) avant de renvoyer un ordre forcé, de pause borne ou d'interdiction que l'état lu ne
     * reflète pas encore (retour d'état lent, relais sans retour, état « en chauffe » d'un thermostat) :
     * sans lui, l'ordre et ses actions supplémentaires repartiraient à chaque cycle.
     */
    const RESEND_DELAY = 60;

    /** Raisons dont l'ordre est soumis à RESEND_DELAY (branches de decide qui appellent resendDue). */
    const RESEND_LIMITED = ['forced_on', 'forced_off', 'ev_priority', 'not_allowed'];

    /** Attente maximale (s) de la fin du tour en cours par un changement de mode (requête web). */
    const RUN_WAIT = 5;

    /** Lanceur de la commande détachée des actions supplémentaires (remplacé par les tests). */
    public static $launcher = 'exec';

    /**
     * Décision pure.
     *
     * $load  : configuration (clés de DEFAULTS + 'controllable' bool)
     * $input : ['allowed' => bool, 'state' => bool, 'surplus' => float, 'surplus_batt' => float,
     *           'import' => float, 'discharge' => float, 'power' => float|null, 'ev_hold' => bool, 'no_data' => bool]
     *          (W, moyennes 1 min ; discharge = décharge batterie ; power = puissance mesurée de la charge, null si inconnue ;
     *          ev_hold = pause après le branchement d'une voiture sur une borne ; no_data = données du site figées)
     * $cache : ['cond_since' => int|null, 'last_switch' => int|null, 'last_state' => bool|null,
     *           'last_order' => 'on'|'off'|null (dernier ordre envoyé, posé par runOne)]
     * $now   : epoch
     * $switchedThisCycle : une bascule automatique a eu lieu il y a moins de SETTLE_DELAY (ce cycle
     *          compris) : les moyennes ne l'ont pas encore prise en compte
     *
     * Retour : ['action' => 'on'|'off'|null, 'reason' => code, 'cache' => nouveau cache, 'auto' => bool]
     */
    public static function decide(array $load, array $input, array $cache, int $now, bool $switchedThisCycle = false): array
    {
        // Valeurs hors plage bornées (Jeedom enregistre les champs sans appliquer min / max) : une
        // marge à 0 allumerait la charge sans surplus, une tolérance négative l'éteindrait toujours
        $cfg   = self::normalizeConfig(array_merge(self::DEFAULTS, $load));
        $mode  = in_array($cfg['mode'], ['auto', 'on', 'off'], true) ? $cfg['mode'] : 'auto';
        $state = (bool) ($input['state'] ?? false);
        $cache = array_merge(['cond_since' => null, 'last_switch' => null, 'last_state' => null, 'last_order' => null], $cache);
        $out   = ['action' => null, 'reason' => 'running', 'cache' => $cache, 'auto' => false];

        if (empty($cfg['controllable'])) {
            $out['reason'] = 'read_only';
            return $out;
        }

        // Forçages : immédiats, sans délai ni minimum (même ordre renvoyé au plus toutes les RESEND_DELAY s)
        if ($mode === 'on' || $mode === 'off') {
            $want = ($mode === 'on');
            $out['reason'] = $want ? 'forced_on' : 'forced_off';
            if ($state !== $want && self::resendDue($cache, $mode, $now)) {
                $out['action'] = $want ? 'on' : 'off';
                $out['cache']['last_switch'] = $now;
            }
            $out['cache']['cond_since'] = null;
            return $out;
        }

        // Voiture branchée sur une borne : les charges automatiques s'effacent (toutes en même temps,
        // sans délai ni marche mini) pour laisser la borne monter sur le surplus, puis restent éteintes
        // jusqu'à la fin de la pause. Ensuite, le surplus mesuré exclut déjà ce que prend la borne.
        if (!empty($input['ev_hold'])) {
            $out['reason'] = 'ev_priority';
            $out['cache']['cond_since'] = null;
            if ($state && self::resendDue($cache, 'off', $now)) {
                $out['action'] = 'off';
                $out['cache']['last_switch'] = $now;
            }
            return $out;
        }

        $allowed   = (bool) ($input['allowed'] ?? true);
        $sinceLast = ($cache['last_switch'] === null) ? PHP_INT_MAX : ($now - (int) $cache['last_switch']);

        // Données du site indisponibles (onduleur injoignable, démon arrêté, Site désactivé) : les
        // flux lus sont figés. Pas d'allumage ; une charge allumée s'éteint après stop_delay (grâce
        // contre un échec isolé), sans marche mini ni attente de stabilisation. Une charge
        // interdite suit la règle normale (extinction immédiate).
        if (!empty($input['no_data']) && $allowed) {
            $out['reason'] = 'no_data';
            if (!$state) {
                $out['cache']['cond_since'] = null;
                return $out;
            }
            if ($cache['cond_since'] === null) {
                $out['cache']['cond_since'] = $now;
            }
            if ($now - (int) $out['cache']['cond_since'] >= (int) $cfg['stop_delay']) {
                $out['action'] = 'off';
                $out['cache']['cond_since']  = null;
                $out['cache']['last_switch'] = $now;
            }
            return $out;
        }

        if (!$state) {
            // Charge éteinte : condition d'allumage
            $available = !empty($cfg['use_battery']) ? (float) ($input['surplus_batt'] ?? 0) : (float) ($input['surplus'] ?? 0);
            $needed    = (float) $cfg['nominal_w'] * (float) $cfg['start_factor'] / 100.0;
            if (!$allowed) {
                $out['reason'] = 'not_allowed';
                $out['cache']['cond_since'] = null;
                return $out;
            }
            if ((float) $cfg['nominal_w'] <= 0 || $available < $needed) {
                $out['reason'] = 'no_surplus';
                $out['cache']['cond_since'] = null;
                return $out;
            }
            if ($cache['cond_since'] === null) {
                $out['cache']['cond_since'] = $now;
            }
            if ($now - (int) $out['cache']['cond_since'] < (int) $cfg['start_delay']) {
                $out['reason'] = 'start_delay';
                return $out;
            }
            if ($sinceLast < (int) $cfg['min_off']) {
                $out['reason'] = 'min_off';
                return $out;
            }
            if ($switchedThisCycle) {
                $out['reason'] = 'waiting_cycle';
                return $out;
            }
            $out['action'] = 'on';
            $out['auto']   = true;
            $out['reason'] = 'running';
            $out['cache']['cond_since']  = null;
            $out['cache']['last_switch'] = $now;
            return $out;
        }

        // Charge allumée : extinction si l'import réseau dépasse la tolérance, ou si la décharge de la
        // batterie dépasse le budget accordé par la marge (l'onduleur décharge la batterie avant
        // d'importer : l'import seul laisserait la charge la vider). Avec une marge < 100 %, la charge a
        // démarré sur un surplus d'au moins nominale × marge : la batterie ne doit fournir que le
        // complément, soit puissance − nominale × marge (puissance mesurée si connue, sinon nominale).
        // Marge ≥ 100 % : budget nul, seule la tolérance s'applique.
        $tolerance = (float) $cfg['import_tolerance'];
        $import    = max(0.0, (float) ($input['import'] ?? 0));
        $discharge = max(0.0, (float) ($input['discharge'] ?? 0));
        $power     = isset($input['power']) ? (float) $input['power'] : (float) $cfg['nominal_w'];
        $budget    = max(0.0, $power - (float) $cfg['nominal_w'] * (float) $cfg['start_factor'] / 100.0);
        $deficit   = $import > $tolerance || $discharge > $budget + $tolerance;
        // Éteindre une charge mesurée à 0 W ne libère rien : pas de verrou de stabilisation, la
        // charge suivante dans l'ordre de délestage peut s'éteindre dans le même tour
        $frees     = !(isset($input['power']) && abs((float) $input['power']) < 0.5);
        if (!$allowed) {
            // Interdite : extinction immédiate, sans attendre la stabilisation d'une autre bascule
            $out['reason'] = 'not_allowed';
            $out['cache']['cond_since'] = null;
            if (!self::resendDue($cache, 'off', $now)) {
                return $out;
            }
            $out['action'] = 'off';
            $out['auto']   = $frees;
            $out['cache']['last_switch'] = $now;
            return $out;
        }
        if (!$deficit) {
            $out['reason'] = 'running';
            $out['cache']['cond_since'] = null;
            return $out;
        }
        if ($cache['cond_since'] === null) {
            $out['cache']['cond_since'] = $now;
        }
        if ($now - (int) $out['cache']['cond_since'] < (int) $cfg['stop_delay']) {
            $out['reason'] = 'stop_delay';
            return $out;
        }
        if ($sinceLast < (int) $cfg['min_on']) {
            $out['reason'] = 'min_on';
            return $out;
        }
        if ($switchedThisCycle) {
            $out['reason'] = 'waiting_cycle';
            return $out;
        }
        $out['action'] = 'off';
        $out['auto']   = $frees;
        $out['reason'] = 'importing';
        $out['cache']['cond_since']  = null;
        $out['cache']['last_switch'] = $now;
        return $out;
    }

    /**
     * Ordre forcé, de pause borne ou d'interdiction (fonction pure) : à envoyer, sauf si le même ordre
     * ($order) est déjà parti il y a moins de RESEND_DELAY s (l'état lu ne l'a pas encore pris en
     * compte). Ordre différent, aucun ordre connu ou horloge revenue en arrière → envoi immédiat.
     */
    private static function resendDue(array $cache, string $order, int $now): bool
    {
        if (($cache['last_order'] ?? null) !== $order || !is_numeric($cache['last_switch'] ?? null)) {
            return true;
        }
        $last = (int) $cache['last_switch'];
        return $now < $last || $now - $last >= self::RESEND_DELAY;
    }

    /**
     * Bornage des paramètres numériques (fonction pure), appliqué par decide et à la sauvegarde :
     * marge entre 50 et 500 % (≤ 0 ou non numérique → défaut), délais, tolérance et nominale ≥ 0
     * (non numérique → défaut), priorité ≥ 1, priorité de repos vide ou ≥ 1. Seules les clés
     * présentes sont traitées ; une valeur valide est rendue telle quelle.
     */
    public static function normalizeConfig(array $cfg): array
    {
        if (array_key_exists('start_factor', $cfg)) {
            $f = $cfg['start_factor'];
            if (!is_numeric($f) || (float) $f <= 0) {
                $cfg['start_factor'] = self::DEFAULTS['start_factor'];
            } elseif ((float) $f < 50 || (float) $f > 500) {
                $cfg['start_factor'] = (float) $f < 50 ? 50 : 500;
            }
        }
        foreach (['start_delay', 'stop_delay', 'min_on', 'min_off', 'import_tolerance', 'nominal_w', 'priority'] as $key) {
            if (!array_key_exists($key, $cfg)) {
                continue;
            }
            $min = ($key === 'priority') ? 1 : 0;
            if (!is_numeric($cfg[$key])) {
                $cfg[$key] = self::DEFAULTS[$key];
            } elseif ((float) $cfg[$key] < $min) {
                $cfg[$key] = $min;
            }
        }
        if (array_key_exists('idle_priority', $cfg)) {
            $idle = trim((string) $cfg['idle_priority']);
            if (!is_numeric($idle)) {
                $cfg['idle_priority'] = '';
            } elseif ((float) $idle < 1) {
                $cfg['idle_priority'] = 1;
            }
        }
        return $cfg;
    }

    /**
     * Priorité effective (fonction pure) : une charge allumée dont la puissance mesurée est nulle
     * (thermostat coupé, cycle terminé…) passe à la priorité de repos configurée. Inactif si la
     * priorité de repos est vide, sans commande de puissance ($rawPower null) ou si la mesure n'est
     * pas un nombre.
     */
    public static function effectivePriority(array $load, bool $state, $rawPower): int
    {
        $priority = (int) ($load['priority'] ?? self::DEFAULTS['priority']);
        $idle     = trim((string) ($load['idle_priority'] ?? ''));
        if ($idle === '' || !is_numeric($idle) || !$state || !is_numeric($rawPower)) {
            return $priority;
        }
        return abs((float) $rawPower) < 0.5 ? (int) $idle : $priority;
    }

    /**
     * Actions supplémentaires (fonction pure) : normalise la liste enregistrée par l'interface
     * (lignes {cmd, options} du sélecteur d'actions Jeedom), dans l'ordre, sans les lignes vides ni
     * désactivées. Toute valeur autre qu'un tableau → [].
     */
    public static function actionList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $list = [];
        foreach ($raw as $action) {
            $cmd = is_array($action) ? trim((string) ($action['cmd'] ?? '')) : '';
            if ($cmd === '') {
                continue;
            }
            $options = is_array($action['options'] ?? null) ? $action['options'] : [];
            if (isset($options['enable']) && (string) $options['enable'] === '0') {
                continue;
            }
            $list[] = ['cmd' => $cmd, 'options' => $options];
        }
        return $list;
    }

    /**
     * Priorité à la voiture (fonction pure, décision 2026-10-13) : la borne **démarre une charge** (état
     * 2 depuis tout autre état connu : reprise du mode Éco après une pause de surplus ou une
     * commutation de phases, départ depuis « en attente »), ou une voiture est **branchée** (1 absente
     * → 3 en attente / 4 terminé). Les charges s'effacent alors cinq minutes pour laisser la borne
     * prendre le surplus : sans cela, des charges déjà allumées lui prendraient l'export et elle ne
     * reprendrait jamais. État précédent inconnu (null, 0 : premier cycle, perte de communication)
     * → pas de déclenchement.
     */
    public static function evConnected($last, $current): bool
    {
        if (!is_numeric($last) || !is_numeric($current)) {
            return false;
        }
        $last    = (int) $last;
        $current = (int) $current;
        if ($last === 0) {
            return false;
        }
        return ($current === 2 && $last !== 2) || ($last === 1 && in_array($current, [3, 4], true));
    }

    /**
     * Actions supplémentaires (fonction pure) : sépare les lignes exécutables des références de
     * commande « #…# » introuvables ou qui ne sont pas des actions ($isAction), ignorées pour ne pas
     * bloquer la commande principale ni les autres actions. Les mots-clés de scénario (wait,
     * variable, scenario…) sont laissés à l'évaluateur Jeedom.
     * Retour : [lignes conservées dans l'ordre, références écartées].
     */
    public static function splitActions(array $list, callable $isAction): array
    {
        $kept    = [];
        $missing = [];
        foreach ($list as $extra) {
            if (strpos($extra['cmd'], '#') === 0 && !$isAction($extra['cmd'])) {
                $missing[] = $extra['cmd'];
                continue;
            }
            $kept[] = $extra;
        }
        return [$kept, $missing];
    }

    /**
     * Décision tenant compte d'une configuration incomplète (fonction pure). $configIssue : une
     * référence renseignée est introuvable (commande, action supplémentaire) ou l'autorisation n'est
     * pas évaluable (déjà traitée comme « interdit » par l'appelant). decide() s'applique toujours :
     * forçages, extinction sur interdiction ou déficit restent actifs, les replis (dernier ordre,
     * nominale) couvrant les commandes optionnelles manquantes. La raison bad_config / no_nominal ne
     * remplace qu'une décision sans action : non pilotable, ou automatique (no_nominal : éteinte).
     */
    public static function decideChecked(array $load, array $input, array $cache, int $now, bool $switchedThisCycle, bool $configIssue): array
    {
        $decision = self::decide($load, $input, $cache, $now, $switchedThisCycle);
        if ($decision['action'] !== null) {
            return $decision;
        }
        $mode = $load['mode'] ?? self::DEFAULTS['mode'];
        $auto = !in_array($mode, ['on', 'off'], true);
        if ($configIssue && ($auto || empty($load['controllable']))) {
            $decision['reason'] = 'bad_config';
        } elseif ($auto && !empty($load['controllable']) && empty($input['state'])
            && (float) ($load['nominal_w'] ?? 0) <= 0) {
            $decision['reason'] = 'no_nominal';
        }
        return $decision;
    }

    /**
     * Stabilisation (fonction pure) : la dernière bascule automatique ($lastAuto, epoch, null =
     * aucune) date de moins de $delay s. Horloge revenue en arrière → pas de blocage.
     */
    public static function settling(?int $lastAuto, int $now, int $delay = self::SETTLE_DELAY): bool
    {
        return $lastAuto !== null && $now >= $lastAuto && $now - $lastAuto < $delay;
    }

    /**
     * Ordre de traitement (fonction pure) : $items = [['id', 'priority' (effective), 'state'], …].
     * D'abord les charges allumées par priorité décroissante (en cas de déficit, on déleste la
     * moins prioritaire), puis les éteintes par priorité croissante (la plus prioritaire est servie
     * en premier) ; égalité départagée par id. Avec une seule bascule automatique à la fois, une
     * extinction sur déficit passe avant tout allumage. Retour : liste des id.
     */
    public static function orderLoads(array $items): array
    {
        usort($items, function ($a, $b) {
            if ((bool) $a['state'] !== (bool) $b['state']) {
                return $a['state'] ? -1 : 1;
            }
            $cmp = $a['state'] ? ((int) $b['priority'] <=> (int) $a['priority']) : ((int) $a['priority'] <=> (int) $b['priority']);
            return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
        });
        return array_column($items, 'id');
    }

    /**
     * Ligne de commande complète (fonction pure) du processus détaché qui exécute les actions
     * supplémentaires d'une charge : « php <script> », sortie ajoutée au log $log, « & » final pour
     * rendre la main aussitôt. Échappement shell POSIX (Jeedom tourne sous Linux). Ne pas passer par
     * system::php() du core : il EXÉCUTE la commande (exec) au lieu de la renvoyer.
     */
    public static function detachedCommand(string $script, string $log, int $eqId, string $action): string
    {
        $quote = function ($arg) {
            return "'" . str_replace("'", "'\\''", $arg) . "'";
        };
        return 'php ' . $quote($script) . ' ' . $quote('eqLogic_id=' . $eqId) . ' ' . $quote('action=' . $action)
            . ' >> ' . $quote($log) . ' 2>&1 &';
    }

    /**
     * Verrou exclusif sur $file, attendu au plus $wait s (0 = non bloquant). Retour : le handle à
     * libérer, false si le verrou est resté pris, null si le fichier ne peut pas être ouvert.
     */
    public static function acquireLock(string $file, float $wait)
    {
        $lock = @fopen($file, 'c');
        if ($lock === false) {
            return null;
        }
        $deadline = microtime(true) + $wait;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                return false;
            }
            usleep(100000);
        }
        return $lock;
    }

    /**
     * Tour complet : lecture du site, décision et ordres pour chaque charge, mise à jour des infos.
     * Appelé depuis jeedomDaemon.php après chaque lot contenant le site, et par myfronius::cron()
     * quand ces lots n'arrivent plus (démon arrêté, planté ou bloqué).
     * Un seul tour à la fois (verrou fichier) : un tour encore en cours (commande principale lente,
     * lot réémis par le démon après expiration de sa requête, cron) fait sauter le suivant plutôt que
     * de prendre deux fois la même décision. $wait (changement de mode) : attend jusqu'à
     * RUN_WAIT s la fin du tour en cours pour passer après lui, avec la configuration à jour (le tour
     * en cours a lu l'ancien mode et le republie).
     */
    public static function run(bool $wait = false): void
    {
        $lock = self::acquireLock(jeedom::getTmpFolder('myfronius') . '/load.lock', $wait ? self::RUN_WAIT : 0);
        if ($lock === false) {
            log::add('myfronius', 'debug', '[load] tour précédent encore en cours : tour sauté');
            return;
        }
        try {
            self::runUnlocked();
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function runUnlocked(): void
    {
        $site = myfronius::byLogicalId('site', 'myfronius');
        if (!is_object($site)) {
            return;
        }
        $now = time();
        // Données figées : Site désactivé (ses commandes ne sont plus mises à jour), onduleur
        // injoignable (comm_ok = 0) ou aucun lot du site depuis plusieurs cycles
        $commCmd = $site->getCmd('info', 'comm_ok');
        $comm    = is_object($commCmd) ? $commCmd->execCmd() : '';
        $noData  = !$site->getIsEnable() || (is_numeric($comm) && (int) $comm === 0) || self::siteStale($now);
        $siteValues = [
            'surplus'      => self::cmdValue($site, 'surplus_avg1', $site, 'surplus'),
            'surplus_batt' => self::cmdValue($site, 'surplus_batt_avg1', $site, 'surplus_batt'),
            'import'       => max(0.0, self::cmdValue($site, 'p_grid_avg1', $site, 'p_grid')),
            'discharge'    => max(0.0, -self::cmdValue($site, 'p_akku_avg1', $site, 'p_akku')),
            'no_data'      => $noData,
        ];

        $loads = [];
        $delay = config::byKey('ev_hold_delay', 'myfronius', '');
        $delay = is_numeric($delay) ? (int) $delay : self::EV_HOLD_DEFAULT;   // vide = défaut
        $holdUntil = (int) $site->getCache('load_ev_hold_until', 0);
        foreach (eqLogic::byType('myfronius') as $eq) {
            if (!$eq->getIsEnable()) {
                continue;
            }
            $type = $eq->getConfiguration('type', '');
            if ($type === 'load') {
                $loads[] = $eq;
            } elseif ($type === 'charger') {
                // Voiture branchée : pause des charges pour laisser la borne prendre le surplus
                $carCmd = $eq->getCmd('info', 'car');
                $car    = is_object($carCmd) ? $carCmd->execCmd() : null;
                $car    = is_numeric($car) ? (int) $car : null;
                if ($delay > 0 && self::evConnected($eq->getCache('load_ev_last_car', null), $car)) {
                    $holdUntil = $now + $delay;
                    log::add('myfronius', 'info', '[load] ' . ($car === 2 ? 'charge démarrée' : 'voiture branchée') . ' sur ' . $eq->getName() . ' : charges en pause ' . $delay . ' s');
                }
                $eq->setCache('load_ev_last_car', $car);
            }
        }
        $site->setCache('load_ev_hold_until', $holdUntil);
        $siteValues['ev_hold'] = $now < $holdUntil;
        if ($noData !== (bool) $site->getCache('load_no_data', false)) {
            if (!empty($loads)) {
                log::add('myfronius', $noData ? 'warning' : 'info', '[load] ' . ($noData
                    ? 'données du site indisponibles : aucun allumage, charges automatiques éteintes après le délai avant extinction'
                    : 'données du site de retour : pilotage normal'));
            }
            $site->setCache('load_no_data', $noData);
        }
        // Ordre de traitement : délestage des allumées de la moins prioritaire à la plus prioritaire,
        // puis allumage des éteintes de la plus prioritaire à la moins prioritaire
        $priorities = [];
        $byId       = [];
        $items      = [];
        foreach ($loads as $eq) {
            $id    = $eq->getId();
            $state = self::stateOf($eq);
            $priorities[$id] = self::priorityOf($eq, $state);
            $byId[$id]       = $eq;
            $items[]         = ['id' => $id, 'priority' => $priorities[$id], 'state' => $state];
        }
        $loads = [];
        foreach (self::orderLoads($items) as $id) {
            $loads[] = $byId[$id];
        }

        // Une seule bascule automatique par fenêtre de la moyenne 1 min, toutes charges confondues
        // (horodatage dans le cache du Site, d'un tour à l'autre)
        $lastAuto = $site->getCache('load_last_auto_switch', null);
        $switched = self::settling(is_numeric($lastAuto) ? (int) $lastAuto : null, $now);
        foreach ($loads as $eq) {
            try {
                if (self::runOne($eq, $siteValues, $now, $switched, $priorities[$eq->getId()])) {
                    $switched = true;
                    $site->setCache('load_last_auto_switch', $now);
                }
            } catch (Exception $e) {
                log::add('myfronius', 'error', '[load] ' . $eq->getName() . ' : ' . $e->getMessage());
            }
        }
    }

    /**
     * Aucun lot du site reçu depuis max(3 cycles, STALE_MIN) : horodatage posé par jeedomDaemon.php
     * (absent = jamais reçu depuis la mise à jour ou le vidage du cache → figé).
     */
    public static function siteStale(int $now): bool
    {
        return $now - self::lastSite() > self::staleAfter();
    }

    /** Horodatage du dernier lot du site (0 = jamais reçu), aussi pour le widget. */
    public static function lastSite(): int
    {
        return (int) cache::byKey(self::LAST_SITE_KEY)->getValue(0);
    }

    /** Délai (s) sans lot du site au-delà duquel les données sont figées (aussi pour le widget). */
    public static function staleAfter(): int
    {
        $cycle = min((int) config::byKey('cycle', 'myfronius', myfronius::DEFAULT_CYCLE), myfronius::MAX_CYCLE);
        return max(3 * $cycle, self::STALE_MIN);
    }

    /** État actuel d'une charge : commande d'état si renseignée, sinon dernier ordre envoyé. */
    private static function stateOf(myfronius $eq): bool
    {
        $stateCmd = self::externalCmd($eq->getConfiguration('cmd_state', ''));
        return is_object($stateCmd) ? self::toBool($stateCmd->execCmd()) : (bool) $eq->getCache('load_last_state', false);
    }

    /** Priorité effective d'une charge d'après son état et sa puissance mesurée actuels. */
    private static function priorityOf(myfronius $eq, bool $state): int
    {
        $load = [
            'priority'      => $eq->getConfiguration('priority', self::DEFAULTS['priority']),
            'idle_priority' => $eq->getConfiguration('idle_priority', ''),
        ];
        $powerCmd = self::externalCmd($eq->getConfiguration('cmd_power', ''));
        if (!is_object($powerCmd) || trim((string) $load['idle_priority']) === '') {
            return self::effectivePriority($load, false, null);
        }
        return self::effectivePriority($load, $state, $powerCmd->execCmd());
    }

    /** Traite une charge ; renvoie true si une bascule automatique a eu lieu. */
    private static function runOne(myfronius $eq, array $siteValues, int $now, bool $switched, int $priority): bool
    {
        $cfg = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $cfg[$key] = $eq->getConfiguration($key, self::DEFAULTS[$key]);
        }
        $cfg    = self::normalizeConfig($cfg);
        $onCmd  = self::externalCmd($eq->getConfiguration('cmd_on', ''));
        $offCmd = self::externalCmd($eq->getConfiguration('cmd_off', ''));
        $cfg['controllable'] = is_object($onCmd) && is_object($offCmd);

        // Configuration incohérente : une commande renseignée mais introuvable → raison bad_config et
        // warning (au changement seulement). Seules allumer / éteindre bloquent le pilotage ; sans
        // état ou puissance, les replis s'appliquent (dernier ordre, nominale).
        $problems = [];
        foreach (['cmd_on', 'cmd_off', 'cmd_state', 'cmd_power'] as $key) {
            $ref = trim((string) $eq->getConfiguration($key, ''));
            if ($ref !== '' && !is_object(self::externalCmd($ref))) {
                $problems[] = $key . ' = « ' . $ref . ' » introuvable';
            }
        }
        // Actions supplémentaires (avec leurs paramètres), exécutées dans l'ordre après la commande
        // principale, en arrière-plan (launchActions). Une référence de commande introuvable ou qui
        // n'est pas une action est ignorée (la commande principale et les autres actions s'exécutent).
        $extraActions = [];
        foreach (['on' => 'actions_on', 'off' => 'actions_off'] as $action => $key) {
            [$extraActions[$action], $missing] = self::splitActions(self::actionList($eq->getConfiguration($key, [])), [self::class, 'isActionRef']);
            foreach ($missing as $ref) {
                $problems[] = $key . ' → « ' . $ref . ' » introuvable ou pas une action (ignorée)';
            }
        }

        $cache = [
            'cond_since'  => $eq->getCache('load_cond_since', null),
            'last_switch' => $eq->getCache('load_last_switch', null),
            'last_state'  => $eq->getCache('load_last_state', null),
            'last_order'  => $eq->getCache('load_last_order', null),
        ];

        $stateCmd = self::externalCmd($eq->getConfiguration('cmd_state', ''));
        $state = is_object($stateCmd) ? self::toBool($stateCmd->execCmd()) : (bool) ($cache['last_state'] ?? false);
        // Autorisation : vide = toujours autorisée ; sinon une commande ou une formule Jeedom.
        // Non évaluable → traitée comme « interdit » (extinction : repli sûr)
        $allowed = self::evaluateAllow((string) $eq->getConfiguration('cmd_allow', ''));
        if ($allowed === null) {
            $problems[] = 'autorisation « ' . $eq->getConfiguration('cmd_allow', '') . ' » non évaluable (traitée comme interdite)';
            $allowed    = false;
        }
        // Warnings au changement seulement (le tour a lieu à chaque cycle du démon)
        $signature = implode("\n", $problems);
        if ($signature !== (string) $eq->getCache('load_cfg_warn', '')) {
            foreach ($problems as $problem) {
                log::add('myfronius', 'warning', '[load] ' . $eq->getName() . ' : ' . $problem);
            }
            if (empty($problems)) {
                log::add('myfronius', 'info', '[load] ' . $eq->getName() . ' : configuration à nouveau valide');
            }
            $eq->setCache('load_cfg_warn', $signature);
        }
        $powerCmd = self::externalCmd($eq->getConfiguration('cmd_power', ''));
        $power    = is_object($powerCmd) ? (float) $powerCmd->execCmd() : ($state ? (float) $cfg['nominal_w'] : 0.0);

        $input    = ['allowed' => $allowed, 'state' => $state, 'power' => is_object($powerCmd) ? $power : null] + $siteValues;
        $decision = self::decideChecked($cfg, $input, $cache, $now, $switched, !empty($problems));
        log::add('myfronius', 'debug', '[load] ' . $eq->getName() . ' prio=' . $priority . ' mode=' . $cfg['mode'] . ' state=' . ($state ? 1 : 0)
            . ' allowed=' . ($allowed ? 1 : 0) . ' power=' . round($power) . ' nominal=' . $cfg['nominal_w']
            . ' surplus=' . round($siteValues['surplus']) . ' surplus_batt=' . round($siteValues['surplus_batt'])
            . ' import=' . round($siteValues['import']) . ' discharge=' . round($siteValues['discharge'])
            . ($siteValues['ev_hold'] ? ' ev_hold=1' : '') . ($siteValues['no_data'] ? ' no_data=1' : '')
            . ' → ' . ($decision['action'] ?? '-') . ' (' . $decision['reason'] . ')');

        if ($decision['action'] === 'on' || $decision['action'] === 'off') {
            $cmd = $decision['action'] === 'on' ? $onCmd : $offCmd;
            log::add('myfronius', 'info', '[load] ' . $eq->getName() . ' → ' . $decision['action'] . ' (' . self::REASONS[$decision['reason']] . ')');
            $cmd->execCmd();
            if (!empty($extraActions[$decision['action']])) {
                self::launchActions($eq, $decision['action']);
            }
            $state = ($decision['action'] === 'on');
            $decision['cache']['last_state'] = $state;
            // Dernier ordre à renvoi limité (forçage, pause borne, interdiction : resendDue) ; une bascule
            // de la régulation automatique ne compte pas, sinon un forçage demandé juste après dans le
            // même sens attendrait RESEND_DELAY. load_last_state est ensuite réécrit avec l'état lu.
            $decision['cache']['last_order'] = in_array($decision['reason'], self::RESEND_LIMITED, true) ? $decision['action'] : null;
            if (!is_object($powerCmd)) {
                $power = $state ? (float) $cfg['nominal_w'] : 0.0;
            }
        }

        foreach ($decision['cache'] as $key => $value) {
            $eq->setCache('load_' . $key, $value);
        }
        $eq->setCache('load_last_state', $state);

        // Énergie du jour (trapèze entre deux cycles, remise à zéro à minuit)
        $eDay = self::integrateDay($eq, $power, $now);

        $eq->checkAndUpdateCmd('p', (int) round($power));
        $eq->checkAndUpdateCmd('state', $state ? 1 : 0);
        $eq->checkAndUpdateCmd('mode', $cfg['mode']);
        $eq->checkAndUpdateCmd('reason', self::REASONS[$decision['reason']] ?? $decision['reason']);
        $eq->checkAndUpdateCmd('e_day', round($eDay / 1000.0, 3));

        return $decision['action'] !== null && !empty($decision['auto']);
    }

    /** Référence d'action supplémentaire « #…# » résolue vers une commande de type action. */
    public static function isActionRef($ref): bool
    {
        $cmd = self::externalCmd(preg_match('/^#(\d+)#$/', $ref, $m) ? $m[1] : $ref);
        return is_object($cmd) && $cmd->getType() === 'action';
    }

    /**
     * Lance les actions supplémentaires de $action (on / off) dans un processus détaché
     * (core/php/myfroniusActions.php) : le callback du démon rend la main sans attendre un wait, un
     * sleep ou une commande lente, et les envois suivants (site, batterie, bornes) ne sont pas retardés.
     */
    private static function launchActions(myfronius $eq, string $action): void
    {
        $script = realpath(__DIR__ . '/../php/myfroniusActions.php');
        call_user_func(self::$launcher, self::detachedCommand($script, log::getPathToLog('myfronius'), (int) $eq->getId(), $action));
        log::add('myfronius', 'debug', '[load] ' . $eq->getName() . ' : actions supplémentaires « ' . $action . ' » lancées en arrière-plan');
    }

    /**
     * Exécute dans l'ordre les actions supplémentaires de $action (on / off), relues dans la
     * configuration (appelé par core/php/myfroniusActions.php). Un verrou par charge enchaîne les
     * listes successives (extinction après un allumage dont un wait est en cours) sans les mélanger.
     * L'échec d'une action est journalisé sans interrompre les suivantes.
     */
    public static function executeActions(myfronius $eq, string $action): void
    {
        [$list] = self::splitActions(self::actionList($eq->getConfiguration('actions_' . $action, [])), [self::class, 'isActionRef']);
        if (empty($list)) {
            return;
        }
        $lock = @fopen(jeedom::getTmpFolder('myfronius') . '/actions_' . $eq->getId() . '.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            foreach ($list as $extra) {
                try {
                    scenarioExpression::createAndExec('action', $extra['cmd'], $extra['options']);
                } catch (Exception $e) {
                    log::add('myfronius', 'error', '[load] ' . $eq->getName() . ' : échec de « ' . $extra['cmd'] . ' » : ' . $e->getMessage());
                }
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function integrateDay(myfronius $eq, float $power, int $now): float
    {
        $today   = date('Y-m-d', $now);
        $day     = $eq->getCache('load_e_day_date', '');
        $eDay    = (float) $eq->getCache('load_e_day_wh', 0);
        $lastT   = $eq->getCache('load_e_last_t', null);
        $lastP   = (float) $eq->getCache('load_e_last_p', 0);
        if ($day !== $today) {
            $eDay = 0.0;
            $eq->setCache('load_e_day_date', $today);
        } elseif ($lastT !== null && $now > (int) $lastT && $now - (int) $lastT <= 120) {
            $eDay += ($lastP + $power) / 2.0 * ($now - (int) $lastT) / 3600.0;
        }
        $eq->setCache('load_e_day_wh', $eDay);
        $eq->setCache('load_e_last_t', $now);
        $eq->setCache('load_e_last_p', $power);
        return $eDay;
    }

    private static function cmdValue(myfronius $eq, string $logicalId, myfronius $fallbackEq, string $fallbackId): float
    {
        $cmd = $eq->getCmd('info', $logicalId);
        if (!is_object($cmd)) {
            $cmd = $fallbackEq->getCmd('info', $fallbackId);
        }
        return is_object($cmd) ? (float) $cmd->execCmd() : 0.0;
    }

    /**
     * Évalue le champ d'autorisation avec l'évaluateur de Jeedom (même syntaxe que les conditions de
     * scénario) : « #[Objet][Équipement][Commande]# », « #[…]# < 55 AND #[…]# == 1 », « 1 »…
     * Vide → true. Non évaluable (commande introuvable, syntaxe) → null.
     * L'évaluateur ne résout que les #id# : la forme lisible est d'abord convertie, comme le fait
     * le core pour les conditions de scénario.
     */
    public static function evaluateAllow(string $expression): ?bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return true;
        }
        try {
            $expression = jeedom::fromHumanReadable($expression);
            // Forme lisible restée non résolue (commande renommée ou supprimée) → introuvable
            if (preg_match('/#\[[^#]*\]\[[^#]*\]\[[^#]*\]#/', $expression)) {
                return null;
            }
            $result = jeedom::evaluateExpression($expression);
        } catch (Exception $e) {
            return null;
        }
        return self::toBool($result, true);   // null si l'évaluateur a renvoyé l'entrée telle quelle
    }

    /**
     * Convertit une valeur de commande Jeedom en booléen : 0/1, true/false, on/off, oui/non,
     * vrai/faux, nombres. $strict : renvoie null pour une chaîne non reconnue (au lieu de false).
     */
    public static function toBool($value, bool $strict = false): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return $strict ? null : false;
        }
        if (is_numeric($value)) {
            return (float) $value != 0.0;
        }
        $lower = strtolower(trim((string) $value));
        if (in_array($lower, ['true', 'on', 'oui', 'vrai', 'yes'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'off', 'non', 'faux', 'no', ''], true)) {
            return false;
        }
        return $strict ? null : false;
    }

    /**
     * Commande externe désignée par sa forme lisible « #[Objet][Équipement][Commande]# » (convention
     * Jeedom, résolue par cmd::byString) ou, par compatibilité, par son identifiant numérique.
     */
    public static function externalCmd($ref)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        if (ctype_digit($ref)) {
            $cmd = cmd::byId((int) $ref);
            return is_object($cmd) ? $cmd : null;
        }
        try {
            $cmd = cmd::byString($ref);
        } catch (Exception $e) {
            return null;
        }
        return is_object($cmd) ? $cmd : null;
    }
}
