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

function get_proxmox_ssh_config(?array $server = null): ?array
{
    $host = trim($server['proxmoxSshHost'] ?? getenv('PROXMOX_SSH_HOST') ?: '');
    if ($host === '') {
        $api = get_proxmox_config($server);
        $host = $api['host'] ?? '';
    }

    $user = trim($server['proxmoxSshUser'] ?? getenv('PROXMOX_SSH_USER') ?: '');
    $password = (string) ($server['proxmoxSshPassword'] ?? getenv('PROXMOX_SSH_PASSWORD') ?: '');
    $portRaw = $server['proxmoxSshPort'] ?? getenv('PROXMOX_SSH_PORT') ?: '22';
    $port = (int) $portRaw;
    if ($port <= 0 || $port > 65535) {
        $port = 22;
    }

    if ($host === '' || $user === '' || $password === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'password' => $password,
    ];
}

/**
 * @return array{raw: ?string, meta: array<string, mixed>}
 */
function proxmox_run_sensors_over_ssh(?array $server = null): array
{
    $meta = [
        'attempted' => false,
        'ok' => false,
        'method' => 'ssh',
        'readingsCount' => 0,
        'error' => null,
        'sshHost' => null,
    ];

    if (!function_exists('ssh2_connect')) {
        $meta['error'] = 'ssh2_extension_missing';

        return ['raw' => null, 'meta' => $meta];
    }

    $sshCfg = get_proxmox_ssh_config($server);
    if ($sshCfg === null) {
        $meta['error'] = 'ssh_not_configured';

        return ['raw' => null, 'meta' => $meta];
    }

    $meta['attempted'] = true;
    $meta['sshHost'] = $sshCfg['host'] . ':' . $sshCfg['port'];

    $ssh = @ssh2_connect($sshCfg['host'], $sshCfg['port']);
    if ($ssh === false) {
        $meta['error'] = 'ssh_connect_failed';

        return ['raw' => null, 'meta' => $meta];
    }

    if (!@ssh2_auth_password($ssh, $sshCfg['user'], $sshCfg['password'])) {
        $meta['error'] = 'ssh_auth_failed';

        return ['raw' => null, 'meta' => $meta];
    }

    $stream = @ssh2_exec($ssh, 'sensors -j 2>/dev/null || sensors');
    if ($stream === false) {
        $meta['error'] = 'ssh_exec_failed';

        return ['raw' => null, 'meta' => $meta];
    }

    stream_set_blocking($stream, true);
    $raw = stream_get_contents($stream);
    fclose($stream);

    if (!is_string($raw) || trim($raw) === '') {
        $meta['error'] = 'ssh_empty_output';

        return ['raw' => null, 'meta' => $meta];
    }

    $meta['ok'] = true;

    return ['raw' => $raw, 'meta' => $meta];
}

/**
 * @param list<array{name: string, temp: int}> $readings
 * @param array<string, mixed> $meta
 * @return array{readings: list<array{name: string, temp: int}>, cpuSensors: array<string, mixed>}
 */
function proxmox_host_cpu_result(array $readings, array $meta): array
{
    $meta['readingsCount'] = count($readings);
    $meta['ok'] = $readings !== [] && ($meta['error'] ?? null) === null;
    if ($readings === [] && ($meta['error'] ?? null) === null) {
        $meta['error'] = 'sensors_output_unparsed';
        $meta['ok'] = false;
    }

    return ['readings' => $readings, 'cpuSensors' => $meta];
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

    if (!$body || $httpCode !== 200) {
        $GLOBALS['proxmox_api_last_error'] = [
            'httpCode' => $httpCode,
            'path' => $path,
            'bodySnippet' => is_string($body) ? substr($body, 0, 500) : null,
        ];

        return null;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
        $GLOBALS['proxmox_api_last_error'] = [
            'httpCode' => $httpCode,
            'path' => $path,
            'bodySnippet' => is_string($body) ? substr($body, 0, 500) : null,
        ];

        return null;
    }

    unset($GLOBALS['proxmox_api_last_error']);

    return $decoded['data'];
}

function proxmox_last_api_error(): ?array
{
    return $GLOBALS['proxmox_api_last_error'] ?? null;
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
 * Host CPU temps from lm-sensors on the PVE node (coretemp Package + Cores) via SSH.
 *
 * @return array{readings: list<array{name: string, temp: int}>, cpuSensors: array<string, mixed>}
 */
function get_proxmox_host_cpu_temperatures(?array $server = null): array
{
    if (get_proxmox_config($server) === null) {
        return proxmox_host_cpu_result([], [
            'attempted' => false,
            'ok' => false,
            'method' => 'ssh',
            'readingsCount' => 0,
            'error' => 'proxmox_not_configured',
            'sshHost' => null,
        ]);
    }

    $sshResult = proxmox_run_sensors_over_ssh($server);
    $meta = $sshResult['meta'];
    $raw = $sshResult['raw'];

    if ($raw === null) {
        return proxmox_host_cpu_result([], $meta);
    }

    $trimmed = ltrim($raw);
    $readings = [];
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $readings = parse_coretemp_from_sensors_json($raw);
    }
    if ($readings === []) {
        $readings = parse_coretemp_from_sensors_text($raw);
    }

    if ($readings !== []) {
        $meta['error'] = null;
    }

    return proxmox_host_cpu_result($readings, $meta);
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
