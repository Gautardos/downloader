# Downloader 2.8.2 — Build & cache optimization

Release 2.8.2 has **no functional change**. It focuses on making Docker builds
faster, more reproducible and cheaper to push, and adds non-regression tests for
the Spotify/librespot-auth/Zotify interoperability layer.

## Build & Docker

- **Layer cache optimized**: base/Apache/PHP → Rust → Python → Composer vendor →
  application source → Composer final/scripts → runtime directories →
  supervisord/entrypoint.
  A PHP-only change now rebuilds and pushes only the source, Composer final and
  `var/` layers; the expensive system, Rust, Python and vendor layers stay
  cached.
- **Rust pinned**: `librespot-auth` is built from
  `f16fa8ad7159aff6af4933fd782707c8e876fc8c` (`ARG LIBRESPOT_AUTH_REF`).
  The binary is marked executable right after `cargo build`; the late
  `find ... chmod +x` layer was removed.
- **Python cache-bust**: `cli/requirements.txt` is copied alone into the image
  and installed through `/opt/venv`, with
  `ARG PYTHON_DEPS_CACHE_BUST=1` available to force a refresh without touching
  the rest of the image.
- **Zotify pinned**: `git+https://github.com/Gautardos/zotify.git@6e45c5ff9b504583d2c5223442baf788bf95be09`.
- **Composer vendor separated from source**: vendor dependencies are installed
  before the source copy, then the autoloader and Symfony scripts run in a
  dedicated final layer.

## Tests

New `composer test:music-runtime` suite (also part of `test:functional` and
`test:all`):

- `tests/librespot_auth_smoke.php`: fake `librespot-auth` binary, credentials
  transformation, missing/invalid output, non-zero exit, timeout, and argument
  injection safety.
- `tests/music_downloader_command_smoke.php`: complete Zotify flag mapping,
  quality fallback, boolean handling, and `MusicTrackVerifier` matching.
- `tests/music_downloader_smoke.py`: `lyrics_are_synced`, `safe_tag`,
  `--verify`, `--tags`/`--update-tags`, numeric tag clearing, and the
  `music_binary` wrapper (exact argv, exit code propagation, missing binary).
- `tests/spotify_user_smoke.py`: playlist mapping, pagination, local-track
  filtering, snapshot id, and 401/403/429 fallbacks with a public warning.

All of these use fakes and monkeypatched HTTP helpers: no real Spotify account,
no real Zotify download and no real `librespot-auth` binary is used.

`tests/docker_music_runtime_smoke.sh` validates a built image (executable
`librespot-auth`, `--help`/`--version`, `import zotify`, pinned commit in
`pip freeze`, empty-directory `--verify` returning `[]`, and
`spotify_user.py --help`). It is intentionally outside `test:all` because it
requires Docker.

## Upgrade

No configuration or data migration is required.

```bash
IMAGE_TAG=2.8.2 docker compose pull
IMAGE_TAG=2.8.2 docker compose up -d
```

The `2.8` and `latest` tags point to 2.8.2.

## Docker Hub note for 2.8.1

At the time of publishing 2.8.2, the `gautardos/downloader-app:2.8.1` manifest
was not available on Docker Hub (the original push failed with repeated 502
errors). The recommended image is therefore **2.8.2** (or `latest`, which points
to 2.8.2). The local `v2.8.1` git tag and GitHub release are left untouched.
