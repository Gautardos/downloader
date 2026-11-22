# Downloader 2.8.5 — Compatibilité Spotify Free

Cette release corrige l'authentification Spotify pour les comptes Free.

## Correctif Spotify Free

- `librespot-auth` utilise désormais une classe d'appareil configurable.
- La classe par défaut passe à **`computer`**, qui fonctionne avec un compte
  Spotify Free comme avec un compte Premium.
- L'ancienne classe `speaker` reste disponible, mais Spotify exige Premium pour
  ce type d'appareil : elle apparaît grisée avec « premium requis » sur un
  compte Free.
- Nouveau réglage **Librespot Auth Device Class** dans Settings.
- La commande générée transmet explicitement `--class=<classe>` ; une valeur
  invalide retombe sur `computer`.
- Documentation mise à jour (README, README.fr, guide d'authentification).

En attendant la mise à jour, le contournement manuel est :

```bash
librespot-auth --name "Speaker Free" --class=computer
```

## Mise à jour

Aucune migration manuelle n'est nécessaire.

```bash
IMAGE_TAG=2.8.5 docker compose pull
IMAGE_TAG=2.8.5 docker compose up -d
```

## Tests

- `tests/librespot_auth_smoke.php` : 35 tests OK, dont classe par défaut
  `computer`, classe explicite et fallback d'une valeur invalide.
- `php tests/functional_flow_smoke.php` : 209 tests OK.
- `lint:twig` et `lint:container` : OK.
