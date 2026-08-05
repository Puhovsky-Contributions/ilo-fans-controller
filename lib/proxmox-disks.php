<?php

require_once __DIR__ . '/env-secrets.php';

/**
 * Proxmox host temperatures via ilo-fans-agent-pve HTTP agent.
 * GET /v1/thermal with Bearer token — cpu[] and disks[] (IFC-compatible shapes).
 */

function get_proxmox_config(?array $server = null): ?array
{
    $url = trim($server['proxmoxAgentUrl'] ?? ifc_env('PROXMOX_AGENT_URL') ?? '');
    $token = trim($server['proxmoxAgentToken'] ?? ifc_env('PROXMOX_AGENT_TOKEN') ?? '');

    if ($url === '' || $token === '') {
        return null;
    }

    $url = rtrim($url, '/');
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }

    return [
        'agentUrl' => $url,
        'token' => $token,
        'source' => 'agent',
    ];
}

/**
 * @return array{cpu: list<array{name: string, temp: int}>, disks: list<array{devpath: string, label: string, temp: int, model: string}>, meta: array<string, mixed>}|null
 */
function proxmox_agent_fetch_thermal(?array $server = null): ?array
{
    static $cacheKey = null;
    static $cacheValue = null;
    static $cacheFetched = false;

    $cfg = get_proxmox_config($server);
    $key = $cfg === null ? '' : $cfg['agentUrl'] . '|' . $cfg['token'];
    if ($cacheFetched && $key === $cacheKey) {
        return $cacheValue;
    }

    if ($cfg === null) {
        return null;
    }

    $url = $cfg['agentUrl'] . '/v1/thermal';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['token'],
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if (!is_string($body) || $body === '' || $httpCode !== 200) {
        $GLOBALS['proxmox_agent_last_error'] = [
            'httpCode' => $httpCode,
            'url' => $url,
            'curlError' => $curlErr !== '' ? $curlErr : null,
            'bodySnippet' => is_string($body) ? substr($body, 0, 500) : null,
        ];
        $cacheKey = $key;
        $cacheValue = null;
        $cacheFetched = true;

        return null;
    }

    unset($GLOBALS['proxmox_agent_last_error']);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $cacheKey = $key;
        $cacheValue = null;
        $cacheFetched = true;

        return null;
    }

    $cpu = $decoded['cpu'] ?? [];
    $disks = $decoded['disks'] ?? [];
    if (!is_array($cpu)) {
        $cpu = [];
    }
    if (!is_array($disks)) {
        $disks = [];
    }

    $cacheKey = $key;
    $cacheValue = [
        'cpu' => $cpu,
        'disks' => $disks,
        'meta' => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [],
    ];
    $cacheFetched = true;

    return $cacheValue;
}

function proxmox_last_agent_error(): ?array
{
    return $GLOBALS['proxmox_agent_last_error'] ?? null;
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

/**
 * Host CPU temps from PVE agent (lm-sensors coretemp on the node).
 *
 * @return array{readings: list<array{name: string, temp: int}>, cpuSensors: array<string, mixed>}
 */
function get_proxmox_host_cpu_temperatures(?array $server = null): array
{
    if (get_proxmox_config($server) === null) {
        return proxmox_host_cpu_result([], [
            'attempted' => false,
            'ok' => false,
            'method' => 'agent',
            'readingsCount' => 0,
            'error' => 'proxmox_agent_not_configured',
            'agentUrl' => null,
        ]);
    }

    $thermal = proxmox_agent_fetch_thermal($server);
    if ($thermal === null) {
        $err = proxmox_last_agent_error();
        $agentUrl = get_proxmox_config($server)['agentUrl'] ?? null;

        return proxmox_host_cpu_result([], [
            'attempted' => true,
            'ok' => false,
            'method' => 'agent',
            'readingsCount' => 0,
            'error' => 'agent_request_failed',
            'agentUrl' => $agentUrl,
            'httpCode' => $err['httpCode'] ?? null,
        ]);
    }

    $readings = [];
    foreach ($thermal['cpu'] as $row) {
        if (!is_array($row) || !isset($row['name'], $row['temp'])) {
            continue;
        }
        $readings[] = [
            'name' => (string) $row['name'],
            'temp' => (int) $row['temp'],
        ];
    }

    $meta = $thermal['meta']['cpu'] ?? [];
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['method'] = 'agent';
    $meta['attempted'] = true;
    $meta['agentUrl'] = get_proxmox_config($server)['agentUrl'] ?? null;
    if ($readings !== []) {
        $meta['error'] = null;
        $meta['ok'] = true;
    }

    return proxmox_host_cpu_result($readings, $meta);
}

/** Human disk id: serial > wwn > short model (same model × N disks). */
function proxmox_disk_identity(array $disk): string
{
    foreach (['serial', 'wwn'] as $key) {
        $v = trim((string) ($disk[$key] ?? ''));
        if ($v !== '' && strtolower($v) !== 'unknown') {
            return $v;
        }
    }

    $model = trim((string) ($disk['model'] ?? 'unknown'));
    if (strlen($model) > 28) {
        return substr($model, 0, 12) . '…' . substr($model, -8);
    }

    return $model !== '' ? $model : 'unknown';
}

function proxmox_disk_display_label(array $disk): string
{
    $devpath = (string) ($disk['devpath'] ?? '');
    $dev = $devpath !== '' ? basename($devpath) : 'disk';

    return $dev . ' (' . proxmox_disk_identity($disk) . ')';
}

/**
 * @return list<array{devpath: string, label: string, temp: int, model: string}>
 */
function get_proxmox_disk_temperatures(?array $server = null): array
{
    if (get_proxmox_config($server) === null) {
        return [];
    }

    $thermal = proxmox_agent_fetch_thermal($server);
    if ($thermal === null) {
        return [];
    }

    $result = [];
    foreach ($thermal['disks'] as $disk) {
        if (!is_array($disk)) {
            continue;
        }
        $devpath = $disk['devpath'] ?? '';
        $temp = $disk['temp'] ?? null;
        if ($devpath === '' || $temp === null) {
            continue;
        }
        $model = trim((string) ($disk['model'] ?? 'unknown'));
        $label = proxmox_disk_display_label($disk);
        $result[] = [
            'devpath' => (string) $devpath,
            'label'   => $label,
            'temp'    => (int) $temp,
            'model'   => $model,
            'serial'  => trim((string) ($disk['serial'] ?? '')),
            'wwn'     => trim((string) ($disk['wwn'] ?? '')),
        ];
    }

    usort($result, static function (array $a, array $b): int {
        $c = ($b['temp'] ?? 0) <=> ($a['temp'] ?? 0);
        if ($c !== 0) {
            return $c;
        }

        return strcmp($a['devpath'] ?? '', $b['devpath'] ?? '');
    });

    return $result;
}
