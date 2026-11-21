# Downloader 2.7.2 — Logs centralisés & rotation

> Tous les journaux applicatifs vivent désormais dans `var/storage/logs/`, avec rotation automatique et permissions cohérentes pour l'agent de logs de l'hôte.

## Logs centralisés

- `worker.log`, `automation_worker.log`, `active_worker.log` et `history_<downloadId>.log` sont écrits dans `var/storage/logs/`.
- Les sorties `automation_title_refresh.log` et `automation_release_dry_run.log` rejoignent le même dossier.
- Les stderr du worker et de l'automation worker sont capturés dans `worker_error.log` et `automation_worker_error.log`.
- Au démarrage, les anciens `var/storage/*.log` sont déplacés dans `logs/` sans modifier leurs droits.
- Le dossier reste en **2750 setgid** ; les fichiers sont créés en **640** et héritent du groupe de `logs/`.

## Rotation

- **5 Mo par fichier, 5 sauvegardes** (`.1` … `.5`) via `LogRotator` pour les logs applicatifs.
- Rotation Supervisor équivalente pour les stderr.
- Le stdout des workers n'est plus dupliqué dans `/var/log`.

## Interface et documentation

- La page **Logs** ne liste plus que les `history_*.log` pour éviter les liens cassés.
- README, README FR et AGENTS décrivent les nouveaux chemins et la rotation.

## Mise à jour

```bash
docker pull gautardos/downloader-app:2.7.2
```

Ou avec le compose fourni :

```env
IMAGE_TAG=2.7.2
```

Aucune migration manuelle n'est nécessaire. Les logs existants sont déplacés automatiquement au démarrage du conteneur.
