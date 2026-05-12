# Plan: Multi-Server Monitoring

## Server Config: `/data/servers.json`

Stored in the persistent Docker volume — never in the repo, never committed.

**Credential safety:**
- `/data/` is a Docker volume, physically outside the repo
- `servers.json.example` committed to repo with dummy values (format reference)
- `servers.json` added to `.gitignore` as a safety net

```json
[
  {
    "id": "server1",
    "name": "Main Server",
    "host": "10.10.9.110",
    "username": "admin",
    "password": "secret",
    "minimumFanSpeed": 10
  }
]
```

Fallback: if `/data/servers.json` is absent, build a single-entry array from env vars
(`ILO_HOST`, `ILO_USERNAME`, `ILO_PASSWORD`, `MINIMUM_FAN_SPEED`) — backwards compatible.

---

## Backend Changes (`ilo-fans-controller.php`)

### New: `get_servers()` in `config.inc.php.env`
- Reads `/data/servers.json`
- Falls back to env vars if file absent
- Returns array of `[id, name, host, username, password, minimumFanSpeed]`

### Refactored functions (all accept `$server` param)
| Function | Change |
|----------|--------|
| `get_fans($server)` | Uses `$server['host/username/password']` |
| `get_temperatures($server)` | Same |
| `get_auto_control($server)` | Reads `auto-control-{id}.json`, falls back to `auto-control.json` |
| `save_auto_control($config, $server)` | Writes to `auto-control-{id}.json` |
| `get_presets($server)` | Reads `presets-{id}.json`, falls back to `presets.json` |

### Page load
```php
$SERVERS_CONFIG = get_servers();
$ALL_DATA = [];
foreach ($SERVERS_CONFIG as $server) {
    $ALL_DATA[] = [
        'id'           => $server['id'],
        'name'         => $server['name'],
        'host'         => $server['host'],
        'fans'         => get_fans($server),
        'temperatures' => get_temperatures($server),
        'autoControl'  => get_auto_control($server),
        'presets'      => get_presets($server),
        'minFanSpeed'  => $server['minimumFanSpeed'] ?? 10,
    ];
}
```

### API routes
All GET and POST routes accept `?server=N` (integer index into `$SERVERS_CONFIG`).

---

## Frontend Changes (`ilo-fans-controller.php`)

### New `servers` Alpine store
```javascript
Alpine.store('servers', {
    list: <?php echo json_encode($ALL_DATA); ?>,
    active: 0,
    get current() { return this.list[this.active]; },

    summary(i) {
        const s = this.list[i];
        const allSensors = s.temperatures.flatMap(g => g.sensors);
        const hottest = allSensors.reduce(
            (a, b) => a.reading > b.reading ? a : b,
            { reading: 0, name: '—' }
        );
        const profile = s.autoControl.config?.profiles?.[s.autoControl.config?.profile];
        const fanValues = Object.values(s.fans);
        const avgFan = fanValues.length
            ? Math.round(fanValues.reduce((a, b) => a + b, 0) / fanValues.length)
            : 0;
        return {
            maxTemp:      hottest.reading,
            sensor:       hottest.name,
            avgFan,
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
```

Existing stores (`fans`, `temperatures`, `autoControl`, `presets`, `app`) become
thin delegates to `$store.servers.current.*`.

### HTML additions (before auto-control section, ~line 510)

**Summary cards row** — horizontal scroll, one card per server:
- Server name
- Profile label (small, below name)
- Max temp °C (large, red or green)
- Hottest sensor name (small, below temp)
- "Fans avg: N%" (small)
- Red border + tinted bg when `isAlert`, green border when active

**Tab bar** — below cards:
- One button per server
- Active tab = green pill style
- Clicking sets `$store.servers.active`

### Content area
Existing sections (auto-control, presets, temperatures, fans) are unchanged in
structure but all reactive references now resolve through `$store.servers.current`.

---

## Daemon Changes (`fan-daemon.php`)
- Read `$serverId = $argv[1] ?? 'default'`
- Load matching server from `/data/servers.json` (or env vars if absent)
- Config path: `/data/auto-control-{serverId}.json`
- PID path: `/var/www/html/fan-daemon-{serverId}.pid`

## Supervisor (`docker/supervisord.conf`)
Add up to 5 static entries:
```ini
[program:fan-daemon-server1]
command=php /var/www/html/fan-daemon.php server1
...
```
Entries for IDs not in `servers.json` exit cleanly — no harm.

---

## Critical Files
| File | Change |
|------|--------|
| `ilo-fans-controller.php` | Major — all functions, stores, HTML |
| `fan-daemon.php` | Moderate — server ID arg, per-server paths |
| `config.inc.php.env` | Minor — `get_servers()` |
| `docker/supervisord.conf` | Minor — daemon entries |
| `.gitignore` | Minor — add `servers.json` |
| `servers.json.example` | New — format reference with dummy values |
