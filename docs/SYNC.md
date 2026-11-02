# Source synchronization

This is a first-pass CLI synchronizer (not a production-ready job queue).

## Prerequisites

- Finish the backend setup in docs/SETUP.md.
- Set IPTV_TRUSTED_HOSTS to a **comma-separated list of fully trusted provider hostnames**, exact match only, no wildcards. Example: `IPTV_TRUSTED_HOSTS=provider.example.org`.
- Only HTTPS endpoints are supported for remote sync. Redirects are disabled.
- Never commit source URLs or credentials.

## Run

```bash
docker compose exec api php bin/console iptv:sync:source 1
```

- M3U: HTTP response is streamed to a bounded temporary file (350 MiB max), then parsed one entry at a time; writes committed every 1000 rows.
- Xtream-compatible: imports metadata from `get_live_streams`, `get_vod_streams`, `get_series`. Playback URL construction, credentials issuance, categories and episode expansion are not yet implemented.
- MAG: intentionally not enabled; adapter depends on a documented, authorized portal.
- Source statuses: pending, syncing, ready, error.
- Imported streaming URLs and source addresses are encrypted in DB. Never return them through public list endpoints.

**Security limitations:** trusted hostname allowlists are an administrative restriction, not complete SSRF protection against a malicious allowed host or DNS rebinding. For production, enforce egress firewall and connection-IP verification. This command must run in a restricted container with no access to cloud metadata/internal services. Do not expose source registration to unauthenticated users.

**Operational limitations:** batches are committed progressively and a failed sync can leave partial imports. No pruning of stale records; no lock against concurrent jobs; no progress counters; large Xtream API payloads may need streaming JSON and throttling in the next iteration. This implementation should be reviewed and tested before deployment.
