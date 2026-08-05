#!/usr/bin/env php
<?php
/**
 * Fan Control Daemon
 *
 * Automatically adjusts fan speeds based on temperatures and the active profile.
 * Run: php fan-daemon.php [server-id]
 *
 * To run in background: nohup php fan-daemon.php server1 > /dev/null 2>&1 &
 */

require 'config.inc.php';
require __DIR__ . '/lib/proxmox-disks.php';
require __DIR__ . '/lib/ilo-zones.php';
require __DIR__ . '/lib/ilo-thermal.php';
require __DIR__ . '/lib/fan-control-sources.php';
require __DIR__ . '/lib/auto-control-config.php';

@ini_set('output_buffering', 'off');
if (defined('STDOUT') && is_resource(STDOUT)) {
    @stream_set_write_buffer(STDOUT, 0);
}

$requestedId = isset($argv[1]) ? (string) $argv[1] : null;
$serverId = $requestedId ?? 'default';

// Load matching server config
$servers = get_servers();
$serverConfig = null;
foreach ($servers as $s) {
    if ($s['id'] === $serverId) {
        $serverConfig = $s;
        break;
    }
}

if ($serverConfig === null) {
    if ($requestedId !== null && $requestedId !== 'default') {
        fwrite(STDERR, "Server {$requestedId} not in servers.json\n");
        exit(1);
    }
    $serverConfig = $servers[0] ?? null;
    if ($serverConfig === null) {
        fan_daemon_log_json([
            'status' => 'error',
            'error' => 'no_servers_configured',
        ]);
        echo "[ERROR] No servers configured\n";
        exit(1);
    }
    $serverId = $serverConfig['id'];
}

define('CONFIG_WRITE_FILE', auto_control_config_write_path($serverId));
define('PID_FILE', __DIR__ . '/fan-daemon-' . $serverId . '.pid');

function fan_daemon_process_running(int $pid, string $expectedServerId): bool
{
    if ($pid <= 0 || !posix_kill($pid, 0)) {
        return false;
    }
    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
    if ($cmdline === false) {
        return true;
    }
    $cmdline = str_replace("\0", ' ', $cmdline);
    if (!str_contains($cmdline, 'fan-daemon.php')) {
        return false;
    }

    return str_contains($cmdline, $expectedServerId);
}

// Check if already running
if (file_exists(PID_FILE)) {
    $pid = (int) trim((string) file_get_contents(PID_FILE));
    if (fan_daemon_process_running($pid, $serverId)) {
        fan_daemon_log_json([
            'serverId' => $serverId,
            'status' => 'skipped',
            'reason' => 'already_running',
            'pid' => $pid,
        ]);
        echo "Daemon already running with PID $pid\n";
        exit(0);
    }
    @unlink(PID_FILE);
}

// Write PID file
file_put_contents(PID_FILE, getmypid());

// Cleanup on exit
register_shutdown_function(function () {
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
});

// Handle signals
pcntl_signal(SIGTERM, function () {
    echo "Received SIGTERM, shutting down...\n";
    exit(0);
});
pcntl_signal(SIGINT, function () {
    echo "Received SIGINT, shutting down...\n";
    exit(0);
});

function get_config()
{
    global $serverId;
    $configFile = resolve_auto_control_config_file($serverId);
    if (!file_exists($configFile)) {
        return null;
    }
    return json_decode(file_get_contents($configFile), true);
}

function calculate_fan_speed($temps, $profile)
{
    if (empty($temps)) {
        return $profile['maxSpeed']; // Safety: max speed if no data
    }

    $maxTemp = max($temps);
    $targetTemp = $profile['targetTemp'];
    $criticalTemp = $profile['maxTemp'];
    $minSpeed = $profile['minSpeed'];
    $maxSpeed = $profile['maxSpeed'];

    // Linear interpolation
    if ($maxTemp <= $targetTemp) {
        return $minSpeed;
    } elseif ($maxTemp >= $criticalTemp) {
        return $maxSpeed;
    } else {
        $ratio = ($maxTemp - $targetTemp) / ($criticalTemp - $targetTemp);
        return (int) round($minSpeed + ($maxSpeed - $minSpeed) * $ratio);
    }
}

/**
 * @return array{ok: bool, error: ?string, speedPct: int, pwm: int, fanCount: int}
 */
