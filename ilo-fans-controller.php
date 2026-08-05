<?php
// Require config variables
require 'config.inc.php';
require __DIR__ . '/lib/proxmox-disks.php';
require __DIR__ . '/lib/ilo-zones.php';
require __DIR__ . '/lib/fan-control-sources.php';

function get_presets($server)
{
	$id = $server['id'];
	if (file_exists("presets-$id.json"))
		return json_decode(file_get_contents("presets-$id.json"), true);
	if (file_exists('presets.json'))
		return json_decode(file_get_contents('presets.json'), true);
	return [
		[
			'name' => 'Silent Mode',
			'speeds' => [15],
		],
		[
			'name' => 'Normal Mode',
			'speeds' => [50],
		],
		[
			'name' => 'Turbo Mode',
			'speeds' => [100],
		]
	];
}

function get_fans($server)
{
	$curl_handle = curl_init("https://{$server['host']}/redfish/v1/Chassis/1/Thermal/");

	curl_setopt($curl_handle, CURLOPT_USERPWD, "{$server['username']}:{$server['password']}");

	// Disable SSL verification
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);

	curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);  // Return the JSON data

	$raw_ilo_data = curl_exec($curl_handle);

	if ($raw_ilo_data) {  // If the request was successful
		$fans = [];
		$data = json_decode($raw_ilo_data, true);
		if (isset($data['Fans'])) {
			foreach ($data['Fans'] as $fan)
				$fans[$fan['FanName']] = $fan['CurrentReading'];
		}
	}

	return $fans ?? [];
}

function get_zone_info($zone) {
	$zoneInfos = [
		'ambient' => ['icon' => 'A', 'label' => 'Ambient', 'color' => 'sky', 'badgeClass' => 'bg-sky-500'],
		'cpu' => ['icon' => 'C', 'label' => 'CPUs', 'color' => 'violet', 'badgeClass' => 'bg-violet-500'],
		'memory' => ['icon' => 'M', 'label' => 'Memory', 'color' => 'pink', 'badgeClass' => 'bg-pink-500'],
		'vr' => ['icon' => 'V', 'label' => 'Regulators', 'color' => 'amber', 'badgeClass' => 'bg-amber-500'],
		'storage' => ['icon' => 'S', 'label' => 'Storage', 'color' => 'blue', 'badgeClass' => 'bg-blue-500'],
		'power' => ['icon' => 'P', 'label' => 'Power Supply', 'color' => 'green', 'badgeClass' => 'bg-green-500'],
		'chipset' => ['icon' => 'I', 'label' => 'Chipset / iLO', 'color' => 'orange', 'badgeClass' => 'bg-orange-500'],
		'pci' => ['icon' => 'X', 'label' => 'PCI Slots', 'color' => 'indigo', 'badgeClass' => 'bg-indigo-500'],
		'other' => ['icon' => '?', 'label' => 'Other', 'color' => 'gray', 'badgeClass' => 'bg-gray-500'],
	];
	return $zoneInfos[$zone] ?? $zoneInfos['other'];
}

function get_temperatures($server)
{
	$autoConfig = get_auto_control($server);

	$curl_handle = curl_init("https://{$server['host']}/redfish/v1/Chassis/1/Thermal/");

	curl_setopt($curl_handle, CURLOPT_USERPWD, "{$server['username']}:{$server['password']}");
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);

	$raw_ilo_data = curl_exec($curl_handle);
	$iloGrouped = [];

	if ($raw_ilo_data) {
		$data = json_decode($raw_ilo_data, true);

		if (isset($data['Temperatures'])) {
			foreach ($data['Temperatures'] as $temp) {
				$name = $temp['Name'] ?? '';
				$reading = $temp['ReadingCelsius'] ?? null;
				$status = $temp['Status']['State'] ?? 'Unknown';
				$critical = $temp['UpperThresholdCritical'] ?? null;

				if ($reading !== null && $status === 'Enabled') {
					$zone = get_temp_zone($name);

					if (!isset($iloGrouped[$zone])) {
						$zoneInfo = get_zone_info($zone);
						$iloGrouped[$zone] = [
							'zone' => $zone,
							'section' => 'ilo',
							'icon' => $zoneInfo['icon'],
							'label' => $zoneInfo['label'],
							'color' => $zoneInfo['color'],
							'badgeClass' => $zoneInfo['badgeClass'],
							'sensors' => [],
							'avg' => 0,
							'min' => PHP_INT_MAX,
							'max' => 0,
							'maxCritical' => 0,
						];
					}

					$iloGrouped[$zone]['sensors'][] = [
						'name' => $name,
						'reading' => $reading,
						'critical' => $critical,
					];

					$iloGrouped[$zone]['min'] = min($iloGrouped[$zone]['min'], $reading);
					$iloGrouped[$zone]['max'] = max($iloGrouped[$zone]['max'], $reading);
					if ($critical) {
						$iloGrouped[$zone]['maxCritical'] = max($iloGrouped[$zone]['maxCritical'], $critical);
					}
				}
			}
		}
	}

	$pveGroups = [];

	$pveCpuResult = get_proxmox_host_cpu_temperatures($server);
	$pveCpuReadings = $pveCpuResult['readings'];
	if (!empty($pveCpuReadings)) {
		$pveCpuGroup = [
			'zone' => 'pve-cpu',
			'section' => 'pve',
			'icon' => 'H',
			'label' => 'Host CPU (coretemp)',
			'color' => 'violet',
			'badgeClass' => 'bg-violet-500',
			'sensors' => [],
			'avg' => 0,
			'min' => PHP_INT_MAX,
			'max' => 0,
			'maxCritical' => 100,
		];
		foreach ($pveCpuReadings as $reading) {
			$temp = $reading['temp'];
			$pveCpuGroup['sensors'][] = [
				'name' => $reading['name'],
				'reading' => $temp,
				'critical' => 100,
			];
			$pveCpuGroup['min'] = min($pveCpuGroup['min'], $temp);
			$pveCpuGroup['max'] = max($pveCpuGroup['max'], $temp);
		}
		$pveGroups[] = $pveCpuGroup;
	}

	$diskReadings = get_proxmox_disk_temperatures($server);
	if (!empty($diskReadings)) {
		$pveDiskGroup = [
			'zone' => 'pve-disks',
			'section' => 'pve',
			'icon' => 'S',
			'label' => 'Disks (SMART)',
			'color' => 'blue',
			'badgeClass' => 'bg-blue-500',
			'sensors' => [],
			'avg' => 0,
			'min' => PHP_INT_MAX,
			'max' => 0,
			'maxCritical' => 55,
		];
		foreach ($diskReadings as $disk) {
			$temp = $disk['temp'];
			$pveDiskGroup['sensors'][] = [
				'name' => $disk['label'],
				'reading' => $temp,
				'critical' => 55,
			];
			$pveDiskGroup['min'] = min($pveDiskGroup['min'], $temp);
			$pveDiskGroup['max'] = max($pveDiskGroup['max'], $temp);
		}
		$pveGroups[] = $pveDiskGroup;
	}

	$finalize = function (array &$group) use ($autoConfig) {
		$sum = array_sum(array_column($group['sensors'], 'reading'));
		$count = count($group['sensors']);
		$group['avg'] = $count > 0 ? round($sum / $count, 1) : 0;
		$group['count'] = $count;
		if ($group['min'] === PHP_INT_MAX) {
			$group['min'] = 0;
		}
		$group['fanControlActive'] = is_fan_control_group_active(
			$group['section'],
			$group['zone'],
			$autoConfig
		);
	};

	$iloOrder = ['ambient', 'cpu', 'memory', 'vr', 'storage', 'power', 'chipset', 'pci', 'other'];
	$iloGroups = [];
	foreach ($iloOrder as $zone) {
		if (!isset($iloGrouped[$zone])) {
			continue;
		}
		$group = $iloGrouped[$zone];
		$finalize($group);
		$iloGroups[] = $group;
	}

	foreach ($pveGroups as &$pg) {
		$finalize($pg);
	}
	unset($pg);

	$sections = [];
	if ($iloGroups !== []) {
		$sections[] = [
			'id' => 'ilo',
			'label' => 'iLO',
			'groups' => $iloGroups,
		];
	}
	if ($pveGroups !== []) {
		$sections[] = [
			'id' => 'pve',
			'label' => 'Proxmox',
			'groups' => $pveGroups,
		];
	}

	return [
		'sections' => $sections,
		'fanControlSources' => get_fan_control_sources($autoConfig),
	];
}

