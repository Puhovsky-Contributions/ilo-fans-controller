#!/usr/bin/env php
<?php
/**
 * Fan Control Daemon
 * 
 * Automatically adjusts fan speeds based on temperatures and the active profile.
 * Run: php fan-daemon.php
 * 
 * To run in background: nohup php fan-daemon.php > /dev/null 2>&1 &
 */

require 'config.inc.php';

define('CONFIG_FILE', __DIR__ . '/auto-control.json');
define('PID_FILE', __DIR__ . '/fan-daemon.pid');

// Check if already running
if (file_exists(PID_FILE)) {
    $pid = (int) file_get_contents(PID_FILE);
    if (posix_kill($pid, 0)) {
        echo "Daemon already running with PID $pid\n";
        exit(1);
    }
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
    if (!file_exists(CONFIG_FILE)) {
        return null;
    }
    return json_decode(file_get_contents(CONFIG_FILE), true);
}

function get_temperatures()
{
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD;

    $curl_handle = curl_init("https://$ILO_HOST/redfish/v1/chassis/1/Thermal");
    curl_setopt($curl_handle, CURLOPT_USERPWD, "$ILO_USERNAME:$ILO_PASSWORD");
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_handle, CURLOPT_TIMEOUT, 10);

    $raw_ilo_data = curl_exec($curl_handle);

    if (!$raw_ilo_data) {
        return null;
    }

    $data = json_decode($raw_ilo_data, true);
    $cpuTemps = [];
    $ambientTemp = null;
    $fanCount = 0;

    if (isset($data['Temperatures'])) {
        foreach ($data['Temperatures'] as $temp) {
            $name = strtolower($temp['Name'] ?? '');
            $reading = $temp['ReadingCelsius'] ?? null;
            $status = $temp['Status']['State'] ?? 'Unknown';

            if ($reading !== null && $status === 'Enabled') {
                // CPU temperatures for fan control
                if (strpos($name, 'cpu') !== false) {
                    $cpuTemps[] = $reading;
                }
                // Ambient/Inlet temperature for safety override
                if (strpos($name, 'inlet') !== false || strpos($name, 'ambient') !== false) {
                    $ambientTemp = $reading;
                }
            }
        }
    }

    // Count active fans
    if (isset($data['Fans'])) {
        foreach ($data['Fans'] as $fan) {
            $status = $fan['Status']['State'] ?? 'Unknown';
            if ($status === 'Enabled') {
                $fanCount++;
            }
        }
    }

    return ['cpu' => $cpuTemps, 'ambient' => $ambientTemp, 'fanCount' => $fanCount];
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

function set_fan_speed($speed, $fanCount)
{
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD, $MINIMUM_FAN_SPEED;

    $speed = max($MINIMUM_FAN_SPEED, min(100, $speed));
    $pwm = (int) ceil($speed / 100 * 255);

    try {
        $ssh = ssh2_connect($ILO_HOST, 22, ["kex" => "diffie-hellman-group14-sha1,diffie-hellman-group1-sha1", "hostkey" => "ssh-rsa,ssh-dss"]);
        if (!$ssh || !ssh2_auth_password($ssh, $ILO_USERNAME, $ILO_PASSWORD)) {
            return false;
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

        return true;
    } catch (Exception $e) {
        echo "  [ERROR] " . $e->getMessage() . "\n";
        return false;
    }
}

// Main loop
echo "=== Fan Control Daemon Started ===\n";
echo "PID: " . getmypid() . "\n";
echo "Config file: " . CONFIG_FILE . "\n\n";

$lastSpeed = null;

while (true) {
    pcntl_signal_dispatch();

    $config = get_config();

    if (!$config) {
        echo "[WARN] Config file not found, waiting...\n";
        sleep(10);
        continue;
    }

    if (!$config['enabled']) {
        if ($lastSpeed !== null) {
            echo "[INFO] Auto-control disabled, switching to idle\n";
            $lastSpeed = null;
        }
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $profileName = $config['profile'] ?? 'normal';
    $profile = $config['profiles'][$profileName] ?? $config['profiles']['normal'];

    // Get temperatures
    $tempData = get_temperatures();
    if ($tempData === null) {
        echo "[WARN] Could not fetch temperatures\n";
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $cpuTemps = $tempData['cpu'];
    $ambientTemp = $tempData['ambient'];
    $fanCount = $tempData['fanCount'] ?: 8; // Default to 8 if not detected

    // Safety: Force Normal profile if ambient > 40°C
    if ($ambientTemp !== null && $ambientTemp > 40 && $profileName === 'silence') {
        echo "[" . date('H:i:s') . "] SAFETY: Ambient {$ambientTemp}°C > 40°C, forcing Normal profile\n";
        $profile = $config['profiles']['normal'];
        $profileName = 'normal (forced)';
    } else {
        echo "[" . date('H:i:s') . "] Profile: {$profile['label']}";
        if ($ambientTemp !== null) {
            echo " | Ambient: {$ambientTemp}°C";
        }
        echo " | Fans: {$fanCount}\n";
    }

    $maxTemp = !empty($cpuTemps) ? max($cpuTemps) : 0;
    echo "  Max CPU temp: {$maxTemp}°C\n";

    // Calculate speed
    $speed = calculate_fan_speed($cpuTemps, $profile);
    echo "  Calculated speed: {$speed}%\n";

    // Hysteresis: only apply if change is > 3%
    $speedDiff = abs($speed - ($lastSpeed ?? 0));
    if ($lastSpeed === null || $speedDiff > 3) {
        echo "  Applying new fan speed (diff: {$speedDiff}%)...\n";
        if (set_fan_speed($speed, $fanCount)) {
            echo "  [OK] Fans set to {$speed}%\n";
            $lastSpeed = $speed;
        }
    } else {
        echo "  No change (diff: {$speedDiff}% < 3%)\n";
    }

    sleep($config['checkInterval'] ?? 30);
}
