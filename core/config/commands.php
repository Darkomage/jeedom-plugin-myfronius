<?php
/**
 * Catalogue déclaratif des commandes MyFronius — source unique de vérité.
 *
 * Structure : [ typeEquipement => [ logicalId => définition ] ] avec typeEquipement ∈
 * site | battery | inverter | meter | charger | load. Les logicalId info sont exactement les clés
 * écrites par leur source, sinon elles sont ignorées par le callback :
 *   site / battery / inverter / meter : calculateur du démon (resources/demond/myfronius/calculator.py) ;
 *   battery ctrl_*, modbus_ok, wchamax, grid_charge_allowed : pilotage Modbus du démon (modbus.py) ;
 *   charger : liaison WebSocket des bornes (wattpilot.py, charger_output) ;
 *   load    : écrites côté PHP par myfroniusLoadManager (décision des charges pilotées).
 *
 * Définition d'une commande :
 *   name       : nom affiché (préservé si l'utilisateur le modifie ensuite)
 *   subType    : numeric | string | binary
 *   unit       : unité affichée ('' si aucune)
 *   generic    : type générique du core Jeedom ou null. Utilisés : POWER (W), CONSUMPTION (kWh),
 *                VOLTAGE, BATTERY, BATTERY_CHARGING, MODE_STATE (info texte du mode) et
 *                MODE_SET_STATE (action « passer à ce mode »). Exploités notamment par l'assistant
 *                de création de widgets en masse de Jeedom Connect (guide dans la doc).
 *   historized : historisation activée à la création (jamais réimposée ensuite : réglage de l'utilisateur)
 *   repeat / smooth / purge : réglages d'historique, calculés en fin de fichier (table unique des
 *                défauts : répétition, lissage, purge ; voir le commentaire qui les calcule)
 *   visible    : visible sur le dashboard à la création
 *   expert     : true = créée uniquement en mode expert
 *   min / max  : bornes optionnelles (jauges, échelle des graphes)
 *   requires   : condition de disponibilité issue de la découverte :
 *                null | 'storage_ok' | 'modbus' | 'meter_primary' (géré, inutilisé à ce jour)
 *                | 'channel:<canal API>' | 'mppt:<n>'. Toute autre valeur : commande jamais créée
 *                (myfronius::requiresMet, vérifié par tests/test_catalog_consistency.py).
 *
 * Conventions de signe (spec §3) : réseau + = import, batterie + = charge, maison et PV ≥ 0.
 */

$cmd = function (string $name, string $subType = 'numeric', string $unit = '', ?string $generic = null,
                 bool $historized = false, bool $visible = true, bool $expert = false,
                 $min = null, $max = null, ?string $requires = null): array {
    return [
        'name'       => $name,
        'subType'    => $subType,
        'unit'       => $unit,
        'generic'    => $generic,
        'historized' => $historized,
        'visible'    => $visible,
        'expert'     => $expert,
        'min'        => $min,
        'max'        => $max,
        'requires'   => $requires,
    ];
};