function get_auto_control($server) {
	$id = $server['id'];
	$configFile = __DIR__ . "/auto-control-$id.json";
	if (!file_exists($configFile))
		$configFile = __DIR__ . '/auto-control.json';

	if (!file_exists($configFile)) {
		$config = [
			'enabled' => false,
			'profile' => 'normal',
			'profiles' => [
				'silence' => ['label' => 'Silence', 'minSpeed' => 10, 'maxSpeed' => 40, 'targetTemp' => 55, 'maxTemp' => 70],
				'normal' => ['label' => 'Normal', 'minSpeed' => 20, 'maxSpeed' => 70, 'targetTemp' => 50, 'maxTemp' => 65],
				'turbo' => ['label' => 'Turbo', 'minSpeed' => 40, 'maxSpeed' => 100, 'targetTemp' => 40, 'maxTemp' => 55],
			],
			'checkInterval' => 30,
			'daemonRunning' => false
		];
		return $config;
	}
	$config = json_decode(file_get_contents($configFile), true);

	// Check if daemon is running
	$pidFile = __DIR__ . "/fan-daemon-$id.pid";
	$config['daemonRunning'] = false;
	if (file_exists($pidFile)) {
		$pid = (int) file_get_contents($pidFile);
		if ($pid > 0 && posix_kill($pid, 0)) {
			$config['daemonRunning'] = true;
		}
	}

	return $config;
}

function save_auto_control($config, $server) {
	$id = $server['id'];
	$configFile = __DIR__ . "/auto-control-$id.json";
	unset($config['daemonRunning']); // Don't save runtime state
	file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
	return get_auto_control($server);
}

$SERVERS_CONFIG = get_servers();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$serverIndex = isset($_GET['server']) ? (int)$_GET['server'] : 0;
	$serverIndex = max(0, min($serverIndex, count($SERVERS_CONFIG) - 1));
	$server = $SERVERS_CONFIG[$serverIndex];

	if (isset($_GET['api'])) {
		header('Content-Type: application/json');
		switch ($_GET['api']) {
			case 'fans':        die(json_encode(get_fans($server)));
			case 'temperatures': die(json_encode(get_temperatures($server)));
			case 'autocontrol': die(json_encode(get_auto_control($server)));
			case 'presets':     die(json_encode(get_presets($server)));
		}
	}

	// Full page load — build data for all servers
	$ALL_DATA = [];
	foreach ($SERVERS_CONFIG as $srv) {
		$ALL_DATA[] = [
			'id'           => $srv['id'],
			'name'         => $srv['name'],
			'host'         => $srv['host'],
			'fans'         => get_fans($srv),
			'temperatures' => get_temperatures($srv),
			'autoControl'  => get_auto_control($srv),
			'presets'      => get_presets($srv),
			'minFanSpeed'  => $srv['minimumFanSpeed'] ?? 10,
		];
	}

} else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Get POST JSON data from JS fetch()
	$data = json_decode(file_get_contents('php://input'), true);
	$serverIndex = isset($data['server']) ? (int)$data['server'] : 0;
	$serverIndex = max(0, min($serverIndex, count($SERVERS_CONFIG) - 1));
	$server = $SERVERS_CONFIG[$serverIndex];

	if (isset($data['action']))  // Check if the action key exists
		if ($data['action'] === 'fans' || $data['action'] === 'presets' || $data['action'] === 'autocontrol')  // Check if the action is valid
			if ($data['action'] === 'fans' && isset($data['fans'])) {  // Set fans speeds
				$FANS = get_fans($server);
				$minFanSpeed = $server['minimumFanSpeed'] ?? 10;

				if (is_int($data['fans']))  // Example: "fans": 50 - set all fans to 50%
					$data['fans'] = array_fill_keys(array_keys($FANS), $data['fans']);  // Fill the array with the same speeds

				$updated = 0;
				$connected = false;
				$ssh_handle = null;
				foreach ($data['fans'] as $fan => $speed) {
					if (array_key_exists($fan, $FANS)) {  // Check if the fan name is valid
						$fan_index = array_search($fan, array_keys($FANS));
						if (($speed >= $minFanSpeed && $speed <= 100) && $speed != $FANS[$fan]) {  // Check if the speed is valid and different from the current fan's speed
							if (!$connected) {  // Connect to iLO (only once)
								$ssh_handle = ssh2_connect($server['host'], 22, ["kex" => "diffie-hellman-group14-sha1,diffie-hellman-group1-sha1", "hostkey" => "ssh-rsa,ssh-dss"]);
								if (!$ssh_handle || !ssh2_auth_password($ssh_handle, $server['username'], $server['password'])) {
									die(json_encode(['error' => 'SSH connection failed']));
								}
								$connected = true;
							}

							$pwm = (int) ceil($speed / 100 * 255);
							// Commande combinée pour gagner du temps
							$stream = ssh2_exec($ssh_handle, "fan p $fan_index max $pwm; fan p $fan_index min 255");
							if ($stream) {
								stream_set_blocking($stream, true);
								stream_set_timeout($stream, 2);
								@stream_get_contents($stream);
								fclose($stream);
							}
							// Petite pause pour laisser l'iLO respirer
							usleep(50000);

							$updated++;
						}
					} else
						die("Invalid fan name: $fan");
				}

				// Wait until the fans are set
				if ($updated > 0)
					do
						$FANS = get_fans($server);
					while ($FANS !== array_merge($FANS, $data['fans']));  // Wait until the fans are updated

				die(json_encode($FANS, JSON_PRETTY_PRINT));
			} else if ($data['action'] === 'presets' && isset($data['presets'])) {  // Save presets to presets-{id}.json
				$id = $server['id'];
				$raw_presets = json_encode($data['presets'], JSON_PRETTY_PRINT);
				file_put_contents("presets-$id.json", $raw_presets);
				die($raw_presets);
			} else if ($data['action'] === 'autocontrol' && isset($data['config'])) {  // Save auto-control config
				header('Content-Type: application/json');
				die(json_encode(save_auto_control($data['config'], $server)));
			} else
				die('Invalid request: missing required key.');
		else
			die('Invalid request: invalid "action" value.');
	else
		die('Invalid request: missing "action" key.');

	// Catch edge cases
	die('Invalid request.');
}
?>

