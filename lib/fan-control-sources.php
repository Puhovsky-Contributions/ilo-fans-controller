<?php

require_once __DIR__ . '/ilo-zones.php';

/**
 * fanControlSources — what feeds max() for auto fan speed.
 *
 * Proxmox: pve:cpu (SSH sensors on host), pve:disks (API token)
 * iLO zones: ilo:all, ilo:ambient, ilo:cpu, ilo:memory, ilo:vr, ilo:storage,
 *            ilo:power, ilo:chipset, ilo:pci, ilo:other
 */
const FAN_CONTROL_SOURCES_DOC = <<<'DOC'
pve:cpu, pve:disks, ilo:all, ilo:ambient, ilo:cpu, ilo:memory, ilo:vr, ilo:storage, ilo:power, ilo:chipset, ilo:pci, ilo:other
DOC;

/** @return list<string> */
function fan_control_source_allowed(): array
{
    return [
        'pve:cpu',
        'pve:disks',
        'ilo:all',
        'ilo:ambient',
        'ilo:cpu',
        'ilo:memory',
        'ilo:vr',
        'ilo:storage',
        'ilo:power',
        'ilo:chipset',
        'ilo:pci',
        'ilo:other',
    ];
}

function is_valid_fan_control_source(string $source): bool
{
    return in_array(strtolower(trim($source)), fan_control_source_allowed(), true);
}

/** @return list<string> */
function get_fan_control_sources(array $config): array
{
    $raw = $config['fanControlSources'] ?? null;
    if (!is_array($raw)) {
        return ['pve:cpu', 'pve:disks', 'ilo:memory', 'ilo:vr', 'ilo:pci'];
    }

    $normalized = [];
    foreach ($raw as $item) {
        if (!is_string($item)) {
            continue;
        }
        $token = strtolower(trim($item));
        if (is_valid_fan_control_source($token)) {
            $normalized[] = $token;
        }
    }

    $normalized = array_values(array_unique($normalized));

    return $normalized !== [] ? $normalized : ['pve:cpu'];
}

/**
 * @param array<string, list<int>> $iloZones
 * @param list<array{name: string, temp: int}> $pveCpuReadings
 * @param list<array{devpath: string, label: string, temp: int, model: string}> $diskReadings
 * @return array{temps: list<int>, bySource: array<string, list<int>>}
 */
function build_fan_control_temps_detailed(
    array $config,
    array $iloZones,
    array $pveCpuReadings,
    array $diskReadings
): array {
    $temps = [];
    $bySource = [];

    foreach (get_fan_control_sources($config) as $source) {
        $chunk = [];
        if ($source === 'pve:cpu') {
            $chunk = array_column($pveCpuReadings, 'temp');
        } elseif ($source === 'pve:disks') {
            $chunk = array_column($diskReadings, 'temp');
        } elseif ($source === 'ilo:all') {
            foreach ($iloZones as $readings) {
                $chunk = array_merge($chunk, $readings);
            }
        } elseif (str_starts_with($source, 'ilo:')) {
            $zone = substr($source, 4);
            $chunk = $iloZones[$zone] ?? [];
        }
        $bySource[$source] = array_values($chunk);
        $temps = array_merge($temps, $chunk);
    }

    return ['temps' => $temps, 'bySource' => $bySource];
}

/** Whether a UI temperature group participates in auto fan speed (when auto is on). */
function is_fan_control_group_active(string $section, string $zone, array $config): bool
{
    $sources = get_fan_control_sources($config);
    if ($section === 'pve') {
        if ($zone === 'pve-cpu') {
            return in_array('pve:cpu', $sources, true);
        }
        if ($zone === 'pve-disks') {
            return in_array('pve:disks', $sources, true);
        }

        return false;
    }

    if ($section === 'ilo') {
        if (in_array('ilo:all', $sources, true)) {
            return true;
        }

        return in_array('ilo:' . $zone, $sources, true);
    }

    return false;
}

function fan_daemon_log_json(array $payload): void
{
    $payload['ts'] = date('c');
    $payload['event'] = $payload['event'] ?? 'fan_control_tick';
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