// ---------------------------------------------------------------------------------------------
// SITE
// ---------------------------------------------------------------------------------------------
$site = [
    // Flux instantanés
    'p_pv'                => $cmd('Production PV',                 'numeric', 'W', 'POWER',       true,  true),
    'p_load'              => $cmd('Consommation maison',           'numeric', 'W', 'POWER',       true,  true),
    'p_load_ex_ev'        => $cmd('Consommation maison hors bornes','numeric', 'W', 'POWER',       true,  true),
    'p_grid'              => $cmd('Réseau (signé, + import)',      'numeric', 'W', 'POWER',       true,  true),
    'p_grid_import'       => $cmd('Import réseau',                 'numeric', 'W', null,          false, false),
    'p_grid_export'       => $cmd('Export réseau',                 'numeric', 'W', null,          false, false),
    'p_akku'              => $cmd('Batterie (signé, + charge)',    'numeric', 'W', 'POWER',       true,  true),
    'p_akku_charge'       => $cmd('Charge batterie',               'numeric', 'W', null,          false, false),
    'p_akku_discharge'    => $cmd('Décharge batterie',             'numeric', 'W', null,          false, false),
    'surplus'             => $cmd('Surplus disponible',            'numeric', 'W', 'POWER',       true,  true),
    'surplus_batt'        => $cmd('Surplus batterie incluse',      'numeric', 'W', null,          true,  true),
    'rel_autonomy'        => $cmd('Autonomie instantanée',         'numeric', '%', null,          false, true,  false, 0, 100),
    'rel_selfconsumption' => $cmd('Autoconsommation instantanée',  'numeric', '%', null,          false, true,  false, 0, 100),
    'mode'                => $cmd('Mode site',                     'string',  '',  null,          true, false),
    'backup_mode'         => $cmd('Mode secours actif',            'binary',  '',  null,          true, false),
    'battery_standby'     => $cmd('Batterie en veille',            'binary',  '',  null,          false, false),
    'comm_ok'             => $cmd('Communication onduleur OK',     'binary',  '',  null,          true, true),
    'last_update'         => $cmd('Dernière mise à jour',          'string',  '',  null,          false, true),
    'window_complete'     => $cmd('Fenêtre 15 min complète',       'binary',  '',  null,          false, false),
];

// Tendances : 6 bases × (avg1, avg5, avg15, slope5)
$trendBases = [
    'p_pv'         => 'Production PV',
    'p_load'       => 'Consommation maison',
    'p_grid'       => 'Réseau',
    'p_akku'       => 'Batterie',
    'surplus'      => 'Surplus',
    'surplus_batt' => 'Surplus batterie incluse',
];
foreach ($trendBases as $base => $label) {
    $isSurplus = strpos($base, 'surplus') === 0;
    // Seules les moyennes 1 min du surplus sont historisées : ce sont celles sur lesquelles décident
    // les charges pilotées (les autres se recalculent depuis les puissances instantanées)
    $site[$base . '_avg1']   = $cmd($label . ' — moyenne 1 min',  'numeric', 'W',     null, $isSurplus, false);
    $site[$base . '_avg5']   = $cmd($label . ' — moyenne 5 min',  'numeric', 'W',     null, false,      false);
    $site[$base . '_avg15']  = $cmd($label . ' — moyenne 15 min', 'numeric', 'W',     null, false,      false);
    $site[$base . '_slope5'] = $cmd($label . ' — pente 5 min',    'numeric', 'W/min', null, false,      $base === 'surplus');
}

// Énergies du jour (minuit heure locale)
$site += [
    'e_day_pv'                 => $cmd('Production PV du jour',          'numeric', 'kWh', null,                true, true),
    'e_day_import'             => $cmd('Import réseau du jour',          'numeric', 'kWh', null,                true, true),
    'e_day_export'             => $cmd('Export réseau du jour',          'numeric', 'kWh', null,                true, true),
    'e_day_load'               => $cmd('Consommation maison du jour',    'numeric', 'kWh', 'CONSUMPTION',       true, true),
    'e_day_akku_charge'        => $cmd('Charge batterie du jour',        'numeric', 'kWh', null,                true, false),
    'e_day_akku_discharge'     => $cmd('Décharge batterie du jour',      'numeric', 'kWh', null,                true, false),
    'e_day_inverter'           => $cmd('Énergie AC onduleur du jour',    'numeric', 'kWh', null,                false, false, true),
    'rate_day_selfconsumption' => $cmd('Autoconsommation du jour',       'numeric', '%',   null,                true, true,  false, 0, 100),
    'rate_day_autonomy'        => $cmd('Autonomie du jour',              'numeric', '%',   null,                true, true,  false, 0, 100),
];

