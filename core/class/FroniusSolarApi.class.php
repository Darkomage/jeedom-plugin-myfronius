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
 * Client PHP minimal de la Fronius Solar API V1 (cURL).
 *
 * Utilisé uniquement côté Jeedom pour le test de connexion et la découverte quand le démon est
 * arrêté. Même contrat que le client Python : renvoie Body.Data déballé, lève une Exception si
 * Head.Status.Code ≠ 0. La découverte reprend exactement les règles de
 * resources/demond/myfronius/discovery.py.
 */
class FroniusSolarApi
{
    private $baseUrl;
    private $rootUrl;
    private $timeout;

    /** Index MPPT → [canal tension, canal courant] dans CommonInverterData. */
    const MPPT_KEYS = [
        1 => ['UDC', 'IDC'],
        2 => ['UDC_2', 'IDC_2'],
        3 => ['UDC_3', 'IDC_3'],
        4 => ['UDC_4', 'IDC_4'],
    ];

    public function __construct(string $host, int $timeout = 5)
    {
        $host = trim($host);
        if (stripos($host, 'http://') !== 0 && stripos($host, 'https://') !== 0) {
            $host = 'http://' . $host;
        }
        $this->rootUrl = rtrim($host, '/') . '/';
        $this->baseUrl = $this->rootUrl . 'solar_api/v1/';
        $this->timeout = $timeout;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /*     * *********************Bas niveau************************* */

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $err !== '') {
            throw new Exception(__('Onduleur injoignable', __FILE__) . ' (' . $url . ') : ' . $err);
        }
        if ($code === 404) {
            throw new Exception(__('Solar API indisponible (404). Sur GEN24, activez-la dans Communication > Solar API.', __FILE__));
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception(__('Réponse HTTP inattendue', __FILE__) . ' ' . $code . ' (' . $url . ')');
        }
        return $body;
    }

    private function getJson(string $url): array
    {
        $doc = json_decode($this->httpGet($url), true);
        if (!is_array($doc)) {
            throw new Exception(__('Réponse non JSON de l\'onduleur', __FILE__) . ' (' . $url . ')');
        }
        return $doc;
    }

    private function getData(string $url): array
    {
        $doc    = $this->getJson($url);
        $status = $doc['Head']['Status'] ?? [];
        $code   = isset($status['Code']) ? (int) $status['Code'] : -1;
        if ($code !== 0) {
            $reason = $status['Reason'] ?? ($status['UserMessage'] ?? '');
            throw new Exception('Solar API erreur ' . $code . ' : ' . $reason . ' (' . $url . ')');
        }
        $data = $doc['Body']['Data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /*     * *********************Requêtes************************* */

    public function apiVersion(): array
    {
        $doc = $this->getJson($this->rootUrl . 'solar_api/GetAPIVersion.cgi');
        if (!isset($doc['APIVersion'])) {
            throw new Exception(__('GetAPIVersion illisible', __FILE__));
        }
        return $doc;
    }

    public function powerFlow(): array
    {
        return $this->getData($this->baseUrl . 'GetPowerFlowRealtimeData.fcgi');
    }

    public function inverter(string $deviceId): array
    {
        return $this->getData($this->baseUrl . 'GetInverterRealtimeData.cgi?Scope=Device&DeviceId='
            . urlencode($deviceId) . '&DataCollection=CommonInverterData');
    }

    public function meters(): array
    {
        return $this->getData($this->baseUrl . 'GetMeterRealtimeData.cgi?Scope=System');
    }

    public function inverterInfo(): array
    {
        return $this->getData($this->baseUrl . 'GetInverterInfo.cgi');
    }

    public function loggerInfo(): array
    {
        $doc    = $this->getJson($this->baseUrl . 'GetLoggerInfo.cgi');
        $status = $doc['Head']['Status'] ?? [];
        if ((int) ($status['Code'] ?? -1) !== 0) {
            throw new Exception('Solar API erreur ' . ($status['Code'] ?? '?') . ' : ' . ($status['Reason'] ?? ''));
        }
        $info = $doc['Body']['LoggerInfo'] ?? [];
        return is_array($info) ? $info : [];
    }

    public function storages(): array
    {
        return $this->getData($this->baseUrl . 'GetStorageRealtimeData.cgi?Scope=System');
    }

    /*     * *********************Découverte************************* */

    private static function nodeValue($node)
    {
        if (is_array($node)) {
            return $node['Value'] ?? null;
        }
        return $node;
    }

    private static function presentChannels(array $data): array
    {
        $channels = [];
        foreach ($data as $key => $node) {
            if ($key === 'Details') {
                continue;
            }
            if ($key === 'DeviceStatus' || self::nodeValue($node) !== null) {
                $channels[] = $key;
            }
        }
        sort($channels);
        return $channels;
    }

    /**
     * Meter primaire : emplacement 0 (point d'injection) uniquement. Un meter côté conso (1) mesure
     * les charges et non l'échange réseau : import/export déduits de ∫p_grid par le démon (spec §3.4).
     */
    public static function primaryMeterId(array $meters): ?string
    {
        foreach ($meters as $deviceId => $meter) {
            if (isset($meter['Meter_Location_Current']) && (int) $meter['Meter_Location_Current'] === 0) {
                return (string) $deviceId;
            }
        }
        return null;
    }

    /** Premier stockage exploitable (contrôleur non vide, spec §4), null sinon. */
    public static function firstStorageWithController(array $storages): ?string
    {
        ksort($storages, SORT_STRING);
        foreach ($storages as $deviceId => $storage) {
            if (!empty($storage['Controller']) && is_array($storage['Controller'])) {
                return (string) $deviceId;
            }
        }
        return null;
    }

    /**
     * Décrit l'installation (même structure que discovery.py, spec §4).
     */
    public static function discover(FroniusSolarApi $api): array
    {
        $powerFlow   = $api->powerFlow();
        $site        = $powerFlow['Site'] ?? [];
        $pfInverters = $powerFlow['Inverters'] ?? [];

        $result = [
            'site' => [
                'mode'              => $site['Mode'] ?? null,
                'powerflow_version' => (int) ($powerFlow['Version'] ?? 0),
                'meter_location'    => $site['Meter_Location'] ?? null,
            ],
            'battery' => [
                'present'           => false,
                'storage_ok'        => false,
                'storage_id'        => null,
                'manufacturer'      => null,
                'model'             => null,
                'serial'            => null,
                'capacity_max'      => null,
                'capacity_designed' => null,
            ],
            'inverters'     => [],
            'meters'        => [],
            'primary_meter' => null,
        ];

        $hasSoc = false;
        foreach ($pfInverters as $inv) {
            if (is_array($inv) && isset($inv['SOC'])) {
                $hasSoc = true;
            }
        }
        $result['battery']['present'] = isset($site['P_Akku']) || $hasSoc;

        // Infos statiques optionnelles
        try {
            $infos = $api->inverterInfo();
        } catch (Exception $e) {
            $infos = [];
        }
        try {
            $logger = $api->loggerInfo();
            $result['site']['firmware']  = (string) ($logger['SWVersion'] ?? '');
            $result['site']['product']   = (string) ($logger['ProductID'] ?? '');
            $result['site']['logger_id'] = (string) ($logger['UniqueID'] ?? '');
        } catch (Exception $e) {
            // datalogger muet : sans importance
        }

        ksort($pfInverters, SORT_STRING);
        foreach ($pfInverters as $deviceId => $pfInv) {
            $info  = $infos[(string) $deviceId] ?? [];
            // CustomName = nom donné dans l'onduleur, pas le modèle (fourni par Modbus, démon)
            $customName = trim(html_entity_decode((string) ($info['CustomName'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $entry = [
                'device_id'    => (string) $deviceId,
                'device_type'  => isset($pfInv['DT']) ? (int) $pfInv['DT'] : null,
                'manufacturer' => 'Fronius',
                'model'        => null,
                'custom_name'  => $customName !== '' ? $customName : null,
                'serial'       => isset($info['UniqueID']) && (string) $info['UniqueID'] !== '' ? (string) $info['UniqueID'] : null,
                'pv_power'     => !empty($info['PVPower']) ? (int) $info['PVPower'] : null, // 0 sur GEN24 (invalide)
                'mppt'         => [],
                'channels'     => [],
            ];
            try {
                $data = $api->inverter((string) $deviceId);
            } catch (Exception $e) {
                log::add('myfronius', 'warning', '[discovery] onduleur ' . $deviceId . ' indisponible : ' . $e->getMessage());
                $data = [];
            }
            $entry['channels'] = self::presentChannels($data);
            foreach (self::MPPT_KEYS as $index => $keys) {
                if (self::nodeValue($data[$keys[0]] ?? null) !== null && self::nodeValue($data[$keys[1]] ?? null) !== null) {
                    $entry['mppt'][] = $index;
                }
            }
            $result['inverters'][] = $entry;
        }

        try {
            $meters = $api->meters();
        } catch (Exception $e) {
            log::add('myfronius', 'warning', '[discovery] compteurs indisponibles : ' . $e->getMessage());
            $meters = [];
        }
        ksort($meters, SORT_STRING);
        foreach ($meters as $deviceId => $meter) {
            $details = $meter['Details'] ?? [];
            $result['meters'][] = [
                'device_id'    => (string) $deviceId,
                'manufacturer' => $details['Manufacturer'] ?? null,
                'model'        => $details['Model'] ?? null,
                'serial'       => $details['Serial'] ?? null,
                'location'     => isset($meter['Meter_Location_Current']) ? (int) $meter['Meter_Location_Current'] : null,
                'channels'     => self::presentChannels($meter),
            ];
        }
        $result['primary_meter'] = self::primaryMeterId($meters);
        if ($result['primary_meter'] === null && in_array(1, array_column($result['meters'], 'location'), true)) {
            log::add('myfronius', 'info', '[discovery] meter côté conso (emplacement 1) : import/export déduits de ∫p_grid');
        }

        if ($result['battery']['present']) {
            try {
                $storages = $api->storages();
            } catch (Exception $e) {
                log::add('myfronius', 'warning', '[discovery] stockage non exploitable : ' . $e->getMessage());
                $storages = [];
            }
            $deviceId = self::firstStorageWithController($storages);
            if ($deviceId !== null) {
                $controller = $storages[$deviceId]['Controller'];
                $details    = $controller['Details'] ?? [];
                $result['battery'] = array_merge($result['battery'], [
                    'storage_ok'        => true,
                    'storage_id'        => (string) $deviceId,
                    'manufacturer'      => $details['Manufacturer'] ?? null,
                    'model'             => $details['Model'] ?? null,
                    'serial'            => $details['Serial'] ?? null,
                    'capacity_max'      => $controller['Capacity_Maximum'] ?? null,
                    'capacity_designed' => $controller['DesignedCapacity'] ?? null,
                ]);
            }
        }

        return $result;
    }
}
