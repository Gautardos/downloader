#!/usr/bin/env bash
# Publie une version de l'image :  ./release.sh 1.0.0
# Construit depuis git (arbre propre exige), controle l'image, pose le tag vX.Y.Z, pousse image et tag.
set -euo pipefail
V="${1:?usage : ./release.sh <version, ex. 1.0.0>}"
IMG=gautardos/downloader-app
cd "$(dirname "$0")"
[ -z "$(git status --porcelain)" ] || { echo "ARRET : modifications non validees (git status)"; exit 1; }
[ "$(git branch --show-current)" = main ] || { echo "ARRET : publier depuis main"; exit 1; }
! git rev-parse -q --verify "refs/tags/v$V" >/dev/null || { echo "ARRET : v$V existe deja"; exit 1; }
docker build --pull -t "$IMG:$V" -t "$IMG:latest" .
docker run --rm --network none --entrypoint sh "$IMG:$V" -c '
  cd /var/www/html || exit 1
  grep -q "^APP_SECRET=change-me" .env || { echo "ARRET : .env de l image non neutre"; exit 1; }
  for f in .env.local .env.dev public/info.php public/dump_api.php public/debug.txt var/db/torrents.db; do
    [ ! -e "$f" ] || { echo "ARRET : $f present dans l image"; exit 1; }
  done
  echo "Image propre"'
read -r -p "Publier $IMG:$V et :latest sur Docker Hub ? [o/N] " R
[ "$R" = o ] || { echo "Abandon (image locale gardee)"; exit 1; }
git tag -a "v$V" -m "Version $V"
docker push "$IMG:$V"
docker push "$IMG:latest"
git push origin main "v$V"
echo "Publie : $IMG:$V"