// ---------------------------------------------------------------------------------------------
// BATTERIE
// ---------------------------------------------------------------------------------------------
$battery = [
    'soc'               => $cmd('État de charge',                 'numeric', '%',   'BATTERY',          true,  true,  false, 0, 100),
    'mode'              => $cmd('Mode batterie',                  'string',  '',    null,               true, true),
    'direction'         => $cmd('Sens (charge/decharge/repos)',   'string',  '',    null,               true, true),
    'charging'          => $cmd('En charge',                      'binary',  '',    'BATTERY_CHARGING', true, false),
    'p'                 => $cmd('Puissance batterie (+ charge)',  'numeric', 'W',   'POWER',            true,  false),
    'standby'           => $cmd('Veille',                         'binary',  '',    null,               true, false),
    'time_to_full'      => $cmd('Temps estimé avant pleine',      'numeric', 'min', null,               false, true),
    'time_to_empty'     => $cmd('Temps estimé avant vide',        'numeric', 'min', null,               false, true),
    'capacity_max'      => $cmd('Capacité utilisable',            'numeric', 'kWh', null,               false, false, true,  null, null, 'storage_ok'),
    'capacity_designed' => $cmd('Capacité nominale',              'numeric', 'kWh', null,               false, false, true,  null, null, 'storage_ok'),
    'voltage_dc'        => $cmd('Tension DC',                     'numeric', 'V',   'VOLTAGE',          false, false, true,  null, null, 'storage_ok'),
    'current_dc'        => $cmd('Courant DC (+ charge)',          'numeric', 'A',   null,               false, false, true,  null, null, 'storage_ok'),
    'temperature'       => $cmd('Température cellule',            'numeric', '°C',  null,               true,  true,  false, null, null, 'storage_ok'),
    'status_cell'       => $cmd('Code état cellule',              'numeric', '',    null,               false, false, true,  null, null, 'storage_ok'),
    'enable'            => $cmd('Batterie gérée',                 'binary',  '',    null,               false, false, true,  null, null, 'storage_ok'),
    // Pilotage Modbus (spec §12) — créées uniquement si le pilotage est activé dans la configuration
    'ctrl_mode'         => $cmd('Pilotage : mode',                'string',  '',    'MODE_STATE',       true, true,  false, null, null, 'modbus'),
    'ctrl_setpoint'     => $cmd('Pilotage : consigne',            'numeric', 'W',   null,               true, true,  false, null, null, 'modbus'),
    'ctrl_reserve'      => $cmd('Pilotage : réserve minimale',    'numeric', '%',   null,               true, true,  false, 0,    100,  'modbus'),
    'ctrl_active'       => $cmd('Pilotage actif',                 'binary',  '',    null,               true, true,  false, null, null, 'modbus'),
    'modbus_ok'         => $cmd('Modbus OK',                      'binary',  '',    null,               true, true,  false, null, null, 'modbus'),
    'wchamax'           => $cmd('Puissance de référence batterie','numeric', 'W',   null,               false, false, true,  null, null, 'modbus'),
    'grid_charge_allowed' => $cmd('Charge réseau autorisée (Fronius)', 'binary', '', null,             false, false, false, null, null, 'modbus'),
];
$action = function (string $name, string $subType = 'other', $min = null, $max = null, string $unit = '', ?string $generic = null): array {
    return ['name' => $name, 'type' => 'action', 'subType' => $subType, 'unit' => $unit, 'generic' => $generic,
            'historized' => false, 'visible' => true, 'expert' => false, 'min' => $min, 'max' => $max, 'requires' => 'modbus'];
};
// Les trois modes sans valeur portent MODE_SET_STATE (widget « Mode » de Jeedom Connect)
$battery['ctrl_auto']         = $action('Gestion automatique (Fronius)', 'other', null, null, '', 'MODE_SET_STATE');
$battery['ctrl_hold']         = $action('Bloquer la décharge', 'other', null, null, '', 'MODE_SET_STATE');
$battery['ctrl_no_charge']    = $action('Bloquer la charge', 'other', null, null, '', 'MODE_SET_STATE');
$battery['ctrl_force_charge'] = $action('Forcer la charge depuis le réseau', 'slider', 0, 20000, 'W');
$battery['ctrl_limit_charge'] = $action('Limiter la charge', 'slider', 0, 20000, 'W');
$battery['ctrl_reserve_set']  = $action('Réserve minimale', 'slider', 0, 100, '%');

