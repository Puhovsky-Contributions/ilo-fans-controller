<?php

/**
 * Env value or secret read from path in {NAME}_FILE (Docker/K8s-friendly).
 */
function ifc_read_secret_file(string $path): string|false
{
    if ($path === '' || !is_readable($path)) {
        return false;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return false;
    }

    return rtrim($content, "\r\n");
}

function ifc_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    $fileEnv = getenv($name . '_FILE');
    if ($fileEnv !== false && $fileEnv !== '') {
        $fromFile = ifc_read_secret_file($fileEnv);
        if ($fromFile !== false) {
            return $fromFile;
        }
    }

    return $default;
}

function ifc_resolve_server_secrets(array $server): array
{
    if (!empty($server['passwordFile'])) {
        $password = ifc_read_secret_file((string) $server['passwordFile']);
        if ($password !== false) {
            $server['password'] = $password;
        }
    }
    if (!empty($server['proxmoxAgentTokenFile'])) {
        $token = ifc_read_secret_file((string) $server['proxmoxAgentTokenFile']);
        if ($token !== false) {
            $server['proxmoxAgentToken'] = $token;
        }
    }

    return $server;
}