<!DOCTYPE html>
<html x-data :class="$store.darkMode.active ? 'dark' : ''">

<head>
	<title>iLO Fans Controller</title>

	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/x-icon" href="./favicon.ico">

	<!-- Fonts (DM Sans & JetBrains Mono) -->
	<link
		href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700&family=JetBrains+Mono&display=swap"
		rel="stylesheet">

	<!-- Alpine.js -->
	<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

	<!-- Tailwind CSS -->
	<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
	<style type="text/tailwindcss">
		/* https://alpinejs.dev/directives/cloak */
			[x-cloak] {
				display: none !important;
			}

			:root.dark {
				color-scheme: dark;
			}

			.outline-button {
				@apply outline-none select-none cursor-pointer disabled:cursor-not-allowed transition duration-75 rounded-md border border-emerald-500 dark:disabled:border-emerald-500/20
							 enabled:hover:border-emerald-600 enabled:dark:hover:border-emerald-400 text-emerald-500 enabled:hover:text-emerald-600
							 enabled:dark:hover:text-emerald-400 dark:disabled:text-emerald-500/20 disabled:border-emerald-500/40 disabled:text-emerald-500/40;
			}

			/* Custom inputs style */
			input, .input {
				@apply transition-all duration-75 outline-none border rounded-md dark:shadow bg-gray-50 border-gray-175
							 disabled:opacity-50 placeholder-gray-300 dark:text-gray-200 dark:placeholder-gray-750 dark:bg-gray-900 dark:focus:border-gray-825
							 dark:border-gray-875 dark:enabled:hover:border-gray-825 hover:border-gray-275 focus:border-gray-275;
			}

			.tooltip {
				@apply z-10 py-0.5 px-2 rounded-md select-none absolute max-h-max min-w-max bg-gray-50 border-gray-150 text-gray-800
							 shadow-md border dark:border-gray-800 dark:bg-gray-850 dark:text-gray-200;
			}

			/* https://tailwindcss.com/docs/dark-mode#toggling-dark-mode-manually */
			@custom-variant dark (&:where(.dark, .dark *));

			@source inline("bg-sky-500 bg-violet-500 bg-pink-500 bg-amber-500 bg-blue-500 bg-green-500 bg-orange-500 bg-indigo-500 bg-gray-500");

			@theme {
				--font-sans: "DM Sans", sans-serif;
				--font-mono: "JetBrains Mono", monospace;

				--color-gray-975: #0A0A0A;
				--color-gray-950: #0F0F10;
				--color-gray-925: #141415;
				--color-gray-900: #19191A;

				--color-gray-875: #232324;
				--color-gray-850: #28282A;
				--color-gray-825: #2D2D2F;
				--color-gray-800: #323234;

				--color-gray-775: #3C3C3E;
				--color-gray-750: #414144;
				--color-gray-725: #464649;
				--color-gray-700: #4B4B4E;

				--color-gray-675: #555558;
				--color-gray-650: #5A5A5E;
				--color-gray-625: #5F5F63;
				--color-gray-600: #646468;

				--color-gray-575: #69696D;
				--color-gray-550: #737378;
				--color-gray-525: #78787D;
				--color-gray-500: #7D7D82;

				--color-gray-475: #87878C;
				--color-gray-450: #8D8D91;
				--color-gray-425: #929296;
				--color-gray-400: #97979B;

				--color-gray-375: #A1A1A5;
				--color-gray-350: #A7A7AA;
				--color-gray-325: #ACACAF;
				--color-gray-300: #B1B1B4;

				--color-gray-275: #BBBBBE;
				--color-gray-250: #C1C1C3;
				--color-gray-225: #C6C6C8;
				--color-gray-200: #CBCBCD;

				--color-gray-175: #D5D5D7;
				--color-gray-150: #E0E0E1;
				--color-gray-125: #E0E0E1;
				--color-gray-100: #E5E5E6;

				--color-gray-75: #EFEFF0;
				--color-gray-50: #F5F5F5;
				--color-gray-25: #FAFAFA;
			}
		</style>
</head>

