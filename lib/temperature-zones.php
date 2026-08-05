<?php

require_once __DIR__ . '/ilo-zones.php';

/**
 * Temperature UI groups: canonical id is `source` (same tokens as fanControlSources).
 * `section` + `zone` = grouping only (zone is short key within section: cpu, disks, …).
 */

/** @return array<string, string> */
function temperature_section_labels(): array
{
    return [
        'ilo' => 'iLO',
        'pve' => 'Proxmox',
    ];
}

/** @return list<string> */
function temperature_ilo_zone_order(): array
{
    return ilo_temp_zone_names();
}

/** @return list<string> */
function temperature_pve_source_order(): array
{
    return ['pve:cpu', 'pve:disks'];
}

/**
 * @return array{
 *   section: string,
 *   zone: string,
 *   icon: string,
 *   label: string,
 *   color: string,
 *   badgeClass: string,
 *   defaultMaxCritical: int
 * }
 */
function temperature_source_meta(string $source): array
{
    static $bySource = null;
    if ($bySource === null) {
        $ilo = [
            'ambient' => ['icon' => 'A', 'label' => 'Ambient', 'color' => 'sky', 'badgeClass' => 'bg-sky-500', 'defaultMaxCritical' => 100],
            'cpu'     => ['icon' => 'C', 'label' => 'CPUs', 'color' => 'violet', 'badgeClass' => 'bg-violet-500', 'defaultMaxCritical' => 100],
            'memory'  => ['icon' => 'M', 'label' => 'Memory', 'color' => 'pink', 'badgeClass' => 'bg-pink-500', 'defaultMaxCritical' => 100],
            'vr'      => ['icon' => 'V', 'label' => 'Regulators', 'color' => 'amber', 'badgeClass' => 'bg-amber-500', 'defaultMaxCritical' => 100],
            'storage' => ['icon' => 'S', 'label' => 'Storage', 'color' => 'blue', 'badgeClass' => 'bg-blue-500', 'defaultMaxCritical' => 100],
            'power'   => ['icon' => 'P', 'label' => 'Power Supply', 'color' => 'green', 'badgeClass' => 'bg-green-500', 'defaultMaxCritical' => 100],
            'chipset' => ['icon' => 'I', 'label' => 'Chipset / iLO', 'color' => 'orange', 'badgeClass' => 'bg-orange-500', 'defaultMaxCritical' => 100],
            'pci'     => ['icon' => 'X', 'label' => 'PCI Slots', 'color' => 'indigo', 'badgeClass' => 'bg-indigo-500', 'defaultMaxCritical' => 100],
            'other'   => ['icon' => '?', 'label' => 'Other', 'color' => 'gray', 'badgeClass' => 'bg-gray-500', 'defaultMaxCritical' => 100],
        ];
        $bySource = [];
        foreach ($ilo as $zone => $meta) {
            $bySource['ilo:' . $zone] = array_merge($meta, ['section' => 'ilo', 'zone' => $zone]);
        }
        $bySource['pve:cpu'] = [
            'section' => 'pve',
            'zone' => 'cpu',
            'icon' => 'H',
            'label' => 'Host CPU',
            'color' => 'violet',
            'badgeClass' => 'bg-violet-500',
            'defaultMaxCritical' => 100,
        ];
        $bySource['pve:disks'] = [
            'section' => 'pve',
            'zone' => 'disks',
            'icon' => 'D',
            'label' => 'Disks',
            'color' => 'blue',
            'badgeClass' => 'bg-blue-500',
            'defaultMaxCritical' => 55,
        ];
    }

    return $bySource[$source] ?? [
        'section' => 'ilo',
        'zone' => 'other',
        'icon' => '?',
        'label' => $source,
        'color' => 'gray',
        'badgeClass' => 'bg-gray-500',
        'defaultMaxCritical' => 100,
    ];
}

function temperature_ilo_source_for_zone(string $iloZone): string
{
    return 'ilo:' . $iloZone;
}

/** Empty group shell for API / UI (add sensors, then finalize). */
function temperature_group_shell(string $source): array
{
    $meta = temperature_source_meta($source);

    return [
        'source' => $source,
        'section' => $meta['section'],
        'zone' => $meta['zone'],
        'icon' => $meta['icon'],
        'label' => $meta['label'],
        'color' => $meta['color'],
        'badgeClass' => $meta['badgeClass'],
        'sensors' => [],
        'avg' => 0,
        'min' => PHP_INT_MAX,
        'max' => 0,
        'maxCritical' => $meta['defaultMaxCritical'],
    ];
}

function temperature_finalize_group(array &$group, array $autoConfig): void
{
    $sum = array_sum(array_column($group['sensors'], 'reading'));
    $count = count($group['sensors']);
    $group['avg'] = $count > 0 ? round($sum / $count, 1) : 0;
    $group['count'] = $count;
    if ($group['min'] === PHP_INT_MAX) {
        $group['min'] = 0;
    }
    $source = (string) ($group['source'] ?? '');
    if ($source === '' && isset($group['zone'])) {
        $legacyZone = (string) $group['zone'];
        if ($legacyZone === 'pve-cpu') {
            $group['source'] = 'pve:cpu';
            $group['zone'] = 'cpu';
            $source = 'pve:cpu';
        } elseif ($legacyZone === 'pve-disks') {
            $group['source'] = 'pve:disks';
            $group['zone'] = 'disks';
            $source = 'pve:disks';
        } elseif (($group['section'] ?? '') === 'ilo') {
            $source = temperature_ilo_source_for_zone($legacyZone);
            $group['source'] = $source;
        } elseif (($group['section'] ?? '') === 'pve') {
            $source = 'pve:' . $legacyZone;
            $group['source'] = $source;
        }
    }
    $group['fanControlActive'] = is_fan_control_source_active($source, $autoConfig);
}
