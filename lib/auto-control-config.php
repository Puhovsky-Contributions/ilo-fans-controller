<?php

/**
 * Auto-control JSON: daemon and web must use the same path.
 * Docker: /data volume. Local dev: project root next to ilo-fans-controller.php.
 */

/** @return list<string> */
function auto_control_config_candidates(string $serverId): array
{
    $root = dirname(__DIR__);

    return [
        '/data/auto-control-' . $serverId . '.json',
        $root . '/auto-control-' . $serverId . '.json',
    ];
}

/** @return list<string> */
function auto_control_default_fallback_candidates(): array
{
    $root = dirname(__DIR__);

    return [
        '/data/auto-control.json',
        $root . '/auto-control.json',
    ];
}

/** Existing file to read, or preferred path for a new file. */
function resolve_auto_control_config_file(string $serverId): string
{
    foreach (auto_control_config_candidates($serverId) as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    foreach (auto_control_default_fallback_candidates() as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return auto_control_config_write_path($serverId);
}

/** Where saves and the daemon should read/write per-server config. */
function auto_control_config_write_path(string $serverId): string
{
    if (is_dir('/data') && is_writable('/data')) {
        return '/data/auto-control-' . $serverId . '.json';
    }

    return dirname(__DIR__) . '/auto-control-' . $serverId . '.json';
}
