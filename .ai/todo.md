# Fix: custom server ids + POST hang + supervisord

## Track D — supervisord
- [x] `docker/generate-supervisor-daemons.php` — get_servers(), cap 5, sanitize program name
- [x] `docker/supervisord.base.conf` — apache + include generated
- [x] `docker/docker-entrypoint.sh` — gen → exec supervisord
- [x] `Dockerfile` — entrypoint, copy generator
- [x] Remove static `fan-daemon-server1..5`
- [x] `autorestart=unexpected` on daemon programs
- [x] `fan-daemon.php` bogus id → exit 1, stderr one line, no JSON spam

## Track A — POST fans hang
- [x] Remove infinite Redfish poll loop
- [x] Bounded verify (3×, 200ms)
- [x] `CURLOPT_TIMEOUT` / connect timeout on `get_fans`
- [x] JSON `warning` when auto-control enabled; UI strips `warning` from fan map

## Track B — docs / UI
- [x] README: index vs `id`, restart after servers.json change
- [x] UI card: `id · #index`

## Track C — verify (manual / Docker)
- [ ] Custom ids in test `servers.json` (2 entries, not server1/2)
- [ ] `docker compose up` → logs show 2× daemon start with custom ids, zero `not in servers.json`
- [ ] Auto-control ON → `fan-daemon-{custom-id}.pid`, UI Daemon active
- [ ] curl POST fans completes in < 5s

## Fan apply logging (fan-daemon)
- [x] stdout flush + `fan_speed_applied` / `fan_speed_apply_failed` events
- [x] `set_fan_speed` structured errors; tick `holdingSpeedPct` + `skipReason`
- [x] README log section
- [ ] `docker compose up` → auto ON → `grep -E 'fan_speed_applied|APPLY fans'` on first tick
- [ ] Stable temps → ticks with `skipReason:hysteresis`, no `fan_speed_applied`
- [ ] Heat / profile change >3% → `fan_speed_applied` again
- [ ] Bad iLO host → `fan_speed_apply_failed` + `applyOk:false`
- [ ] Logs appear immediately (no multi-minute batching)

## Review

Supervisord now mirrors `servers.json` ids at container start only (no hot-reload without restart).

### How to test
1. Put 2 servers with custom `id` values in mounted `/data/servers.json`
2. Rebuild & run: `docker compose build && docker compose up`
3. `docker compose logs | grep -E 'Fan Control Daemon Started|not in servers.json'`
4. Enable auto-control in UI → check `/var/www/html/fan-daemon-<id>.pid` inside container
5. `time curl -X POST ... -d '{"action":"fans","server":0,"fans":50}'` → should finish quickly