// ---------------------------------------------------------------------------------------------
// ONDULEUR
// ---------------------------------------------------------------------------------------------
$inverter = [
    'pac'          => $cmd('Puissance AC',        'numeric', 'W',   'POWER',   true,  true,  false, null, null, 'channel:PAC'),
    'sac'          => $cmd('Puissance apparente', 'numeric', 'VA',  null,      false, false, true,  null, null, 'channel:SAC'),
    'uac'          => $cmd('Tension AC',          'numeric', 'V',   'VOLTAGE', false, true,  false, null, null, 'channel:UAC'),
    'iac'          => $cmd('Courant AC',          'numeric', 'A',   null,      false, true,  false, null, null, 'channel:IAC'),
    'fac'          => $cmd('Fréquence',           'numeric', 'Hz',  null,      false, false, true,  null, null, 'channel:FAC'),
    'total_energy' => $cmd('Énergie AC totale',   'numeric', 'kWh', null,      true,  true,  false, null, null, 'channel:TOTAL_ENERGY'),
    'e_day_ac'     => $cmd('Énergie AC du jour',  'numeric', 'kWh', null,      true,  true,  false, null, null, 'channel:TOTAL_ENERGY'),
    'state'        => $cmd('État',                'string',  '',    null,      true, true,  false, null, null, 'channel:DeviceStatus'),
    'status_code'  => $cmd('Code état',           'numeric', '',    null,      false, false, true,  null, null, 'channel:DeviceStatus'),
    'error_code'   => $cmd('Code erreur',         'numeric', '',    null,      true, true,  false, null, null, 'channel:DeviceStatus'),
];
for ($n = 1; $n <= 4; $n++) {
    $inverter['udc_' . $n] = $cmd('Tension DC MPPT ' . $n,   'numeric', 'V', 'VOLTAGE', false, false, true,  null, null, 'mppt:' . $n);
    $inverter['idc_' . $n] = $cmd('Courant DC MPPT ' . $n,   'numeric', 'A', null,      false, false, true,  null, null, 'mppt:' . $n);
    $inverter['pdc_' . $n] = $cmd('Puissance DC MPPT ' . $n, 'numeric', 'W', null,      true,  true,  false, null, null, 'mppt:' . $n);
}

