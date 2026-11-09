# No-terminal imports

Apply migration `migrations/004_import_jobs.sql` alongside previous migrations.

The admin page supports upload of an M3U/M3U8 playlist file and adding remote M3U/Xtream/MAG sources. Remote M3U and Xtream registrations enqueue background import jobs; MAG registration remains pending for a provider-specific authorized adapter (no false success). Files are placed in a private shared Docker volume, queued, imported in the worker, then deleted even if processing fails.

The `worker` Compose service runs the internal commands automatically. Users should not enter commands for everyday imports. Admins still need initial deployment/migration steps. A failed remote sync requires inspection of trusted host allowlist and network isolation; direct provider HTTP requests are not enabled from the browser.

The frontend polls `GET /api/imports` every few seconds. Status values: queued, running, completed, failed. Never commit M3U access URLs, MAC addresses, usernames or passwords. Local upload max 350 MiB; adjust reverse proxy / PHP `upload_max_filesize` and `post_max_size` when deploying.
