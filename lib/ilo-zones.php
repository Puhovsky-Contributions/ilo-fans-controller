<?php

/**
 * Map iLO Redfish sensor names to UI/daemon zones (same rules as the web UI).
 */
function get_temp_zone(string $name): string
{
    $lowerName = strtolower($name);

    $zones = [
        'ambient' => ['inlet', 'exhaust', 'ambient'],
        'cpu'     => ['cpu', 'processor'],
        'memory'  => ['dimm', 'mem'],
        'vr'      => ['vr p1', 'vr p2'],
        'storage' => ['hd', 'storage', 'cntlr'],
        'power'   => ['p/s', 'psu', 'power'],
        'chipset' => ['chipset', 'ilo'],
        'pci'     => ['pci'],
    ];

    foreach ($zones as $zone => $patterns) {
        foreach ($patterns as $pattern) {
            if (strpos($lowerName, $pattern) !== false) {
                return $zone;
            }
        }
    }

    return 'other';
}

/** @return list<string> */
function ilo_temp_zone_names(): array
{
    return ['ambient', 'cpu', 'memory', 'vr', 'storage', 'power', 'chipset', 'pci', 'other'];
}
