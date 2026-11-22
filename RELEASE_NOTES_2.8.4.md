# Downloader 2.8.4 — Intégrité étendue à la bibliothèque

Cette release étend le contrôle d'intégrité vidéo aux fichiers déjà présents
dans l'index Automation, et ajoute des contrôles ciblés depuis Connected Lists
et Library Browser.

Aucune migration manuelle n'est nécessaire. Les bases et dossiers manquants sont
créés automatiquement.

## Intégrité vidéo

- Le scan couvre désormais l'historique de téléchargement **et** les fichiers
  vidéo actifs de `library_entries`. Les fichiers déjà présents sur le NAS sont
  détectés même s'ils n'ont jamais été téléchargés par l'application.
- Dédoublonnage par chemin : une entrée d'historique et une entrée
  bibliothèque partagent le même contrôle. L'historique fournit alors
  `download_id`, la taille attendue et la source.
- Nouveaux contrôles ciblés **Check integrity** dans Connected Lists et dans la
  fiche Discovery de Library Browser, avec filtres `show_folder` / `tmdb_id`.
- Les fichiers sans source connue affichent « source inconnue » ; le requeue
  automatique est désactivé pour eux.
- La page Integrity indique la provenance du contrôle (`History`,
  `Library` ou `History + Library`).
- `app:integrity-check` accepte `--history-only`, `--show` et `--tmdb` en plus
  des options existantes.

## Tests

- `composer test:functional` : OK.
- `tests/integrity_smoke.php` : 167 tests OK, dont scan bibliothèque seul,
  dédoublonnage historique/bibliothèque et requeue sans source.
- `lint:twig` et `lint:container` : OK.
- Validation réelle en dry-run : Rurouni Kenshin (47/47 fichiers détectés),
  Gachiakuta (24 fichiers, 11 incomplets).

## Build Docker 2.8.4

Mesure sur le build incrémental depuis l'image 2.8.3, avec cache de couches :

- Temps de build : **33,8 s**.
- 11 nouvelles couches (source, templates, `composer install` final, dossiers
  runtime, configuration finale).
- **2,10 MiB** de blobs compressés à uploader, plus le manifeste et la config
  (quelques Ko).
- Les couches lourdes (librespot-auth, venv Python, vendor Composer, paquets
  système) sont intégralement réutilisées depuis le cache.

```bash
IMAGE_TAG=2.8.4 docker compose pull
IMAGE_TAG=2.8.4 docker compose up -d
```

Les tags `2.8` et `latest` pointent sur 2.8.4.

## Limites connues

- Un fichier bibliothèque sans entrée d'historique source ne peut pas être
  remis en file automatiquement ; il reste contrôlable et visible dans
  Integrity.
- Pour un fichier bibliothèque seul, la taille attendue vient de
  `library_entries.size`. Une troncature antérieure au premier scan ne peut pas
  toujours être déduite de la taille, mais les contrôles de signature, de taille
  minimale et `--deep` restent appliqués.