// ---------------------------------------------------------------------------------------------
// SMART METER
// ---------------------------------------------------------------------------------------------
$meter = [
    'p_sum'               => $cmd('Puissance active totale',     'numeric', 'W',     'POWER', true,  true,  false, null, null, 'channel:PowerReal_P_Sum'),
    'frequency'           => $cmd('Fréquence',                   'numeric', 'Hz',    null,    false, false, true,  null, null, 'channel:Frequency_Phase_Average'),
    's_sum'               => $cmd('Puissance apparente totale',  'numeric', 'VA',    null,    false, false, true,  null, null, 'channel:PowerApparent_S_Sum'),
    'q_sum'               => $cmd('Puissance réactive totale',   'numeric', 'var',   null,    false, false, true,  null, null, 'channel:PowerReactive_Q_Sum'),
    'pf_sum'              => $cmd('Facteur de puissance',        'numeric', '',      null,    false, false, true,  -1,   1,    'channel:PowerFactor_Sum'),
    'e_import_total'      => $cmd('Énergie importée totale',     'numeric', 'kWh',   null,    true,  true,  false, null, null, 'channel:EnergyReal_WAC_Plus_Absolute'),
    'e_export_total'      => $cmd('Énergie exportée totale',     'numeric', 'kWh',   null,    true,  true,  false, null, null, 'channel:EnergyReal_WAC_Minus_Absolute'),
    'e_reactive_consumed' => $cmd('Énergie réactive consommée',  'numeric', 'kvarh', null,    false, false, true,  null, null, 'channel:EnergyReactive_VArAC_Sum_Consumed'),
    'e_reactive_produced' => $cmd('Énergie réactive produite',   'numeric', 'kvarh', null,    false, false, true,  null, null, 'channel:EnergyReactive_VArAC_Sum_Produced'),
    'visible'             => $cmd('Valeurs valides',             'binary',  '',      null,    false, false, true,  null, null, 'channel:Visible'),
];
for ($n = 1; $n <= 3; $n++) {
    $meter['p_l' . $n]  = $cmd('Puissance active L' . $n,    'numeric', 'W',   null,      false, true,  false, null, null, 'channel:PowerReal_P_Phase_' . $n);
    $meter['u_l' . $n]  = $cmd('Tension L' . $n,             'numeric', 'V',   'VOLTAGE', false, true,  false, null, null, 'channel:Voltage_AC_Phase_' . $n);
    $meter['i_l' . $n]  = $cmd('Courant L' . $n,             'numeric', 'A',   null,      false, true,  false, null, null, 'channel:Current_AC_Phase_' . $n);
    $meter['s_l' . $n]  = $cmd('Puissance apparente L' . $n, 'numeric', 'VA',  null,      false, false, true,  null, null, 'channel:PowerApparent_S_Phase_' . $n);
    $meter['q_l' . $n]  = $cmd('Puissance réactive L' . $n,  'numeric', 'var', null,      false, false, true,  null, null, 'channel:PowerReactive_Q_Phase_' . $n);
    $meter['pf_l' . $n] = $cmd('Facteur de puissance L' . $n,'numeric', '',    null,      false, false, true,  -1,   1,    'channel:PowerFactor_Phase_' . $n);
}
foreach (['12', '23', '31'] as $pair) {
    $meter['u_l' . $pair] = $cmd('Tension composée L' . $pair, 'numeric', 'V', null, false, false, true, null, null, 'channel:Voltage_AC_PhaseToPhase_' . $pair);
}

