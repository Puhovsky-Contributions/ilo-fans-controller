<?php

/**
 * Proxmox node disk temperatures via API token.
 * List: GET /nodes/{node}/disks/list — no temperature field.
 * Per disk: GET /nodes/{node}/disks/smart?disk={devpath}
 */

function get_proxmox_config(?array $server = null): ?array
{
    $host = trim($server['proxmoxHost'] ?? getenv('PROXMOX_HOST') ?: '');
    $node = trim($server['proxmoxNode'] ?? getenv('PROXMOX_NODE') ?: '');
    $token = trim($server['proxmoxApiToken'] ?? getenv('PROXMOX_API_TOKEN') ?: '');

    if ($host === '' || $node === '' || $token === '') {
        return null;
    }

    if (stripos($token, 'PVEAPIToken=') === 0) {
        $token = substr($token, strlen('PVEAPIToken='));
    }

    $portRaw = $server['proxmoxPort'] ?? getenv('PROXMOX_PORT') ?: '8006';
    $port = (int) $portRaw;
    if ($port <= 0 || $port > 65535) {
        $port = 8006;
    }

    return ['host' => $host, 'node' => $node, 'token' => $token, 'port' => $port];
}

function parse_disk_smart_temperature(array $smart): ?int
{
    $text = $smart['text'] ?? '';
    if ($text === '') {
        return null;
    }

    if (preg_match('/Temperature:\s+(\d+)\s+Celsius/i', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/Current Drive Temperature:\s*(\d+)\s*C/i', $text, $m)) {
        return (int) $m[1];
    }

    if (preg_match_all('/Temperature Sensor \d+:\s+(\d+)\s+Celsius/i', $text, $matches)) {
        return max(array_map('intval', $matches[1]));
    }

    return null;
}

function proxmox_api_get(array $cfg, string $path, array $query = []): mixed
{
    $port = $cfg['port'] ?? 8006;
    $url = 'https://' . $cfg['host'] . ':' . $port . '/api2/json' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: PVEAPIToken=' . $cfg['token'],
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
        return null;
    }

    return $decoded['data'];
}

function proxmox_api_post(array $cfg, string $path, array $form): mixed
{
    $port = $cfg['port'] ?? 8006;
    $url = 'https://' . $cfg['host'] . ':' . $port . '/api2/json' . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: PVEAPIToken=' . $cfg['token'],
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
        return null;
    }

    return $decoded['data'];
}

function proxmox_execute_command(array $cfg, string $command): ?string
{
    $data = proxmox_api_post($cfg, '/nodes/' . rawurlencode($cfg['node']) . '/execute', [
        'commands' => $command,
    ]);
    if ($data === null) {
        return null;
    }

    if (is_string($data)) {
        return $data;
    }

    if (is_array($data)) {
        if (isset($data['out-data']) && is_string($data['out-data'])) {
            return $data['out-data'];
        }
        if (isset($data[0]) && is_string($data[0])) {
            return $data[0];
        }
        $exit = $data['exitcode'] ?? $data['exit-code'] ?? 0;
        if ($exit !== 0) {
            return null;
        }
        foreach (['out-data', 'output', 'stdout'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }
    }

    return null;
}

/**
 * @return list<array{name: string, temp: int}>
 */
function parse_coretemp_from_sensors_json(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $readings = [];
    foreach ($data as $chipData) {
        if (!is_array($chipData)) {
            continue;
        }
        foreach ($chipData as $label => $props) {
            if (!is_array($props) || !preg_match('/^(Package id \d+|Core \d+)$/i', (string) $label)) {
                continue;
            }
            foreach ($props as $key => $value) {
                if (preg_match('/^temp\d+_input$/', (string) $key) && is_numeric($value)) {
                    $readings[] = [
                        'name' => (string) $label,
                        'temp' => (int) round((float) $value),
                    ];
                    break;
                }
            }
        }
    }

    return $readings;
}

/**
 * @return list<array{name: string, temp: int}>
 */
function parse_coretemp_from_sensors_text(string $text): array
{
    $readings = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (!preg_match('/^(Package id \d+|Core \d+):\s+\+?([\d.]+)\s*(?:°| )?\s*C/i', trim($line), $m)) {
            continue;
        }
        $readings[] = [
            'name' => $m[1],
            'temp' => (int) round((float) $m[2]),
        ];
    }

    return $readings;
}

/**
 * Host CPU temps from lm-sensors on the PVE node (coretemp Package + Cores).
 * Requires API token permission for POST /nodes/{node}/execute (e.g. Sys.Mod).
 *
 * @return list<array{name: string, temp: int}>
 */
function get_proxmox_host_cpu_temperatures(?array $server = null): array
{
    $cfg = get_proxmox_config($server);
    if ($cfg === null) {
        return [];
    }

    $raw = proxmox_execute_command($cfg, 'sensors -j 2>/dev/null || sensors');
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $trimmed = ltrim($raw);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $readings = parse_coretemp_from_sensors_json($raw);
        if ($readings !== []) {
            return $readings;
        }
    }

    return parse_coretemp_from_sensors_text($raw);
}

/**
 * @return list<array{devpath: string, label: string, temp: int, model: string}>
 */
function get_proxmox_disk_temperatures(?array $server = null): array
{
    $cfg = get_proxmox_config($server);
    if ($cfg === null) {
        return [];
    }

    $disks = proxmox_api_get($cfg, '/nodes/' . rawurlencode($cfg['node']) . '/disks/list');
    if (!is_array($disks)) {
        return [];
    }

    $result = [];
    foreach ($disks as $disk) {
        if (!is_array($disk)) {
            continue;
        }
        $devpath = $disk['devpath'] ?? '';
        if ($devpath === '') {
            continue;
        }

        $smart = proxmox_api_get($cfg, '/nodes/' . rawurlencode($cfg['node']) . '/disks/smart', [
            'disk' => $devpath,
        ]);
        if (!is_array($smart)) {
            continue;
        }

        $temp = parse_disk_smart_temperature($smart);
        if ($temp === null) {
            continue;
        }

        $model = trim($disk['model'] ?? 'unknown');
        $short = basename($devpath);
        $result[] = [
            'devpath' => $devpath,
            'label'   => $short . ' (' . $model . ')',
            'temp'    => $temp,
            'model'   => $model,
        ];
    }

    return $result;
}
