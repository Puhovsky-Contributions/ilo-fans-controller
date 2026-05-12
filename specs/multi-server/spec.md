# Spec: Multi-Server Monitoring

## Problem
The app manages fans on a single HP iLO server. Users with multiple servers
must run separate instances with no unified view.

## Goal
Monitor and control up to 5 HP iLO servers from one interface.

## User Stories
- As a user, I can see all my servers at a glance in a summary row at the top
- As a user, I can switch between servers using tabs
- As a user, I am alerted visually when a server exceeds its thermal profile limit
- As a user, I configure servers in a file — no UI for server management

## Acceptance Criteria
- [ ] Summary row shows one card per server: name, active profile, max temp + sensor name, avg fan %
- [ ] Card turns red when max temp ≥ active profile's `maxTemp`
- [ ] Tab bar switches the full UI to the selected server
- [ ] All existing features (fans, temps, auto-control, presets) work per server independently
- [ ] Configuring servers requires only editing `servers.json` — no code change
- [ ] Single-server deployments (no `servers.json`) work identically to today (backwards compat)
- [ ] Auto-control daemon runs independently per server

## Out of Scope
- UI for adding/removing/editing servers
- More than 5 servers
- Cross-server aggregation or comparison views