<body class="w-full dark:bg-gray-950 transition-colors duration-75">
	<main class="p-5 pb-8 sm:px-10 max-w-[60rem] mx-auto">
		<div class="flex items-center justify-between mb-5 sm:mb-7">
			<div x-data="{ showTooltip: false }" class="relative" @mouseover.away="showTooltip = false">
				<a :href="'https://' + $store.servers.current.host" target="_blank" @mouseenter="showTooltip = !showTooltip">
					<img src="./favicon.ico" alt="favicon"
						class="h-12 w-12 transform transition-transform duration-75 active:scale-90" draggable="false">
				</a>

				<!-- Open iLO Tooltip -->
				<p x-cloak x-show="showTooltip" x-transition:enter="transition ease-out duration-100"
					x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
					x-transition:leave="transition ease-in duration-100"
					x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90"
					class="tooltip origin-left top-0 bottom-0 left-full my-auto ml-2 text-xs">
					Click to open iLO
				</p>
			</div>

			<div class="flex flex-col">
				<h1 class="font-bold text-2xl sm:text-3xl dark:text-white text-black select-none">
					iLO Fans Controller
				</h1>
				<div class="flex items-center justify-between">
					<p class="text-sm font-normal self-end pb-0.5 italic dark:text-gray-250 text-gray-450 select-none">
					</p>

					<!-- Version -->
					<a class="text-xs select-none font-mono px-2 py-0.5 rounded-full transition-colors duration-75 dark:bg-gray-925 dark:hover:bg-gray-900
									dark:focus:bg-gray-900 dark:text-gray-750 dark:hover:text-gray-650 dark:focus:text-gray-650 bg-gray-25 hover:bg-gray-50
									focus:bg-gray-50 text-gray-450 hover:text-gray-600 focus:text-gray-600">
						v1.0.0
					</a>
				</div>
			</div>

			<div class="mb-3 sm:mb-0">
				<div x-data="{ showTooltip: false }" class="relative" @mouseover.away="showTooltip = false">
					<!-- Theme Switcher -->
					<button class="cursor-pointer transition-colors duration-75 p-2 sm:p-1.5 dark:shadow-sm leading-none rounded-full dark:bg-gray-900 dark:text-gray-600
										 dark:hover:bg-gray-875 dark:hover:text-gray-500 dark:focus:text-gray-500 dark:focus:bg-gray-875 bg-gray-50 text-gray-300
										 hover:bg-gray-75 hover:text-gray-400 focus:bg-gray-75 focus:text-gray-400"
						@click="$store.darkMode.cycleMode()" @mouseenter="showTooltip = !showTooltip">
						<template x-if="$store.darkMode.state === 'system'">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
								stroke="currentColor" class="w-6 h-6">
								<path stroke-linecap="round" stroke-linejoin="round"
									d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
							</svg>
						</template>
						<template x-if="$store.darkMode.state === 'light'">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
								stroke="currentColor" class="w-6 h-6">
								<path stroke-linecap="round" stroke-linejoin="round"
									d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
							</svg>
						</template>
						<template x-if="$store.darkMode.state === 'dark'">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
								stroke="currentColor" class="w-6 h-6">
								<path stroke-linecap="round" stroke-linejoin="round"
									d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
							</svg>
						</template>
					</button>

					<!-- Theme Switcher Tooltip -->
					<div x-cloak x-show="showTooltip" x-transition:enter="transition ease-out duration-100"
						x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
						x-transition:leave="transition ease-in duration-100"
						x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90"
						class="tooltip origin-right top-0 bottom-0 right-full my-auto mr-2 text-xs">
						<!-- Capitalize first letter -->
						<p x-text="$store.darkMode.state.charAt(0).toUpperCase() + $store.darkMode.state.slice(1)"></p>
					</div>
				</div>
			</div>
		</div>

		<!-- Server summary (always visible) -->
		<template x-if="$store.servers.list.length >= 1">
			<div class="flex gap-3 overflow-x-auto pb-2 mb-3">
				<template x-for="(server, i) in $store.servers.list" :key="server.id">
					<div class="flex-shrink-0 rounded-lg border-2 p-3 transition-colors min-w-[200px]"
						:class="[
							$store.servers.summary(i).isAlert
								? 'border-red-500 bg-red-500/5 dark:bg-red-500/5'
								: i === $store.servers.active
									? 'border-emerald-500 bg-emerald-500/5 dark:border-emerald-500 dark:bg-emerald-500/5'
									: 'dark:border-gray-875 border-gray-175 dark:bg-gray-900/50 bg-gray-50',
							$store.servers.list.length > 1 ? 'cursor-pointer' : ''
						]"
						@click="$store.servers.list.length > 1 && ($store.servers.active = i)">
						<p class="font-semibold dark:text-white text-black text-sm" x-text="server.name"></p>
						<p class="text-xs dark:text-gray-500 text-gray-400 mb-2" x-text="$store.servers.summary(i).profileLabel"></p>
						<p class="text-3xl font-mono font-bold"
							:class="$store.servers.summary(i).isAlert ? 'text-red-500' : 'dark:text-emerald-400 text-emerald-600'"
							x-text="$store.servers.summary(i).maxTemp + '°C'"></p>
						<p class="text-xs dark:text-gray-500 text-gray-400 truncate" x-text="$store.servers.summary(i).sensor"></p>
						<p class="text-xs dark:text-gray-600 text-gray-500 mt-2" x-text="$store.servers.summary(i).fansDetail"></p>
						<p class="text-xs dark:text-gray-600 text-gray-400 mt-0.5" x-text="'Avg fan: ' + $store.servers.summary(i).avgFan + '%'"></p>
					</div>
				</template>
			</div>
		</template>

		<!-- Auto Control Section -->
		<div class="mt-5 p-4 rounded-lg border-2 transition-colors mb-3"
			:class="$store.autoControl.config.enabled ? 'dark:border-emerald-500/50 border-emerald-500/50 dark:bg-emerald-500/5 bg-emerald-500/5' : 'dark:border-gray-875 border-gray-175 dark:bg-gray-900/50 bg-gray-50'">
			<div class="flex items-center justify-between mb-3">
				<div class="flex items-center space-x-2">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5" :class="$store.autoControl.config.enabled ? 'text-emerald-500' : 'dark:text-gray-600 text-gray-400'">
						<path fill-rule="evenodd" d="M14.447 3.027a.75.75 0 01.527.92l-4.5 16.5a.75.75 0 01-1.448-.394l4.5-16.5a.75.75 0 01.921-.526zM16.72 6.22a.75.75 0 011.06 0l5.25 5.25a.75.75 0 010 1.06l-5.25 5.25a.75.75 0 11-1.06-1.06L21.44 12l-4.72-4.72a.75.75 0 010-1.06zm-9.44 0a.75.75 0 010 1.06L2.56 12l4.72 4.72a.75.75 0 11-1.06 1.06L.97 12.53a.75.75 0 010-1.06l5.25-5.25a.75.75 0 011.06 0z" clip-rule="evenodd" />
					</svg>
					<h2 class="text-lg font-semibold select-none" :class="$store.autoControl.config.enabled ? 'dark:text-white text-black' : 'dark:text-gray-400 text-gray-600'">
						Auto Control
					</h2>
					<span x-show="$store.autoControl.config.daemonRunning" class="text-xs px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 animate-pulse">
						Daemon active
					</span>
					<span x-show="$store.autoControl.config.enabled && !$store.autoControl.config.daemonRunning" class="text-xs px-1.5 py-0.5 rounded-full bg-amber-500/20 text-amber-600 dark:text-amber-400">
						Start daemon
					</span>
				</div>

				<!-- Toggle -->
				<div class="flex items-center space-x-2">
					<span class="text-xs font-medium" :class="$store.autoControl.config.enabled ? 'dark:text-gray-500 text-gray-400' : 'dark:text-gray-300 text-gray-700'">Manual</span>
					<button class="input cursor-pointer h-5 w-10 !rounded-full px-0.5 flex items-center"
						:class="$store.autoControl.config.enabled ? 'dark:!border-emerald-500 !border-emerald-500' : ''"
						@click="$store.autoControl.toggle()">
						<span class="h-3.5 w-3.5 rounded-full transform transition-all duration-100"
							:class="$store.autoControl.config.enabled ? 'bg-emerald-500 translate-x-5' : 'dark:bg-gray-700 bg-gray-300'"></span>
					</button>
					<span class="text-xs font-medium" :class="$store.autoControl.config.enabled ? 'dark:text-emerald-400 text-emerald-600' : 'dark:text-gray-500 text-gray-400'">Auto</span>
				</div>
			</div>

			<!-- Profile selector (visible when auto enabled) -->
			<div x-show="$store.autoControl.config.enabled" x-collapse>
				<div class="flex flex-wrap gap-2 mt-2">
					<template x-for="(profile, key) in $store.autoControl.config.profiles" :key="key">
						<button class="px-3 py-1.5 rounded-md text-sm font-medium transition-all"
							:class="$store.autoControl.config.profile === key
								? 'bg-emerald-500 text-white'
								: 'dark:bg-gray-800 bg-gray-100 dark:text-gray-300 text-gray-700 dark:hover:bg-gray-750 hover:bg-gray-200'"
							@click="$store.autoControl.setProfile(key)"
							x-text="profile.label">
						</button>
					</template>
				</div>
				<p class="text-xs dark:text-gray-600 text-gray-400 mt-2">
					<span x-text="'Speed: ' + $store.autoControl.config.profiles[$store.autoControl.config.profile]?.minSpeed + '-' + $store.autoControl.config.profiles[$store.autoControl.config.profile]?.maxSpeed + '% | '"></span>
					<span x-text="'Target: ' + $store.autoControl.config.profiles[$store.autoControl.config.profile]?.targetTemp + '°C | '"></span>
					<span x-text="'Max: ' + $store.autoControl.config.profiles[$store.autoControl.config.profile]?.maxTemp + '°C'"></span>
				</p>
			</div>
		</div>

		<div class="flex flex-col sm:flex-row items-center" :class="$store.autoControl.config.enabled ? 'opacity-50 pointer-events-none' : ''">
			<div x-data="{ showTooltip: false }" class="relative">
				<div
					class="dark:text-gray-300 text-gray-400 select-none sm:mb-0 mb-2.5 mr-2.5 flex items-center space-x-1">
					<div @mouseenter="showTooltip = !showTooltip" @mouseover.away="showTooltip = false">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
							class="w-5 h-5 cursor-help dark:hover:text-gray-175 hover:text-gray-500 transition-colors duration-75">
							<path fill-rule="evenodd"
								d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
								clip-rule="evenodd" />
						</svg>
					</div>
					<p>
						Presets:
					</p>
				</div>

				<!-- Presets Info Tooltip -->
				<p x-cloak x-show="showTooltip" x-transition:enter="transition ease-out duration-100"
					x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
					x-transition:leave="transition ease-in duration-100"
					x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-90"
					class="tooltip origin-top sm:origin-top-left top-full -left-full sm:left-0 -mt-1.5 sm:mt-1.5 !py-1.5 !px-2.5 text-sm">
					To delete a preset, right click on it.<br>
					On mobile, long press it.
				</p>
			</div>

			<div class="flex flex-wrap w-full gap-2.5">
				<template x-for="(preset, index) in $store.presets.presets" :key="index">
					<button class="outline-button flex-1 sm:px-1.5 px-2 py-1.5 sm:py-0.5 min-w-max text-sm"
						:class="$store.presets.currentPreset == index ? '!font-semibold' : ''" x-text="preset.name"
						:disabled="$store.app.isLoading" @click="$store.presets.applyPreset(index)"
						@contextmenu="$store.presets.onRightClick($event, index)"></button>
				</template>
				<button class="input cursor-pointer flex-1 border-dashed !bg-transparent sm:px-1.5 px-2 py-1.5 sm:py-0.5 sm:max-w-max text-sm dark:text-gray-875
									 dark:hover:text-gray-825 dark:focus:text-gray-825 text-gray-175 hover:text-gray-275 focus:text-gray-275"
					:disabled="$store.app.isLoading" @click="$store.presets.newPreset()">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
						class="w-5 h-5 mx-auto">
						<path
							d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
					</svg>
				</button>
			</div>
		</div>

			<!-- Temperatures Section -->
		<div class="mt-7">
			<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
				<div>
					<div class="flex items-center space-x-2">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 dark:text-red-400 text-red-500">
							<path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v10.19l2.72-2.72a.75.75 0 111.06 1.06l-4 4a.75.75 0 01-1.06 0l-4-4a.75.75 0 111.06-1.06l2.72 2.72V3a.75.75 0 01.75-.75zM9 14.25a3 3 0 106 0 3 3 0 00-6 0z" clip-rule="evenodd" />
						</svg>
						<h1 class="text-xl font-semibold select-none dark:text-white text-black">Temperatures</h1>
						<span x-show="$store.temperatures.autoRefreshInterval > 0" class="text-xs px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
							Auto
						</span>
					</div>
					<p x-show="$store.autoControl.config.enabled" class="text-xs dark:text-gray-500 text-gray-500 mt-1 flex items-center gap-1.5">
						<span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 shrink-0" title="Used in auto fan speed">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-2.5 h-2.5"><path fill-rule="evenodd" d="M10.403 2.43a1.5 1.5 0 00-1.806 0l-7.5 5.625a1.5 1.5 0 00-.597 1.194V16.5a1.5 1.5 0 001.5 1.5h4.5a1.5 1.5 0 001.5-1.5v-3.75h3v3.75a1.5 1.5 0 001.5 1.5h4.5a1.5 1.5 0 001.5-1.5V9.249a1.5 1.5 0 00-.597-1.194l-7.5-5.625z" clip-rule="evenodd"/></svg>
						</span>
						<span>— zone used in auto fan calculation</span>
					</p>
				</div>
				<div class="flex items-center space-x-2">
					<!-- Auto-refresh selector -->
					<select
						class="input text-xs px-1.5 py-0.5 rounded cursor-pointer"
						@change="$store.temperatures.setAutoRefresh(parseInt($event.target.value))"
						:value="$store.temperatures.autoRefreshInterval">
						<option value="0">Off</option>
						<option value="5">5s</option>
						<option value="10">10s</option>
						<option value="30">30s</option>
						<option value="60">60s</option>
					</select>
					<button class="outline-button px-2 py-0.5 text-xs flex items-center space-x-1"
						@click="$store.temperatures.refresh()" :disabled="$store.temperatures.isLoading">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3" :class="$store.temperatures.isLoading ? 'animate-spin' : ''">
							<path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0v2.43l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
						</svg>
					</button>
				</div>
			</div>

			<!-- Grouped Zones View -->
			<template x-for="section in ($store.temperatures.data.sections || [])" :key="section.id">
				<div class="mb-4">
					<h2 class="text-sm font-semibold uppercase tracking-wide dark:text-gray-500 text-gray-500 mb-2" x-text="section.label"></h2>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
						<template x-for="group in (section.groups || [])" :key="section.id + '-' + group.zone">
							<div x-data="{ open: false }" class="rounded-lg dark:bg-gray-900 bg-gray-50 border dark:border-gray-875 border-gray-150 overflow-hidden"
								:class="group.fanControlActive && $store.autoControl.config.enabled ? 'ring-1 ring-emerald-500/40 dark:ring-emerald-500/30' : ''">
								<div class="p-2.5 cursor-pointer select-none flex items-center justify-between"
									@click="open = !open"
									:class="open ? 'dark:bg-gray-875 bg-gray-100' : ''">
									<div class="flex items-center space-x-2 min-w-0">
										<span class="w-6 h-6 rounded-md flex items-center justify-center text-xs font-bold text-white shrink-0"
											:class="group.badgeClass || 'bg-gray-500'"
											x-text="group.icon"></span>
								<span class="font-medium text-sm dark:text-gray-200 text-gray-800 truncate" x-text="group.label"></span>
								<span class="text-xs dark:text-gray-600 text-gray-400 shrink-0" x-text="'(' + group.count + ')'"></span>
								<span x-show="group.fanControlActive && $store.autoControl.config.enabled"
									class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 shrink-0"
									title="Used in auto fan speed">
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3"><path fill-rule="evenodd" d="M10.403 2.43a1.5 1.5 0 00-1.806 0l-7.5 5.625a1.5 1.5 0 00-.597 1.194V16.5a1.5 1.5 0 001.5 1.5h4.5a1.5 1.5 0 001.5-1.5v-3.75h3v3.75a1.5 1.5 0 001.5 1.5h4.5a1.5 1.5 0 001.5-1.5V9.249a1.5 1.5 0 00-.597-1.194l-7.5-5.625z" clip-rule="evenodd"/></svg>
								</span>
							</div>
							<div class="flex items-center space-x-3">
								<div class="text-right">
									<span class="text-sm font-mono font-bold"
										:class="group.max >= (group.maxCritical || 100) ? 'text-red-500' : group.max >= ((group.maxCritical || 100) * 0.8) ? 'text-amber-500' : 'dark:text-emerald-400 text-emerald-600'"
										x-text="group.avg + '°C'"></span>
									<span class="text-xs dark:text-gray-600 text-gray-500 ml-1" x-text="'(' + group.min + '-' + group.max + ')'"></span>
								</div>
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
									class="w-4 h-4 dark:text-gray-600 text-gray-400 transition-transform duration-200"
									:class="open ? 'rotate-180' : ''">
									<path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
								</svg>
							</div>
						</div>

						<!-- Progress bar -->
						<div class="h-1 dark:bg-gray-800 bg-gray-200">
							<div class="h-full transition-all duration-300"
								:class="group.max >= (group.maxCritical || 100) ? 'bg-red-500' : group.max >= ((group.maxCritical || 100) * 0.8) ? 'bg-amber-500' : 'bg-emerald-500'"
								:style="'width: ' + Math.min(100, (group.avg / (group.maxCritical || 100)) * 100) + '%'">
							</div>
						</div>

						<!-- Sensors Details (collapsible) -->
						<div x-show="open" x-collapse>
							<div class="p-2 space-y-1 dark:bg-gray-925 bg-gray-25">
								<template x-for="sensor in group.sensors" :key="sensor.name">
									<div class="flex items-center justify-between py-1 px-2 rounded dark:hover:bg-gray-900 hover:bg-gray-50">
										<span class="text-xs dark:text-gray-500 text-gray-600 truncate mr-2" x-text="sensor.name"></span>
										<div class="flex items-center space-x-2">
											<div class="w-16 h-1 rounded-full dark:bg-gray-800 bg-gray-200">
												<div class="h-full rounded-full"
													:class="sensor.reading >= (sensor.critical || 100) ? 'bg-red-500' : sensor.reading >= ((sensor.critical || 100) * 0.8) ? 'bg-amber-500' : 'bg-emerald-500'"
													:style="'width: ' + Math.min(100, (sensor.reading / (sensor.critical || 100)) * 100) + '%'">
												</div>
											</div>
											<span class="text-xs font-mono font-semibold w-8 text-right"
												:class="sensor.reading >= (sensor.critical || 100) ? 'text-red-500' : sensor.reading >= ((sensor.critical || 100) * 0.8) ? 'text-amber-500' : 'dark:text-emerald-400 text-emerald-600'"
												x-text="sensor.reading + '°'"></span>
										</div>
									</div>
								</template>
							</div>
						</div>
					</div>
						</template>
					</div>
				</div>
			</template>
		</div>

		<div class="mt-5 flex items-center w-full justify-between">
			<h1 class="text-2xl font-semibold select-none dark:text-white text-black">Fans</h1>

			<div class="flex items-center space-x-2">
				<label for="edit-all" class="text-sm font-medium select-none transition-colors duration-75"
					:class="[ $store.app.editAll ? 'dark:text-gray-300 text-gray-700' : 'dark:text-gray-700 text-gray-300', $store.app.isLoading ? '!opacity-50' : '' ]">
					Edit all
				</label>

				<!-- Switch -->
				<button id="edit-all" class="input cursor-pointer group h-5 w-10 !rounded-full px-0.5 flex items-center"
					:class="$store.app.editAll ? 'dark:!border-gray-825 !border-gray-275' : ''"
					:disabled="$store.app.isLoading" @click="$store.app.editAll = !$store.app.editAll">
					<span class="h-3.5 w-3.5 rounded-full transform transition-all duration-100"
						:class="$store.app.editAll ? 'bg-emerald-500 translate-x-5' : 'dark:bg-gray-825 bg-gray-175'"></span>
				</button>
			</div>
		</div>

		<div class="space-y-6 w-full mt-5">
			<template x-for="(speed, name) in $store.fans.fans" :key="name">
				<div class="flex flex-col sm:flex-row sm:items-center justify-between group sm:space-y-0 space-y-3"
					x-init="$watch('speed', (newSpeed) => $store.fans.setSpeed(name, newSpeed))">
					<div class="w-full flex items-center space-x-3 transform-opacity duration-75">
						<p class="dark:text-gray-200 text-gray-650 group-hover:text-black dark:group-hover:text-white
									 dark:group-focus:text-white transition-colors duration-75 font-medium text-lg no-break select-none peer w-12"
							:class="$store.app.isLoading ? 'dark:!text-gray-200' : ''" x-text="name"></p>

						<!-- Sorry for this -->
						<input type="range" :min="$store.servers.current.minFanSpeed" max="100"
							class="touch-none w-full flex-1 border appearance-none [&::-webkit-slider-thumb]:transition-colors
											[&::-webkit-slider-thumb]:duration-75 [&::-webkit-slider-thumb]:appearance-none cursor-pointer [&::-webkit-slider-thumb]:bg-emerald-500
											[&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:h-6 sm:[&::-webkit-slider-thumb]:w-5 sm:[&::-webkit-slider-thumb]:h-5
											[&::-webkit-slider-thumb]:rounded-full enabled:[&::-webkit-slider-thumb]:hover:bg-emerald-600 dark:enabled:[&::-webkit-slider-thumb]:hover:bg-emerald-400
										enabled:[&::-webkit-slider-thumb]:focus:bg-emerald-600 dark:enabled:[&::-webkit-slider-thumb]:focus:bg-emerald-400
										enabled:[&::-webkit-slider-thumb]:peer-hover:bg-emerald-600 dark:enabled:[&::-webkit-slider-thumb]:peer-hover:bg-emerald-400
											enabled:peer-hover:border-gray-175 dark:enabled:peer-hover:border-gray-825 h-5 sm:h-3.5 !rounded-full disabled:cursor-default" :disabled="$store.app.isLoading"
							x-model="speed">
					</div>

					<div x-data="{ originalSpeed: speed }" class="select-none items-center flex flex-row sm:flex-row">
						<input type="number" :min="$store.servers.current.minFanSpeed" max="100" required
							class="w-16 sm:ml-3 max-w-max px-1.5 py-0.5 font-mono text-gray-800"
							:placeholder="originalSpeed" :disabled="$store.app.isLoading" x-model="speed">

						<button class="outline-button mx-3 sm:mr-0 px-1 text-sm" type="button"
							@click="speed = originalSpeed"
							:disabled="speed == originalSpeed || $store.app.isLoading">Reset</button>

						<!-- Divider (only mobile) -->
						<div class="sm:hidden h-px w-full bg-gray-100 dark:bg-gray-900"></div>
					</div>
				</div>
			</template>
		</div>

		<div class="flex flex-col items-center mt-7 sm:space-y-3 space-y-4">
			<button type="button"
				class="!outline-none transition-all duration-75 sm:h-10 h-11 sm:w-[15rem] items-center w-full flex justify-center bg-emerald-500 hover:bg-emerald-500/90
								active:bg-emerald-500/80 px-2 py-1.5 rounded-md text-white font-medium select-none cursor-pointer disabled:cursor-progress disabled:!bg-emerald-500/60 disabled:text-opacity-60"
				@click="$store.app.applySpeeds()" :disabled="$store.app.isLoading">
				<template x-if="!$store.app.isLoading">
					<div class="flex items-center space-x-1.5">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
							<path fill-rule="evenodd"
								d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
								clip-rule="evenodd" />
						</svg>
						<span>Set speeds</span>
					</div>
				</template>
				<template x-if="$store.app.isLoading">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-5 w-5">
						<path fill="currentColor"
							d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,19a8,8,0,1,1,8-8A8,8,0,0,1,12,20Z"
							opacity=".25" />
						<path fill="currentColor"
							d="M12,4a8,8,0,0,1,7.89,6.7A1.53,1.53,0,0,0,21.38,12h0a1.5,1.5,0,0,0,1.48-1.75,11,11,0,0,0-21.72,0A1.5,1.5,0,0,0,2.62,12h0a1.53,1.53,0,0,0,1.49-1.3A8,8,0,0,1,12,4Z">
							<animateTransform attributeName="transform" dur="0.75s" repeatCount="indefinite"
								type="rotate" values="0 12 12;360 12 12" />
						</path>
					</svg>
				</template>
			</button>

			<p x-show="$store.app.requestTime" class="text-sm dark:text-gray-750 text-gray-350 select-none font-mono">
				Executed in <span
					x-text="$store.app.requestTime >= 1000 ? ($store.app.requestTime / 1000).toFixed(2) + 's' : $store.app.requestTime + 'ms'"></span>
			</p>
		</div>
	</main>

	<script lang="javascript">
		document.addEventListener('alpine:init', () => {
			Alpine.store('darkMode', {
				active: false,
				state: null,

				updateState() {
					if (!('theme' in localStorage)) {
						this.state = 'system';
						this.active = window.matchMedia('(prefers-color-scheme: dark)').matches;
					} else {
						this.state = localStorage.theme;
						this.active = localStorage.theme === 'dark';
					}
				},

				cycleMode() {
					switch (this.state) {
						case 'system':
							localStorage.theme = 'light';
							this.state = 'light';
							break;
						case 'light':
							localStorage.theme = 'dark';
							this.state = 'dark';
							break;
						default:
							localStorage.removeItem('theme');
							this.state = 'system';
					}
					this.updateState();
				},

				init() {
					this.updateState();
				}
			});

			Alpine.store('servers', {
				list: <?php echo json_encode($ALL_DATA); ?>,
				active: 0,

				get current() { return this.list[this.active]; },

				summary(i) {
					const s = this.list[i];
					const groups = (s.temperatures?.sections || []).flatMap(sec => sec.groups || []);
					const allSensors = groups.flatMap(g => g.sensors || []);
					const hottest = allSensors.reduce(
						(a, b) => a.reading > b.reading ? a : b,
						{ reading: 0, name: '—' }
					);
					const profile = s.autoControl?.profiles?.[s.autoControl?.profile];
					const fanValues = Object.values(s.fans || {});
					const avgFan = fanValues.length
						? Math.round(fanValues.reduce((a, b) => a + b, 0) / fanValues.length)
						: 0;
					const fansDetail = Object.entries(s.fans || {})
						.map(([name, speed]) => `${name}: ${speed}%`)
						.join(' · ') || '—';
					return {
						maxTemp:      hottest.reading,
						sensor:       hottest.name,
						avgFan,
						fansDetail,
						profileLabel: profile?.label ?? 'Manual',
						isAlert:      profile ? hottest.reading >= profile.maxTemp : false,
					};
				},

				async refresh(i) {
					const [tempRes, fanRes] = await Promise.all([
						fetch(`?api=temperatures&server=${i}`),
						fetch(`?api=fans&server=${i}`)
					]);
					if (tempRes.ok) this.list[i].temperatures = await tempRes.json();
					if (fanRes.ok)  this.list[i].fans = await fanRes.json();
				},
			});

			Alpine.store('fans', {
				get fans() { return Alpine.store('servers').current.fans; },

				setSpeed(fan, rawSpeed) {
					const speed = parseInt(rawSpeed);
					const minFanSpeed = Alpine.store('servers').current.minFanSpeed;

					if (speed >= minFanSpeed && speed <= 100)
						if (Alpine.store('app').editAll)
							for (const f in this.fans)
								this.fans[f] = speed;
						else
							this.fans[fan] = speed;

					Alpine.store('presets').detectPreset();  // FIXME: Not properly working
				}
			});

			Alpine.store('temperatures', {
				get data() { return Alpine.store('servers').current.temperatures || { sections: [] }; },
				isLoading: false,
				lastRefresh: null,
				autoRefreshInterval: localStorage.getItem('tempRefreshInterval') || '0',
				intervalId: null,

				async refresh() {
					this.isLoading = true;
					try {
						const i = Alpine.store('servers').active;
						await Alpine.store('servers').refresh(i);
						this.lastRefresh = new Date().toLocaleTimeString();
					} catch (e) {
						console.error('Failed to refresh:', e);
					}
					this.isLoading = false;
				},

				setAutoRefresh(seconds) {
					this.autoRefreshInterval = seconds;
					localStorage.setItem('tempRefreshInterval', seconds);

					if (this.intervalId) {
						clearInterval(this.intervalId);
						this.intervalId = null;
					}

					if (seconds > 0) {
						this.intervalId = setInterval(() => this.refresh(), seconds * 1000);
					}
				},

				init() {
					this.lastRefresh = new Date().toLocaleTimeString();
					if (this.autoRefreshInterval > 0) {
						this.setAutoRefresh(parseInt(this.autoRefreshInterval));
					}
				}
			});

			Alpine.store('autoControl', {
				get config() { return Alpine.store('servers').current.autoControl; },

				async save() {
					const i = Alpine.store('servers').active;
					try {
						const res = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
							method: 'POST',
							body: JSON.stringify({ action: 'autocontrol', config: this.config, server: i }),
						});
						if (res.ok) {
							Alpine.store('servers').list[i].autoControl = await res.json();
						}
					} catch (e) {
						console.error('Failed to save auto-control config:', e);
					}
				},

				async toggle() {
					this.config.enabled = !this.config.enabled;
					await this.save();
				},

				async setProfile(profileKey) {
					this.config.profile = profileKey;
					await this.save();
				},

				async refresh() {
					const i = Alpine.store('servers').active;
					try {
						const res = await fetch(`<?php echo $_SERVER['PHP_SELF']; ?>?api=autocontrol&server=${i}`);
						if (res.ok) {
							Alpine.store('servers').list[i].autoControl = await res.json();
						}
					} catch (e) {
						console.error('Failed to refresh auto-control config:', e);
					}
				}
			});

			Alpine.store('presets', {
				currentPreset: null,
				get presets() { return Alpine.store('servers').current.presets; },

				async updatePresets() {
					const i = Alpine.store('servers').active;
					const res = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
						method: 'POST',
						body: JSON.stringify({ action: 'presets', presets: this.presets, server: i }),
					});

					if (res.ok) {
						Alpine.store('servers').list[i].presets = await res.json();
					}
				},

				applyPreset(index) {
					const preset = this.presets[index];
					const speeds = preset.speeds;
					const fans = Alpine.store('fans').fans;

					if (speeds.length === 1)  // Apply the same speed for all the fans
						Object.keys(fans).forEach(fan => {
							fans[fan] = speeds[0];
						});
					else  // Apply the speed for each fan
						Object.keys(speeds).forEach(i => {
							fans[Object.keys(fans)[i]] = speeds[i];
						});

					this.currentPreset = index;
				},

				newPreset() {
					const name = prompt('Enter the name of the new preset:');
					if (name && name.trim().length > 0) {
						const fans = Alpine.store('fans').fans;
						const speeds = Object.values(fans);

						// Check if a preset with the same speeds already exists
						const existingPreset = this.presets.find(preset => preset.speeds.length === 1 ? speeds.every(speed => speed === preset.speeds[0]) : preset.speeds.join(',') === speeds.join(','));
						if (existingPreset) {
							alert(`A preset with the same speeds already exists (${existingPreset.name}).`);
							return;
						}

						this.presets.push({ name: name.trim(), speeds });
						this.currentPreset = this.presets.length - 1;

						this.updatePresets();  // Save the presets in the presets-{id}.json file
					}
				},

				onRightClick(event, index) {  // On right click on a preset, delete it
					event.preventDefault();
					if (confirm('Are you sure you want to delete this preset?')) {
						this.presets.splice(index, 1);
						this.currentPreset = null;

						this.updatePresets();  // Save the presets in the presets-{id}.json file
					}
				},

				detectPreset() {  // Try to detect and set the current preset
					const fans = Alpine.store('fans').fans;
					const speeds = Object.values(fans);

					Object.keys(this.presets).forEach(presetIndex => {
						const preset = this.presets[presetIndex];
						const presetSpeeds = preset.speeds;

						if (speeds.every((speed, i) => speed === presetSpeeds[presetSpeeds.length === speeds.length ? i : 0])) {
							this.currentPreset = presetIndex;
							return;
						}
					});
				},

				init() { this.detectPreset(); }
			});

			Alpine.store('app', {
				editAll: false,
				isLoading: false,
				requestTime: null,

				async applySpeeds() {
					this.isLoading = true;
					this.requestTime = null;
					const currentTimestamp = new Date().getTime();
					const i = Alpine.store('servers').active;
					const fans = Alpine.store('fans').fans;

					const res = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
						method: 'POST',
						body: JSON.stringify({ action: 'fans', fans, server: i }),
					});

					if (res.ok) {
						Alpine.store('servers').list[i].fans = await res.json();
						this.requestTime = new Date().getTime() - currentTimestamp;
					}

					this.isLoading = false;
				}
			});
		});
	</script>
</body>

</html>
