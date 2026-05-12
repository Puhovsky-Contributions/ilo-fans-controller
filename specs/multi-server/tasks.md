# Tasks: Multi-Server Monitoring

Tasks are ordered by dependency. `[P]` = can run in parallel with the previous task in the same group.

---

## Group 1 — Config layer
- [ ] Add `servers.json` to `.gitignore`
- [ ] Create `servers.json.example` with dummy values (format reference, safe to commit)
- [ ] Add `get_servers()` to `config.inc.php.env` — reads `/data/servers.json`, falls back to env vars

## Group 2 — Refactor PHP data functions
- [ ] Refactor `get_fans()` to accept `$server` array param
- [ ] [P] Refactor `get_temperatures()` to accept `$server` array param
- [ ] [P] Refactor `get_auto_control($server)` — per-server config path `auto-control-{id}.json`
- [ ] [P] Refactor `save_auto_control($config, $server)` — same path logic
- [ ] [P] Refactor `get_presets($server)` — per-server path `presets-{id}.json`

## Group 3 — Page load + API routes
- [ ] Replace single-server page-load block with multi-server loop building `$ALL_DATA`
- [ ] Add `?server=N` param handling to all GET API routes
- [ ] Add `server` index param handling to all POST action handlers

## Group 4 — Alpine stores
- [ ] Create `servers` store: `list`, `active`, `current` getter, `summary(i)`, `refresh(i)`
- [ ] Refactor `fans` store to delegate to `$store.servers.current.fans`
- [ ] [P] Refactor `temperatures` store to delegate to `$store.servers.current.temperatures`
- [ ] [P] Refactor `autoControl` store to delegate to `$store.servers.current.autoControl`
- [ ] [P] Refactor `presets` store to delegate to `$store.servers.current.presets`
- [ ] [P] Refactor `app` store `applySpeeds()` to include `?server=N` in POST

## Group 5 — HTML
- [ ] Add summary cards row (before auto-control section, ~line 510)
- [ ] Add tab bar (below summary cards)
- [ ] Ensure content area reactively reflects `$store.servers.active` (all refs via `current`)

## Group 6 — Daemon + Supervisor
- [ ] Parameterize `fan-daemon.php`: accept `$argv[1]` as server ID, per-server config/PID paths
- [ ] Update `docker/supervisord.conf` with up to 5 static daemon entries

## Group 7 — Verification
- [ ] Single server, no `servers.json` → behaviour identical to current (backwards compat)
- [ ] Two servers in `servers.json` → two cards, two tabs, data fetched independently
- [ ] Max temp ≥ profile `maxTemp` → card border and bg turn red
- [ ] Apply fan speed on server 1 → SSH goes to server 1 host only
- [ ] Auto-control toggle on server 2 → only `auto-control-server2.json` changes
