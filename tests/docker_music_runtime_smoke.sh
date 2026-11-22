#!/usr/bin/env bash
set -euo pipefail

IMAGE="${1:-gautardos/downloader-app:2.8.5}"
ZOTIFY_COMMIT="6e45c5ff9b504583d2c5223442baf788bf95be09"

echo "Docker music runtime smoke: $IMAGE"

docker run --rm --entrypoint sh "$IMAGE" -c '
set -eu
cd /var/www/html
failed=0

ok() {
    echo "  ok   $1"
}

ko() {
    echo "  FAIL $1"
    failed=1
}

BIN=/var/www/html/var/librespot-auth/target/release/librespot-auth
if [ -x "$BIN" ]; then
    ok "librespot-auth binary is executable"
else
    ko "librespot-auth binary is missing or not executable ($BIN)"
fi

if [ -x "$BIN" ] && { "$BIN" --help >/dev/null 2>&1 || "$BIN" --version >/dev/null 2>&1; }; then
    ok "librespot-auth --help/--version works"
else
    ko "librespot-auth --help/--version failed"
fi

if /opt/venv/bin/python -c "import zotify" >/dev/null 2>&1; then
    ok "zotify imports from /opt/venv"
else
    ko "zotify import failed from /opt/venv"
fi

if /opt/venv/bin/pip freeze 2>/dev/null | grep -q "6e45c5ff9b504583d2c5223442baf788bf95be09"; then
    ok "zotify is pinned to the expected commit"
else
    ko "zotify pin 6e45c5ff9b504583d2c5223442baf788bf95be09 not found in pip freeze"
fi

tmpdir=$(mktemp -d)
verify_output=$(python3 cli/music_downloader.py --verify "$tmpdir" 2>/dev/null || true)
rm -rf "$tmpdir"
if [ "$verify_output" = "[]" ]; then
    ok "music_downloader --verify on an empty directory returns []"
else
    ko "music_downloader --verify returned unexpected output: $verify_output"
fi

if python3 cli/spotify_user.py --help >/dev/null 2>&1; then
    ok "spotify_user.py --help exits 0"
else
    ko "spotify_user.py --help failed"
fi

exit "$failed"
'
