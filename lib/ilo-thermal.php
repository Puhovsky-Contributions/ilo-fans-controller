<?php

require_once __DIR__ . '/ilo-zones.php';

/**
 * @return array{
 *   zones: array<string, list<int>>,
 *   ambient: ?int,
 *   fanCount: int,
 * }|null
 */
function fetch_ilo_thermal(array $server): ?array
{
    $curl_handle = curl_init("https://{$server['host']}/redfish/v1/chassis/1/Thermal");
    curl_setopt($curl_handle, CURLOPT_USERPWD, "{$server['username']}:{$server['password']}");
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_handle, CURLOPT_TIMEOUT, 10);

    $raw = curl_exec($curl_handle);
    if (!$raw) {
        return null;
    }

    $data = json_decode($raw, true);
    $zones = array_fill_keys(ilo_temp_zone_names(), []);
    $ambientTemp = null;
    $fanCount = 0;

    if (isset($data['Temperatures'])) {
        foreach ($data['Temperatures'] as $temp) {
            $name = $temp['Name'] ?? '';
            $reading = $temp['ReadingCelsius'] ?? null;
            $status = $temp['Status']['State'] ?? 'Unknown';

            if ($reading === null || $status !== 'Enabled') {
                continue;
            }

            $zone = get_temp_zone($name);
            $zones[$zone][] = (int) $reading;

            if ($zone === 'ambient') {
                $ambientTemp = (int) $reading;
            }
        }
    }

    if (isset($data['Fans'])) {
        foreach ($data['Fans'] as $fan) {
            if (($fan['Status']['State'] ?? 'Unknown') === 'Enabled') {
                $fanCount++;
            }
        }
    }

    return [
        'zones'    => $zones,
        'ambient'  => $ambientTemp,
        'fanCount' => $fanCount,
    ];
}
