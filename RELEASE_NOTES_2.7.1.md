# Downloader 2.7.1 — Qualité Spotify & robustesse

> Une mise à jour mineure centrée sur la qualité Spotify selon le compte, la fiabilité de la mise à jour de la base torrents et le bruit des scans de sécurité.

## Qualité Spotify selon le compte

- **Auto** est désormais la qualité par défaut : 320 kbps avec Premium, 160 kbps avec un compte gratuit.
- L'écran **Settings → Music Downloader** explique les limites : `normal` = 96 kbps, `high` = 160 kbps, `very_high` = 320 kbps (Premium uniquement).
- La valeur invalide `medium` est supprimée. Toute valeur inconnue retombe sur `auto`.
- La page **Music** affiche la qualité active et un lien direct vers les Settings.
- Si `very_high` est utilisé avec un compte gratuit, le worker ajoute un message actionnable sur `Audio key error` (« 320 kbps requires Spotify Premium… ») dans les logs, l'historique et les notifications.
- Les README documentent les limites Free/Premium et indiquent d'utiliser les credentials du même compte.

## Mise à jour de la base torrents

- L'import ne dépend plus de la limite PHP de 30 secondes : `set_time_limit(0)` et `ignore_user_abort(true)`.
- L'image applique `max_execution_time = 300` au SAPI web (les workers CLI restent sans limite).
- Si le serveur renvoie du HTML au lieu de JSON, l'UI affiche un message clair au lieu de `Unexpected token '<'`, et rappelle que l'import est idempotent : un nouvel essai ne réimporte pas les hashs existants.

## Audit des lyrics

- Les en-têtes de section type `[Verse 1: Chris Webby]` ne sont plus pris pour des timestamps invalides.
- Les mots de boilerplate (`share`, `embed`, etc.) ne sont plus détectés à l'intérieur d'un mot ou d'une phrase de lyrics.
- Les timestamps réellement malformés et les vrais marqueurs de scraper restent détectés.
- Tests de non-régression ajoutés.

## Sécurité

- Les fichiers et dossiers cachés (`.git`, `.env`, `.aws`, `.claude`, etc.) sont bloqués au niveau Apache avant Symfony, tout en conservant `/.well-known/` pour les challenges ACME.
- Cela réduit le bruit des scanners de secrets dans les logs.

## Mise à jour

```bash
docker pull gautardos/downloader-app:2.7.1
```

Ou avec le compose fourni :

```env
IMAGE_TAG=2.7.1
```

Aucune migration manuelle n'est nécessaire. Les chemins, la file et la base existants continuent de fonctionner.
