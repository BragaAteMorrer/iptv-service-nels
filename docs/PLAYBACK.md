# HLS playback proof of concept

Open `http://127.0.0.1:8080/player.html` after starting the dev stack.

The browser player supports:
- Native HLS where provided (e.g. Safari), else hls.js in Media Source Extensions compatible browsers.
- Play/pause, mute, fullscreen, playback errors, network/media recovery, transient info banner.
- Three *inspired* visual themes: NOS, CanalSatellite 1996 and TPS 1998.
- A manually supplied direct HTTP(S) HLS URL with no embedded username/password (including query parameters named username/password).

**This is a prototype, not end-to-end provider playback:** registered M3U/Xtream credentials and decrypted catalog stream URLs are deliberately NOT returned to the browser. A future authenticated playback-session service must resolve private streams server side and proxy/relay manifests and media with server-side restrictions and bounded resources.

The CDN dependency hls.js is pinned for convenience. Before deploying: bundle locally and pin/check the artifact using a lockfile; add CSP and full authentication. Stream sources must permit cross-origin requests if accessed directly by the browser. Do not attempt DRM bypass.

The historical themes currently customize color and presentation only; faithful skin components and D-pad navigation will follow.
