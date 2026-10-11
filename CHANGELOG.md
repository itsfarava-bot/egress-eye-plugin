# Egress Eye changelog

## 1.0.0 — initial release candidate

- Record slow blocking WordPress HTTP API calls of at least one second.
- Show exact host, duration, slow-only totals and probable source as a guess.
- Allow administrators to manually block/unblock exact initial hosts.
- Require capability, POST, state-bound nonce, loopback confirmation and audit.
- Bound diagnostic data and active request contexts; keep blocked hosts.
- Bundle local admin styling and Persian translation.
- Provide isolated read-only actions after successful recording/state commits.
- Delete owned tables, options, notices and maintenance cron on uninstall.

The plugin version is 1.0.0. The independent database schema version remains 2.
Development baseline 0.1.0 was not a public directory release.