// ---------------------------------------------------------------------------------------------
// BORNE WATTPILOT (spec §11) — créée manuellement, alimentée par la liaison WebSocket du démon
// ---------------------------------------------------------------------------------------------
$charger = [
    'p'                 => $cmd('Puissance de charge',          'numeric', 'W',   'POWER', true,  true),
    'car'               => $cmd('État voiture (code)',          'numeric', '',    null,    true, false),
    'car_text'          => $cmd('État voiture',                 'string',  '',    null,    true, true),
    'mode_code'         => $cmd('Mode (code)',                  'numeric', '',    null,    true, false),
    'mode'              => $cmd('Mode',                         'string',  '',    'MODE_STATE', true, true),
    'amp'               => $cmd('Courant réglé',                'numeric', 'A',   null,    true, false),
    'ama'               => $cmd('Courant max installation',     'numeric', 'A',   null,    false, false),
    'acu'               => $cmd('Courant autorisé',             'numeric', 'A',   null,    true, false),
    'phases'            => $cmd('Phases utilisées',             'numeric', '',    null,    true, false, false, 0, 3),
    'psm'               => $cmd('Commutation de phases (code)', 'numeric', '',    null,    false, false, true),
    'alw'               => $cmd('Charge autorisée',             'binary',  '',    null,    true, false),
    'frc'               => $cmd('Forçage (code)',               'numeric', '',    null,    true, false),
    'force_text'        => $cmd('Forçage',                      'string',  '',    null,    true, true),
    'model_status'      => $cmd('Raison (code)',                'numeric', '',    null,    false, false, true),
    'model_status_text' => $cmd('Raison de l\'état',            'string',  '',    null,    true, false),
    'e_session'         => $cmd('Énergie de la session',        'numeric', 'kWh', null,    true,  true),
    'e_total'           => $cmd('Énergie totale',               'numeric', 'kWh', null,    true,  false),
    'fst'               => $cmd('Seuil de démarrage surplus',   'numeric', 'W',   null,    true, false),
    'fam'               => $cmd('Limite batterie surplus',      'numeric', '%',   null,    true, false, false, 0, 100),
    'fup'               => $cmd('Charge sur surplus',           'binary',  '',    null,    true, false),
    'fte'               => $cmd('Énergie prochain trajet',      'numeric', 'kWh', null,    false, false),
    'trip_km'           => $cmd('Distance prochain trajet',     'numeric', 'km',  null,    false, false),
    'ftt'               => $cmd('Heure prochain trajet',        'string',  '',    null,    false, false),
    'cco'               => $cmd('Consommation du véhicule',     'numeric', 'kWh/100km', null, false, false),
    'temperature'       => $cmd('Température',                  'numeric', '°C',  null,    false, false, true),
    'connected'         => $cmd('Connexion borne',              'binary',  '',    null,    true, true),
    'fwv'               => $cmd('Firmware',                     'string',  '',    null,    false, false, true),
];
for ($n = 1; $n <= 3; $n++) {
    $charger['p_l' . $n] = $cmd('Puissance L' . $n, 'numeric', 'W', null,      false, false, true);
    $charger['u_l' . $n] = $cmd('Tension L' . $n,   'numeric', 'V', 'VOLTAGE', false, false, true);
    $charger['i_l' . $n] = $cmd('Courant L' . $n,   'numeric', 'A', null,      false, false, true);
}
// Actions (type action, sous-type other sauf mode_set = select)
$charger['mode_prev']     = ['name' => 'Mode précédent', 'type' => 'action', 'subType' => 'other',  'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
$charger['mode_next']     = ['name' => 'Mode suivant',   'type' => 'action', 'subType' => 'other',  'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
$charger['mode_set']      = ['name' => 'Choisir le mode', 'type' => 'action', 'subType' => 'select', 'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null,
                             'listValue' => '3|Défaut;4|Éco;5|Prochain trajet',
                             'value' => 'mode_code'];   // commande info liée : le sélecteur affiche le mode actif
// Un bouton par mode (MODE_SET_STATE, widget « Mode » de Jeedom Connect) ; nommés comme les valeurs
// de l'info « Mode » pour que l'application puisse repérer le mode actif
$chargerMode = function (string $name): array {
    return ['name' => $name, 'type' => 'action', 'subType' => 'other', 'unit' => '', 'generic' => 'MODE_SET_STATE',
            'historized' => false, 'visible' => false, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
};
$charger['mode_default']  = $chargerMode('Défaut');
$charger['mode_eco']      = $chargerMode('Éco');
$charger['mode_trip']     = $chargerMode('Prochain trajet');
$charger['force_on']      = ['name' => 'Forcer la charge', 'type' => 'action', 'subType' => 'other', 'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
$charger['force_off']     = ['name' => 'Forcer l\'arrêt',  'type' => 'action', 'subType' => 'other', 'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
$charger['force_neutral'] = ['name' => 'Retour automatique', 'type' => 'action', 'subType' => 'other', 'unit' => '', 'generic' => null, 'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
// Réglages écrits sur la borne (clés amp, fte + esk, ftt, cco ; traduction dans wattpilot.py), chacun
// lié à l'info qu'il règle (sélecteur positionné sur la valeur courante hors widget dédié). Le widget
// Borne (template charger) les présente avec leurs valeurs ; absents du widget Site. Le courant est
// borné par le démon à [6 A, limite d'installation `ama`] ; la distance est convertie en énergie avec
// la consommation du véhicule (refusée si elle est inconnue) ; l'heure est un texte HH:MM (sous-type
// message : champ libre dans les scénarios). Noms sans apostrophe : Jeedom les retire à l'affichage
$chargerSetting = function (string $name, string $subType, string $info, $min = null, $max = null, string $unit = ''): array {
    return ['name' => $name, 'type' => 'action', 'subType' => $subType, 'unit' => $unit, 'generic' => null,
            'historized' => false, 'visible' => true, 'expert' => false, 'min' => $min, 'max' => $max, 'requires' => null,
            'value' => $info];
};
$charger['amp_set']         = $chargerSetting('Courant max',           'slider',  'amp',     6,  32,   'A');
$charger['trip_energy_set'] = $chargerSetting('Énergie du trajet',     'slider',  'fte',     1,  100,  'kWh');
$charger['trip_km_set']     = $chargerSetting('Distance du trajet',    'slider',  'trip_km', 10, 1000, 'km');
$charger['trip_time_set']   = $chargerSetting('Heure du trajet',       'message', 'ftt');
$charger['cco_set']         = $chargerSetting('Consommation véhicule', 'slider',  'cco',     5,  40,   'kWh/100km');

// ---------------------------------------------------------------------------------------------
// CHARGE PILOTÉE (spec §13) — créée manuellement, décidée côté PHP à chaque cycle
// ---------------------------------------------------------------------------------------------
$loadAction = function (string $name, ?string $generic = null): array {
    return ['name' => $name, 'type' => 'action', 'subType' => 'other', 'unit' => '', 'generic' => $generic,
            'historized' => false, 'visible' => true, 'expert' => false, 'min' => null, 'max' => null, 'requires' => null];
};
$load = [
    'p'         => $cmd('Puissance',              'numeric', 'W',   'POWER', true,  true),
    'state'     => $cmd('État',                   'binary',  '',    null,    true,  true),
    'mode'      => $cmd('Mode (auto / on / off)', 'string',  '',    'MODE_STATE', true, true),
    'reason'    => $cmd('Raison',                 'string',  '',    null,    true, true),
    'e_day'     => $cmd('Énergie absorbée du jour','numeric', 'kWh', null,    true,  true),
    'mode_auto' => $loadAction('Automatique (surplus)', 'MODE_SET_STATE'),
    'mode_on'   => $loadAction('Forcer la marche', 'MODE_SET_STATE'),
    'mode_off'  => $loadAction('Forcer l\'arrêt', 'MODE_SET_STATE'),
    'mode_prev' => $loadAction('Mode précédent'),
    'mode_next' => $loadAction('Mode suivant'),
];

$catalog = [
    'site'     => $site,
    'battery'  => $battery,
    'inverter' => $inverter,
    'meter'    => $meter,
    'charger'  => $charger,
    'load'     => $load,
];

// Réglages d'historique (table unique des défauts, clés de configuration du core vérifiées dans
// cmd.class.php / history.class.php 4.6.1). Posés à la création d'une commande ; sur une installation
// existante, la migration unique active l'historisation manquante et complète les réglages vides, sans
// jamais remplacer un choix de l'utilisateur ni retirer une historisation :
//   repeat : repeatEventManagement « never » — textes, binaires et puissances des bornes et des charges
//            pilotées (souvent constantes, à 0 hors charge) : seuls les changements sont enregistrés.
//            Vérifié dans le core : checkAndUpdateCmd ne déclenche ni événement ni historique sur une
//            valeur identique, numériques compris ; « never » est aussi le défaut du core ;
//   smooth : historizeMode « avg » pour les puissances instantanées : détail de chaque cycle tant que
//            les points sont récents, moyenne horaire au-delà du « Délai avant archivage » global de
//            Jeedom (réglage conseillé : 336 h = 14 jours) ;
//   purge  : historyPurge « never » pour toute commande historisée (explicite : '' suivrait la purge
//            globale de Jeedom). Consignes et seuils en W sont des réglages, pas des puissances.
$powerSettings = ['ctrl_setpoint', 'wchamax', 'fst'];
$repeatNever   = ['charger' => ['p'], 'load' => ['p']];
foreach ($catalog as $type => $cmds) {
    foreach ($cmds as $logicalId => $def) {
        if (($def['type'] ?? 'info') !== 'info') {
            continue;
        }
        $isPower = $def['subType'] === 'numeric' && $def['unit'] === 'W' && !in_array($logicalId, $powerSettings, true);
        $never   = in_array($def['subType'], ['string', 'binary'], true) || in_array($logicalId, $repeatNever[$type] ?? [], true);
        $catalog[$type][$logicalId]['repeat'] = $never ? 'never' : '';
        $catalog[$type][$logicalId]['smooth'] = $isPower ? 'avg' : '';
        $catalog[$type][$logicalId]['purge']  = 'never';
    }
}

return $catalog;