function set_fan_speed($speed, $fanCount)
{
    global $serverConfig;

    $minFanSpeed = $serverConfig['minimumFanSpeed'] ?? 10;
    $speed = max($minFanSpeed, min(100, (int) $speed));
    $pwm = (int) ceil($speed / 100 * 255);
    $fanCount = (int) $fanCount;

    $result = [
        'ok' => false,
        'error' => null,
        'speedPct' => $speed,
        'pwm' => $pwm,
        'fanCount' => $fanCount,
    ];

    try {
        $ssh = ssh2_connect($serverConfig['host'], 22, ["kex" => "diffie-hellman-group14-sha1,diffie-hellman-group1-sha1", "hostkey" => "ssh-rsa,ssh-dss"]);
        if (!$ssh) {
            $result['error'] = 'ssh_connect_failed';

            return $result;
        }
        if (!ssh2_auth_password($ssh, $serverConfig['username'], $serverConfig['password'])) {
            $result['error'] = 'ssh_auth_failed';

            return $result;
        }

        // Loop only on detected fans count
        for ($i = 0; $i < $fanCount; $i++) {
            // Combined command to save time
            $stream = ssh2_exec($ssh, "fan p $i max $pwm; fan p $i min 255");

            if ($stream) {
                stream_set_blocking($stream, true);
                stream_set_timeout($stream, 2);
                // Clear the buffer
                @stream_get_contents($stream);
                fclose($stream);
            }
            // Small pause to let iLO breathe between fans
            usleep(50000);
        }

        $result['ok'] = true;

        return $result;
    } catch (Exception $e) {
        echo "  [ERROR] " . $e->getMessage() . "\n";
        $result['error'] = 'ssh_exec_failed';

        return $result;
    }
}

// Main loop
echo "=== Fan Control Daemon Started (iLO + Proxmox disks) ===\n";
echo "Server: $serverId ({$serverConfig['host']})\n";
echo "PID: " . getmypid() . "\n";
echo "Config file: " . resolve_auto_control_config_file($serverId) . " (write: " . CONFIG_WRITE_FILE . ")\n\n";

$lastSpeed = null;

