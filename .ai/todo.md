# fan control UI + disk ignore

## Plan
- [x] Card max °C → only `fanControlActive` groups (+ non-ignored disks)
- [x] 🏠 click → toggle `fanControlSources`, save auto-control.json
- [x] `ignoredDisks` in config; filter pve:disks in daemon + max()
- [x] Disk row ignore button; label `dev · serial/wwn`
- [x] Remove `ilo:memory` mono token on zone cards
- [x] Agent: smartctl serial + WWN in JSON

## Review
Summary + alert align with daemon max(). Per-disk ignore needs agent deploy for real serials (sysfs model ≠ serial).

## Fix 2026-08-06
- [x] Zone card avg/min/max/count → exclude `ignored` disks in `temperature_finalize_group`
- [x] Grid `items-start` only (neighbor card no stretch on expand); no col-span

## How to test
1. Card max matches hottest among 🏠 zones only (not ambient etc.)
2. Click 🏠 → `fanControlSources` in saved JSON toggles
3. Disks → ignore nvme → card/daemon ignore its temp; `ignoredDisks` has token
4. After agent upgrade: API disk names show serial not Samsung ellipsis
