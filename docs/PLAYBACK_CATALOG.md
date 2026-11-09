# Playback MVP (HLS)

Open `http://localhost:8080/tv.html` and enter the local admin token; click **Ouvrir le bouquet**. Channels are loaded from the lineup; selecting a channel requests `POST /api/playback/channel/{id}/session` (admin token header). The response contains a same-origin short-lived encrypted relay ticket, not provider credentials.

The browser requests `GET /api/playback/relay?ticket=...`; the server validates expiry and provider DNS/IP against the explicit trusted-host allowlist, with redirects disabled. HLS playlists are rewritten to reference the relay for variant manifests and media segments; streaming payloads are limited to 32 MiB per request and playlists to 2 MiB.

This is an **experimental local-development implementation**, not production-hardened. Limitations:
- Supports plain HLS (.m3u8), not direct MPEG-TS (.ts), catch-up, VOD, DRM or signed-provider-specific redirect flows.
- Xtream playback assumes the `/live/{user}/{password}/{stream_id}.m3u8` convention. Some providers use TS-only or other naming.
- 15-minute tickets: a running long session needs refreshing/resigning to continue beyond expiry.
- Some HLS manifests contain custom URL, key or discontinuity formats, and encryption is rejected.
- PHP built-in server is unsuitable for high-throughput concurrent long-running streaming; a dedicated reverse proxy and stream service are needed.
- Plain HTTP transmits data without transport encryption between server and provider.
- Tokens in relay URLs must not be logged or disclosed; protect the local application and restrict network egress independently of application URL validation.
- The existing repository does not yet have end-to-end Docker tests for this feature.

Do not publish the server or expose this prototype on a public IP. No subscription credentials should be committed.
