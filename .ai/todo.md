# temperature zones unify

## Plan
- [x] `lib/temperature-zones.php` — registry, `source` = fanControlSources token
- [x] PVE groups: `zone` cpu/disks (not pve-cpu), `source` pve:cpu / pve:disks
- [x] iLO groups: `source` ilo:{zone}, same short `zone` as daemon iloZones keys
- [x] `is_fan_control_source_active(source)` replaces section+zone hack
- [x] UI: key `group.source`, mono token on card, legend mentions fanControlSources

## Review
One id everywhere: config, API, UI, daemon bySource keys align.

## How to test
1. `?api=temperatures&server=0` — each group has `source`, `section`, `zone`; no `pve-cpu`
2. 🏠 on groups whose `source` ∈ top-level `fanControlSources`
3. UI cards show `ilo:memory` etc. beside label (sm+)
