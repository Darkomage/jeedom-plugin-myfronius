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
 * Client Modbus TCP minimal en lecture seule (fonction 03), utilisé uniquement par le bouton
 * « Tester Modbus » : présence de la carte SunSpec, localisation du modèle 124 (stockage) et
 * lecture des registres de contrôle. Les écritures sont réservées au démon (spec §12).
 */
class FroniusModbus
{
    const STORAGE_MODEL = 124;

    private $host;
    private $port;
    private $unit;
    private $timeout;
    private $tid = 0;

    public function __construct(string $host, int $port = 502, int $unit = 1, int $timeout = 3)
    {
        $host = preg_replace('#^https?://#i', '', trim($host));
        $this->host    = preg_replace('#[:/].*$#', '', $host);
        $this->port    = $port;
        $this->unit    = $unit;
        $this->timeout = $timeout;
    }

    /** Lit $count registres de maintien à partir de $address (adresse protocole, base 0). */
    public function read(int $address, int $count): array
    {
        $this->tid = ($this->tid + 1) & 0xFFFF;
        $request = pack('nnnCCnn', $this->tid, 0, 6, $this->unit, 3, $address, $count);
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($fp === false) {
            throw new Exception(__('Modbus TCP injoignable', __FILE__) . ' (' . $this->host . ':' . $this->port . ') : ' . $errstr);
        }
        stream_set_timeout($fp, $this->timeout);
        fwrite($fp, $request);
        $head = $this->readExact($fp, 6);
        $len  = unpack('n', substr($head, 4, 2))[1];
        $body = $this->readExact($fp, $len);
        fclose($fp);
        $frame = $head . $body;
        $fn = ord($frame[7]);
        if ($fn === (3 | 0x80)) {
            $code = ord($frame[8]);
            $names = [1 => 'ILLEGAL FUNCTION', 2 => 'ILLEGAL DATA ADDRESS', 3 => 'ILLEGAL DATA VALUE', 4 => 'SLAVE DEVICE FAILURE'];
            throw new Exception('Modbus exception ' . $code . ' (' . ($names[$code] ?? '?') . ')');
        }
        if ($fn !== 3) {
            throw new Exception('Modbus : fonction inattendue ' . $fn);
        }
        $bytes = ord($frame[8]);
        $regs  = array_values(unpack('n*', substr($frame, 9, $bytes)));
        return $regs;
    }

    private function readExact($fp, int $size): string
    {
        $buf = '';
        while (strlen($buf) < $size) {
            $chunk = fread($fp, $size - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new Exception(__('Modbus : réponse tronquée ou connexion fermée', __FILE__));
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function signed(int $v): int
    {
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /** Parcourt la chaîne SunSpec ; renvoie ['base' => …, 'length' => …] ou null si le modèle est absent. */
    public function findModel(int $modelId): ?array
    {
        $head = $this->read(40000, 2);
        if ($head[0] !== 0x5375 || $head[1] !== 0x6E53) {
            throw new Exception(__('Pas de carte SunSpec en 40000 : Modbus TCP activé ? unit ID correct ?', __FILE__));
        }
        $pos = 40002;
        for ($i = 0; $i < 50; $i++) {
            [$id, $len] = $this->read($pos, 2);
            if ($id === 0xFFFF) {
                return null;
            }
            if ($id === $modelId) {
                return ['base' => $pos + 2, 'length' => $len];
            }
            $pos += 2 + $len;
        }
        throw new Exception(__('Chaîne SunSpec trop longue', __FILE__));
    }

    /** Résumé lisible pour le bouton de test. */
    public function probe(): array
    {
        $model = $this->findModel(self::STORAGE_MODEL);
        if ($model === null) {
            return ['storage' => false];
        }
        $r  = $this->read($model['base'], 24);
        $sf = function (int $off) use ($r) { return pow(10, self::signed($r[$off])); };
        return [
            'storage'    => true,
            'base'       => $model['base'],
            'wchamax'    => (int) round($r[0] * $sf(16)),
            'storctl'    => $r[3],
            'minrsv'     => round($r[5] * $sf(19), 1),
            'soc'        => round($r[6] * $sf(20), 1),
            'outwrte'    => round(self::signed($r[10]) * $sf(23), 1),
            'inwrte'     => round(self::signed($r[11]) * $sf(23), 1),
            'rvrttms'    => $r[13],
        ];
    }
}
