<?php

/*
  ILO ACCESS CREDENTIALS
  --------------
  These are used to connect to the iLO
  interface and manage the fan speeds.
*/

$ILO_HOST = 'your-ilo-ip';  // Ex. 192.168.1.69
$ILO_USERNAME = 'your-ilo-username';  // Ex. Administrator
$ILO_PASSWORD = 'your-ilo-password';  // Ex. AdministratorPassword1234

/*
  MISCELLANEOUS SETTINGS
  --------------
  These allows you to customize
  the behavior of the tool.
*/

// Minimum fan speed percentage, from 0% (DANGEROUS) to 100%
$MINIMUM_FAN_SPEED = 10;
$AUTO_DAEMON = true;

/*
  PROXMOX (optional) — disk temps via API token; see .env.example / servers.json
*/

function get_servers() {
    $serversFile = '/data/servers.json';
    if (file_exists($serversFile)) {
        $data = json_decode(file_get_contents($serversFile), true);
        if (is_array($data) && count($data) > 0) return $data;
    }
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD, $MINIMUM_FAN_SPEED;
    return [[
        'id'             => 'server1',
        'name'           => 'Server',
        'host'           => $ILO_HOST,
        'username'       => $ILO_USERNAME,
        'password'       => $ILO_PASSWORD,
        'minimumFanSpeed' => $MINIMUM_FAN_SPEED,
    ]];
}

?>