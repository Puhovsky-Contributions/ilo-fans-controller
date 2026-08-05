# Proxmox + Docker integration

- [x] lib/proxmox-disks.php — list + smart per disk, parse NVMe/SCSI temps
- [x] fan-daemon — iLO + Proxmox temps for fan calc
- [x] ilo-fans-controller — storage zone from Proxmox
- [x] config / Dockerfile / compose / .env.example / servers.json.example
- [x] supervisord logs → stdout/stderr
- [x] manual verify against live Proxmox (user env) — parser smoke-tested locally; live API needs user creds

## Review

One API for all disk temps: **no** — `disks/list` has health/wearout only; temperature requires `disks/smart` per `devpath`.