while (true) {
    pcntl_signal_dispatch();

    $config = get_config();

    if (!$config) {
        fan_daemon_log_json([
            'serverId' => $serverId,
            'iloHost' => $serverConfig['host'],
            'status' => 'waiting',
            'error' => 'config_missing',
            'configFile' => resolve_auto_control_config_file($serverId),
        ]);
        echo "[WARN] Config file not found, waiting...\n";
        sleep(10);
        continue;
    }

    if (!$config['enabled']) {
        fan_daemon_log_json([
            'serverId' => $serverId,
            'iloHost' => $serverConfig['host'],
            'status' => 'idle',
            'autoEnabled' => false,
            'profile' => ['key' => $config['profile'] ?? null],
            'checkIntervalSec' => $config['checkInterval'] ?? 30,
        ]);
        if ($lastSpeed !== null) {
            echo "[INFO] Auto-control disabled\n";
            $lastSpeed = null;
        }
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $profileName = $config['profile'] ?? 'normal';
    $profile = $config['profiles'][$profileName] ?? $config['profiles']['normal'];
    $profileForced = false;
    $originalProfileName = $profileName;

    $iloData = fetch_ilo_thermal($serverConfig);
    if ($iloData === null) {
        fan_daemon_log_json([
            'serverId' => $serverId,
            'iloHost' => $serverConfig['host'],
            'status' => 'error',
            'error' => 'ilo_thermal_unavailable',
            'autoEnabled' => true,
        ]);
        echo "[WARN] Could not fetch iLO temperatures\n";
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $iloZones = $iloData['zones'];
    $ambientTemp = $iloData['ambient'];
    $fanCount = $iloData['fanCount'] ?: 8;

    $diskReadings = get_proxmox_disk_temperatures($serverConfig, $config);
    $pveCpuResult = get_proxmox_host_cpu_temperatures($serverConfig);
    $pveCpuReadings = $pveCpuResult['readings'];

    // Safety: Force Normal profile if ambient > 40°C
    if ($ambientTemp !== null && $ambientTemp > 40 && in_array($profileName, ['silence', 'silent'], true)) {
        $profileForced = true;
        $profile = $config['profiles']['normal'];
        $profileName = 'normal (forced)';
    }

    $fanCalc = build_fan_control_temps_detailed($config, $iloZones, $pveCpuReadings, $diskReadings);
    $allTemps = $fanCalc['temps'];
    $speed = calculate_fan_speed($allTemps, $profile);
    $previousSpeedPct = $lastSpeed;
    $speedDiff = abs($speed - ($lastSpeed ?? 0));
    $shouldApply = $lastSpeed === null || $speedDiff > 3;
    $applyOk = null;
    $applyResult = null;
    $maxTempC = $allTemps !== [] ? max($allTemps) : null;

    if ($shouldApply) {
        $applyResult = set_fan_speed($speed, $fanCount);
        $applyOk = $applyResult['ok'];
        if ($applyOk) {
            $lastSpeed = $speed;
            fan_daemon_log_event('fan_speed_applied', [
                'serverId' => $serverId,
                'iloHost' => $serverConfig['host'],
                'speedPct' => $applyResult['speedPct'],
                'pwm' => $applyResult['pwm'],
                'previousSpeedPct' => $previousSpeedPct,
                'fanCount' => $applyResult['fanCount'],
                'maxTempC' => $maxTempC,
            ]);
            $was = $previousSpeedPct === null ? 'none' : (string) $previousSpeedPct;
            $maxLabel = $maxTempC === null ? '?' : (string) $maxTempC;
            fan_daemon_stdout_line('[' . date('H:i:s') . "] APPLY fans={$applyResult['speedPct']}% pwm={$applyResult['pwm']} (was {$was}%, max {$maxLabel}°C)\n");
        } else {
            fan_daemon_log_event('fan_speed_apply_failed', [
                'serverId' => $serverId,
                'iloHost' => $serverConfig['host'],
                'error' => $applyResult['error'] ?? 'unknown',
                'speedPct' => $applyResult['speedPct'],
                'pwm' => $applyResult['pwm'],
            ]);
            fan_daemon_stdout_line('[' . date('H:i:s') . '] APPLY FAILED fans=' . $applyResult['speedPct'] . '%: ' . ($applyResult['error'] ?? 'unknown') . "\n");
        }
    }

    $skipReason = null;
    if (!$shouldApply) {
        $skipReason = 'hysteresis';
    } elseif ($applyOk === false) {
        $skipReason = 'apply_failed';
    }

    $pveCfg = get_proxmox_config($serverConfig);
    $cpuSensorsMeta = $pveCpuResult['cpuSensors'] ?? null;
    $warnings = [];
    if (in_array('pve:cpu', get_fan_control_sources($config), true) && $pveCpuReadings === []) {
        $warnings[] = 'pve_cpu:' . (($cpuSensorsMeta ?? [])['error'] ?? 'unavailable');
    }

    $logPayload = [
        'serverId' => $serverId,
        'iloHost' => $serverConfig['host'],
        'proxmox' => $pveCfg !== null ? [
            'agentUrl' => $pveCfg['agentUrl'],
            'source' => $pveCfg['source'] ?? 'agent',
        ] : null,
        'status' => 'ok',
        'autoEnabled' => true,
        'profile' => [
            'key' => $originalProfileName,
            'effectiveKey' => $profileName,
            'label' => $profile['label'],
            'forced' => $profileForced,
            'minSpeed' => $profile['minSpeed'],
            'maxSpeed' => $profile['maxSpeed'],
            'targetTemp' => $profile['targetTemp'],
            'maxTemp' => $profile['maxTemp'],
        ],
        'fanControlSources' => get_fan_control_sources($config),
        'ilo' => [
            'ambientC' => $ambientTemp,
            'fanCount' => $fanCount,
            'zones' => $iloZones,
        ],
        'pve' => [
            'cpu' => $pveCpuReadings,
            'cpuSensors' => $cpuSensorsMeta,
            'disks' => $diskReadings,
        ],
        'fanCalc' => [
            'bySource' => $fanCalc['bySource'],
            'tempsUsed' => $allTemps,
            'maxTempC' => $maxTempC,
            'speedPct' => $speed,
            'holdingSpeedPct' => $lastSpeed,
            'previousSpeedPct' => $previousSpeedPct,
            'hysteresisDiffPct' => $speedDiff,
            'skipReason' => $skipReason,
            'applied' => $shouldApply && $applyOk === true,
            'applyAttempted' => $shouldApply,
            'applyOk' => $applyOk,
        ],
        'safety' => [
            'ambientForceNormal' => $profileForced,
            'ambientThresholdC' => 40,
        ],
        'checkIntervalSec' => $config['checkInterval'] ?? 30,
    ];
    if ($warnings !== []) {
        $logPayload['warnings'] = $warnings;
    }
    fan_daemon_log_json($logPayload);

    if ($profileForced) {
        echo "[" . date('H:i:s') . "] SAFETY: Ambient {$ambientTemp}°C > 40°C, forcing Normal profile\n";
    }

    sleep($config['checkInterval'] ?? 30);
}
