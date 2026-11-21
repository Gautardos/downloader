# AGENTS.md — Guide technique du projet Downloader

> Document destiné aux agents de développement qui reprennent ce dépôt.
> Il décrit l'architecture réelle du code, les contrats entre composants, les flux
> métier et les pièges connus. Le `README.md` reste la documentation utilisateur ;
> ce fichier est la référence d'implémentation.

---

## 1. Vue d'ensemble

**Downloader** est une application web **self-hosted** de gestion de médias,
livrée sous forme d'**image Docker unique**. Elle combine :

- **Symfony 6.3** (PHP 8.1+) pour le backend web, les routes et les vues Twig.
- **Python 3** (scripts `cli/`) pour le moteur de téléchargement musical, les
  paroles et le tagging/renommage/déplacement.
- **Alldebrid** comme service de debrid cloud : l'application n'est *pas* un
  client BitTorrent, elle délègue le P2P puis télécharge les liens HTTP débridés.
- **Zotify** (fork `Gautardos/zotify`) comme moteur de téléchargement Spotify.
- **OpenRouter / xAI** en option pour le renommage intelligent et la classification
  de genres, avec un modèle configurable par usage.
- **Supervisor** pour lancer Redis, Apache et le worker de téléchargement.
- **Redis** uniquement pour le stockage des sessions PHP.
- **SQLite** en option pour l'index local de recherche de torrents
  (`gautardos/hash-db`).
- **Aucune base SQL applicative** : l'état (config, file d'attente, historique)
  est stocké dans des **fichiers JSON** sous `var/storage/`.

Deux grands domaines fonctionnels :

1. **Vidéos / torrents / liens directs** via Alldebrid.
2. **Musique** : recherche Spotify → téléchargement Zotify → paroles → tags →
   déplacement dans une bibliothèque organisée.

---

## 2. Stack et versions

| Composant | Version / contrainte | Notes |
| --------- | -------------------- | ----- |
| PHP | `>=8.1` (`composer.json`), image `php:8.3-apache` | L'environnement de dev local peut être plus récent |
| Symfony | `6.3.*` | Micro-kernel via `MicroKernelTrait` |
| Twig | fourni par `symfony/twig-bundle` 6.3 | Templates sans pipeline d'assets |
| Guzzle | `^7.5` | Appels HTTP Alldebrid, Grok, Spotify |
| `gautardos/hash-db` | `dev-master` (dépôt VCS GitHub) | Base SQLite de torrents + CSV de hashs |
| Python | 3 (venv `/opt/venv` dans l'image) | |
| Zotify | `git+https://github.com/Gautardos/zotify.git` | Binaire configurable (`music_binary`) |
| Redis | paquet système + extension PHP `redis` | Sessions uniquement |
| SQLite | paquet système + PDO SQLite | Recherche torrents |
| 7zip | `p7zip-full` | Extraction de l'archive splittée de la base torrents |
| Rust / Cargo | installé à la compilation | Compile `librespot-auth` dans l'image |

Dépendances PHP complémentaires (voir `composer.json`) : `symfony/process`,
`symfony/console`, `symfony/yaml`, `symfony/dotenv`, `symfony/runtime`,
`symfony/flex`.

---

## 3. Arborescence et rôle des fichiers

```text
.
├── Dockerfile                  # Image unique : Apache + PHP + Python + Redis + librespot-auth
├── supervisord.conf            # Lance redis, apache2 et le worker
├── .env                        # APP_ENV=dev par défaut (fichier suivi par git)
├── composer.json / .lock
├── bin/console                 # Console Symfony
├── config/
│   ├── bundles.php
│   ├── packages/
│   │   ├── framework.yaml      # Sessions Redis, secret
│   │   ├── twig.yaml
│   │   ├── cache.yaml
│   │   └── routing.yaml
│   ├── routes.yaml             # Charge les routes par attributs de src/Controller
│   └── services.yaml           # Câblage + RedisSessionHandler
├── public/
│   ├── index.php               # Front controller Symfony
│   ├── .htaccess
│   ├── info.php                # phpinfo() — DEBUG, à retirer en prod
│   ├── logo.png / favicon.ico
├── src/
│   ├── Kernel.php
│   ├── Command/DownloadWorkerCommand.php   # Worker CLI de la file
│   ├── Controller/
│   │   ├── AuthController.php
│   │   ├── ConfigController.php
│   │   └── DashboardController.php         # Contrôleur principal (très gros)
│   ├── EventSubscriber/
│   │   ├── AuthSubscriber.php              # Garde d'authentification globale
│   │   ├── ConfigSubscriber.php            # Injecte `storage` dans Twig
│   │   └── SessionIdleSubscriber.php       # Libère les locks de session sur les routes de polling
│   └── Service/
│       ├── AlldebridService.php   # API Alldebrid (v4 et v4.1)
│       ├── AuthService.php        # Auth session basée config JSON
│       ├── DownloadManager.php    # Téléchargement HTTP natif PHP + progression
│       ├── AiService.php          # Provider IA (OpenRouter/xAI) + modèles par usage
│       ├── GrokService.php        # Wrapper renommage média
│       ├── JsonStorage.php        # Persistance JSON avec flock
│       ├── MediaAddonService.php  # Manifests Stremio/Nuvio + agrégation de streams
│       ├── MediaDownloadService.php # Torrent Stremio → Alldebrid → queue
│       ├── NuvioCollectionService.php # Import des collections Nuvio + mapping catalogues
│       ├── MediaTypeHelper.php    # Détection type/icône par extension
│       ├── QueueManager.php       # File d'attente + historique + démarrage worker
│       ├── TmdbService.php        # Recherche/métadonnées TMDB + IMDb IDs
│       ├── SpotifyService.php     # Métadonnées Spotify (Client Credentials)
│       ├── SpotifyUserService.php # Playlists du compte via token librespot/Zotify
│       └── TorrentDbService.php   # Recherche SQLite
├── templates/
│   ├── base.html.twig             # Layout global : nav, toasts, tracker, polling
│   ├── auth/login.html.twig
│   ├── config/index.html.twig     # Tous les réglages
│   ├── dashboard/
│   │   ├── index.html.twig        # Page Debrid/torrents (grosse UI + JS)
│   │   ├── music.html.twig        # Recherche/ajout Spotify
│   │   ├── music_files.html.twig  # Gestion fichiers, tags, paroles, genres, move
│   │   ├── queue.html.twig
│   │   ├── logs.html.twig
│   │   └── locked.html.twig       # Écran "configuration requise"
│   └── history/index.html.twig
├── cli/
│   ├── spotify_user.py            # Playlists/métadonnées via token librespot (privées incluses)
│   ├── music_downloader.py        # Wrapper Zotify + modes --verify/--tags/--update-tags
│   ├── lyrics_fetcher.py          # LRCLib + Genius, écrit dans les tags
│   ├── tag_rename_move.py         # Genre + tags + renommage + déplacement bibliothèque
│   └── requirements.txt
├── var/                           # Gitignoré, runtime
│   ├── storage/                   # JSON d'état (config, queue, historique…)
│   ├── home/                      # HOME applicatif (credentials Spotify, archive)
│   ├── db/                        # torrents.db (SQLite)
│   ├── librespot-auth/            # Binaire Rust compilé (dans l'image)
│   ├── cache/ var/log/ var/composer_home/
└── vendor/                        # Gitignoré
```

Fichiers **suivis** par git : tout `src/`, `templates/`, `config/`, `cli/`,
`public/`, `Dockerfile`, `supervisord.conf`, `composer.json`, `README.md`.
`vendor/`, `var/`, `.env` sont ignorés (`.env` est toutefois déjà tracké).

---

## 4. Démarrage et cycle de vie d'une requête

### Front controller
`public/index.php` → `vendor/autoload_runtime.php` → `App\Kernel`.
Le kernel est un micro-kernel Symfony standard ; les routes sont déclarées par
attributs dans `src/Controller` (`config/routes.yaml`).

### Authentification
`src/EventSubscriber/AuthSubscriber.php`
- Au `kernel.request`, toute requête principale est vérifiée sauf :
  - la route `login` et les routes internes commençant par `_` ;
  - les **routes de polling** listées dans `STATELESS_ROUTES` :
    `notifications_poll`, `queue_status`, `queue_active_log`, `progress`.
- Si non authentifié → redirection vers `login`.

`AuthService` compare le login/mot de passe aux clés `admin_user` /
`admin_password` du JSON `config`, puis écrit `authenticated` + `username` en
session.

> ⚠️ Les routes de polling contournent totalement l'authentification. Ne pas y
> exposer de données sensibles. Les identifiants admin sont stockés en clair
> dans `var/storage/config.json`.

### Sessions
Sessions PHP stockées dans **Redis** (`config/packages/framework.yaml` +
`config/services.yaml`), préfixe `downloader_sess_`, TTL 86400.
`SessionIdleSubscriber` force `session->save()` sur certaines routes de lecture
(`progress`, `queue_status`, `check_files`, debug…) pour relâcher rapidement le
lock de session et éviter les blocages Apache prefork pendant le polling.

### Global Twig
`ConfigSubscriber` injecte l'objet `JsonStorage` dans Twig sous le nom
`storage`. Les templates l'utilisent notamment via
`storage.isConfigured()`, `hasAlldebrid()`, etc.

---

## 5. Persistance : JsonStorage et fichiers d'état

`App\Service\JsonStorage` encapsule `var/storage/<key>.json`.

- `get(key, default)` : lecture avec `flock(LOCK_SH)`, JSON décodé en tableau.
- `set(key, data)` : écriture avec `flock(LOCK_EX)`, `ftruncate` puis écriture
  JSON pretty-print.
- Le répertoire `var/storage` est créé à l'instanciation si absent.
- Aide métier : `isConfigured()`, `hasAlldebrid()`, `hasMusicPath()`,
  `hasDefaultTorrentPath()`, `hasGrok()`, `hasSpotify()`.

### Clés JSON utilisées

| Fichier / clé | Contenu | Producteur / consommateur |
| ------------- | ------- | ------------------------- |
| `config.json` (`config`) | Toute la configuration (voir §7) | `ConfigController`, services |
| `server_queue.json` (`server_queue`) | Tableau d'items en attente | `QueueManager` |
| `active_worker_task.json` (`active_worker_task`) | Item en cours | `QueueManager` / worker |
| `history.json` (`history`) | Historique des opérations | `QueueManager`, contrôleurs |
| `recent_paths.json` (`recent_paths`) | 10 derniers chemins de dl vidéo | `DashboardController` |
| `server_notifications.json` (`server_notifications`) | Notifications à poller par l'UI | worker + contrôleurs |
| `media_addons.json` (`media_addons`) | Manifests Stremio/Nuvio configurés | `MediaAddonService`, `MediaController` |
| `media_collections.json` (`media_collections`) | Collections/dossiers Nuvio importés + rapport | `NuvioCollectionService`, `MediaController` |
| `worker_heartbeat.json` (`worker_heartbeat`) | Timestamp de vie du worker | worker, `QueueManager` |
| `alldebrid_history_cache.json` (`alldebrid_history_cache`) | Snapshot paginé de `user/history` (TTL 60 s, 500 liens max) | `DebridHistoryService` |
| `progress_<downloadId>.json` | Progression d'un téléchargement | `DownloadManager`, `/progress` |

Autres fichiers runtime (hors JsonStorage) :
- `var/storage/worker.lock` : mutex `flock` du worker.
- `var/storage/logs/worker.log` : log texte du worker (rotation 5 Mo / 5 sauvegardes via `LogRotator`).
- `var/storage/logs/worker_error.log` : stderr du worker (rotation 5 Mo / 5 sauvegardes via Supervisor).
- `var/storage/logs/automation_title_refresh.log` : sortie du refresh des titres (rotation via `LogRotator`).
- `var/storage/logs/automation_release_dry_run.log` : sortie du dry-run des releases (rotation via `LogRotator`).
- `var/storage/logs/active_worker.log` : log du téléchargement musique en cours.
- `var/storage/logs/history_<downloadId>.log` : log détaillé par téléchargement.
- `var/db/torrents.db` : base SQLite de recherche de torrents.

> **Logs** : `var/storage/logs/` est le seul dossier lu par l'agent de logs de
> l'hôte (2750 setgid sur l'hôte). Ne pas appeler `chmod`/`chgrp` sur ce dossier
> depuis le code : les nouveaux fichiers héritent du groupe autorisé.
> `App\Service\LogRotator` écrit avec un umask 027 et tourne à 5 Mo / 5
> sauvegardes.

> ⚠️ **Atomicité** : `get()` puis `set()` ne forment pas une transaction. Deux
> écrivains concurrents peuvent perdre une mise à jour (ex. enqueue simultané,
> heartbeat vs queue). Le `flock` protège seulement l'accès fichier, pas le
> read-modify-write. En cas de besoin de robustesse, ajouter un verrou métier
> ou un mécanisme de merge.

---

## 6. File d'attente et worker

### QueueManager (`src/Service/QueueManager.php`)

- `enqueue(item, type = 'video')` : ajoute à `server_queue`, déduit le type
  via `MediaTypeHelper` si `video`, puis appelle `triggerWorker()`.
- `triggerWorker()` : tente d'acquérir `worker.lock` (non bloquant). Si acquis,
  vérifie le heartbeat (≤ 10 s ⇒ worker déjà actif) puis lance
  `bin/console app:download-worker` :
  - Linux/macOS : `nohup php bin/console app:download-worker > /dev/null 2>&1 &`
  - Windows : `start /B ...`
- `pop()` : retire le premier item (via `getQueue()`, qui relance aussi le
  worker si besoin).
- `setActiveTask()` / `getActiveTask()` : `active_worker_task` avec détection de
  worker mort via heartbeat (seuil **3600 s** pour la musique, **120 s** sinon).
- `removeFromQueue(index)` / `purgeQueue()` : retirent et historisent en
  `canceled`, purgent les fichiers `progress_*.json`.
- `recordHistory(item, status, fileCount, stats)` : ajoute une entrée à
  `history`, tronquée selon `history_retention_limit` (minimum 10).

### Worker (`src/Command/DownloadWorkerCommand.php`)

Commande : `php bin/console app:download-worker`.

Boucle infinie :
1. Écrit `worker_heartbeat = time()`.
2. `pop()` un item dû (`not_before <= now`). Si aucun : efface la tâche
   active, heartbeat, `sleep(2)`.
3. Met la tâche active et émet une notification `Starting`.
4. Traite selon `item['type']` :
   - `music` → `processMusicTask()`
   - sinon → `DownloadManager::downloadFile()` (heartbeat toutes les ~5 s via
     callback), puis `recordHistory(..., 'success', 1, résultat)`.
5. Succès/échec → notification serveur + historique (échec : `recordHistory`
   avec statut `error`), efface la tâche active, `usleep(100000)`.

Un `flock(LOCK_EX | LOCK_NB)` sur `worker.lock` empêche les workers concurrents.
Supervisor relance le worker en cas d'arrêt, mais un seul peut vivre à la fois.

Au démarrage, le worker récupère `active_worker_task` s'il existe : la tâche est
ré-enqueuée si elle n'est ni déjà en queue (même URL/filename) ni déjà terminée
dans l'historique (`success`, `warning`, `error`, `canceled`). Cela évite de
perdre un téléchargement interrompu par un redémarrage du conteneur.

### Structure d'un item de queue

Vidéo (créé dans `DashboardController::download`) :
```php
[
  'url' => <lien Alldebrid débridé>,
  'original_url' => <lien d'origine>,
  'filename' => <nom final>,
  'path' => <répertoire de destination>,
  'download_id' => <id hex>,
  'overwrite' => bool,
  'date_added' => 'Y-m-d H:i:s',
  'type' => 'video'|'audio'|'ebook'|... // déduit de l'extension
]
```

Musique (créé dans `DashboardController::musicAdd`) :
```php
[
  'url' => <URL Spotify>,
  'filename' => 'Spotify: <nom>',
  'path' => <music_root_path>,
  'download_id' => <id hex>,
  'date_added' => ...,
  'type' => 'music',
  'expected_tracks' => [ ['artist'=>..., 'album'=>..., 'song_name'=>...], ... ],
  'retry_count' => int (ajouté lors des retries immédiats),
  'scheduled_retry_count' => int (retries différés API/audio key),
  'not_before' => timestamp Unix (item ignoré par `pop()` avant cette date)
]
```

### Traitement musique (`processMusicTask`)

1. Construit une commande shell :
   `(. /opt/venv/bin/activate && python3 cli/music_downloader.py "<url>" --output ... --download-format ... --root-path ... ...)`
   avec `HOME`, `USERPROFILE`, `APPDATA` pointant vers `var/home` et
   `PYTHONUNBUFFERED=1`.
2. `Process::start()`, puis boucle de lecture incrémentale : heartbeat toutes
   les 0,5 s, copie stdout/stderr dans `active_worker.log` et
   `history_<id>.log`, écrit `progress_<id>.json` avec la dernière ligne.
3. Après succès : exécute `music_downloader.py --verify <root>` pour obtenir un
   JSON des pistes réellement présentes (tags lus par `music-tag`).
4. Compare `expected_tracks` et `verified` sur
   `artist`/`album`/`song_name` après normalisation des deux côtés
   (casse, espaces, séparateurs `/` et `;` → `,`) :
   - toutes présentes → `success`
   - aucune → `error`
   - partielles → `warning`
   - `expected_tracks` vide → `error` (déclenche retry)
5. Détecte les erreurs Spotify/audio key dans la sortie
   (`received an empty response`, `Audio key error`, `Failed fetching audio key`,
   `GENERAL DOWNLOAD ERROR`).
6. Si la tâche est incomplète avec ces erreurs (ou en `error`), historise
   `failed_requeued`, incrémente `scheduled_retry_count`, positionne
   `not_before = now + 300` et ré-enqueue. Le worker ignore l’item pendant
   5 minutes ; Zotify skip ensuite les pistes déjà présentes. Maximum
   `MAX_API_RETRIES = 5`. Si `music_quality` vaut `very_high` et que la sortie
   contient `Audio key error`, le worker ajoute aux logs, à l’historique et à
   la notification la consigne « 320 kbps requires Spotify Premium… » sans
   modifier la logique de retry.
7. Sinon, pour une erreur non-API : retry immédiat jusqu’à 3 tentatives
   (`retry_count < 2`).
8. `recordHistory` avec pistes trouvées/manquantes.

---

## 7. Configuration applicative

Tout est édité sur `/config` (`ConfigController::index`) et stocké dans
`var/storage/config.json`. Toute sauvegarde **réécrit l'intégralité** des clés
avec les valeurs du formulaire (les clés absentes du POST retombent sur leurs
valeurs par défaut).

| Clé | Défaut | Rôle |
| --- | ------ | ---- |
| `api_key` | — | Clé API Alldebrid |
| `ai_provider` | `openrouter` | `openrouter` ou `xai` (fallback auto si clé OpenRouter absente) |
| `openrouter_api_key` | — | Clé API OpenRouter |
| `ai_model_default` | — | Modèle IA de secours global |
| `ai_model_renaming` | — | Modèle IA pour le renommage média |
| `ai_model_music_genre` | — | Modèle IA pour la classification musicale |
| `grok_api_key` | — | Clé API xAI (fallback legacy) |
| `grok_model` | `grok-4-fast-non-reasoning` | Modèle xAI renommage (fallback) |
| `default_path` | — | Répertoire de dl vidéo par défaut |
| `spotify_client_id` | — | Client ID Spotify |
| `spotify_client_secret` | — | Client Secret Spotify |
| `music_output` | `{artist} - {album} - {song_name}.{ext}` | Template de nom Zotify |
| `music_format` | `mp3` | Format audio (`mp3`, `flac`, `m4a`, `opus`) |
| `music_root_path` | — | Répertoire de dl musique (interne conteneur) |
| `music_creds` | `/var/www/html/var/home/.local/credentials.json` | Credentials Spotify |
| `music_archive` | `/var/www/html/var/home/log/archive.txt` | Archive Zotify (skip) |
| `music_quality` | `auto` | Qualité Zotify (`auto`, `normal`, `high`, `very_high`) ; `very_high` = 320 kbps Premium uniquement |
| `music_skip_existing` | `false` | Skip déjà téléchargés |
| `music_lyrics` | `true` | Télécharger les paroles via Zotify |
| `music_progress` | `false` | Progression Zotify |
| `music_print_downloads` | `false` | Logs Zotify |
| `music_progress_info` | `false` | Infos de progression détaillées |
| `music_retries` | `3` | Tentatives réseau Zotify |
| `music_venv_path` | `venv` | Chemin du venv Python (absolu ou relatif au projet) |
| `music_binary` | `zotify` | Binaire appelé par `music_downloader.py` |
| `music_library_path` | — | Bibliothèque finale organisée |
| `music_genre_mapping` | — | JSON `{"genre_patterns": {"regex": "Genre"}}` |
| `music_genre_tagging_mode` | `ai` | `ai` ou `mapping` |
| `music_spotify_genres` | `true` | Utiliser les genres artiste Spotify comme signal avant l’IA |
| `tmdb_api_key` | — | Clé API TMDB ou token v4 |
| `tmdb_language` | `fr-FR` | Langue des métadonnées TMDB |
| `music_genre_grok_prompt` | — | Prompt système de classification |
| `music_genre_grok_model` | `grok-4-fast-non-reasoning` | Modèle xAI genres (fallback) |
| `music_genres` | `false` | Active la gestion des genres |
| `genius_api_token` | — | Token Genius (paroles non synchronisées) |
| `lrclib_token` | — | Token LRCLib (paroles synchronisées) |
| `admin_user` | `admin` | Login admin |
| `admin_password` | `admin` | Mot de passe admin |
| `history_retention_limit` | `500` | Taille max de l'historique (min 10) |
| `grok_renaming_prompt` | — | Prompt personnalisé de renommage |
| `allow_xxx_search` | `false` | Autorise les catégories XXX dans la recherche |
| `music_librespot_auth_binary` | `/var/www/html/var/librespot-auth/target/release/librespot-auth` | Binaire d'auth Spotify |

> Notes :
> - `music_venv_path` est résolu différemment selon les contrôleurs : parfois
>   tel quel, parfois préfixé par le projet, parfois avec un fallback
>   `venv`. Vérifier le contrôleur concerné avant de modifier.
> - `music_library_path` vide désactive le déplacement final.

---

## 8. Routes HTTP

Routes déclarées par attributs. `GET`/`POST` indiqué ; les routes de polling
(`★`) échappent à l'authentification.

### Auth
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET/POST | `/login` | `login` | Connexion |
| GET | `/logout` | `logout` | Déconnexion |

### Vidéo / torrents / liens
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/torrent` | `torrent` | Page Debrid récente (vérifie clé + chemin) ; `?view=packs` charge la vue avancée |
| GET | `/debrid/recent` | `debrid_recent` | Liens récents paginés depuis le cache `user/history` (`offset`, `limit`, `refresh`) |
| POST | `/download` | `download` | Débride et enqueue un fichier vidéo |
| POST | `/dashboard/upload-torrent` | `upload_torrent` | Upload magnet ou `.torrent`, attend le statut, débride les liens |
| POST | `/dashboard/upload-direct-link` | `upload_direct_link` | Débride une liste de liens directs |
| POST | `/dashboard/magnet-info` | `magnet_info` | Aperçu des fichiers d'un magnet |
| POST | `/check-files` | `check_files` | Vérifie existence/taille des fichiers |
| POST | `/create-folder` | `create_folder` | Crée un répertoire récursivement |
| POST | `/rename-ai` | `post_rename_ai` | Suggestions de noms via Grok |
| POST | `/purge-history` | `purge_history` | Purge l'historique Alldebrid (`user/history/delete`) |
| POST | `/delete-path` | `delete_path` | Retire un chemin des récents |

### Recherche torrents (SQLite)
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/torrent-db/categories` | `torrent_db_categories` | Catégories distinctes (filtre XXX) |
| POST | `/torrent-db/search` | `torrent_db_search` | Recherche LIKE normalisée, limite 50 |
| POST | `/torrent-db/description` | `torrent_db_description` | Description gzip décompressée |
| POST | `/torrent-db/preview` | `torrent_db_preview` | Upload magnet + statut v4.1 |

### File d'attente / suivi
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/queue` | `queue` | Page file d'attente |
| GET ★ | `/queue-status` | `queue_status` | `{queue, active}` |
| POST | `/queue-remove/{index}` | `queue_remove` | Supprime un item |
| POST | `/queue-purge` | `queue_purge` | Vide la file |
| GET ★ | `/progress/{downloadId}` | `progress` | Lit `progress_<id>.json` |
| GET ★ | `/queue-active-log` | `queue_active_log` | 100 dernières lignes du log actif si tâche musique |
| GET | `/history-log/{downloadId}` | `history_log` | Log texte d'un dl |
| GET | `/history` | `history` | Historique paginé (50/page) |
| GET | `/notifications-poll` | `notifications_poll` | Lit `server_notifications` sans le vider |
| POST | `/notifications-ack` | `notifications_ack` | Acquitte les notifications affichées (CSRF) |
| GET | `/logs` | `logs` | Liste des fichiers de logs |

### Discover (films / séries)
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/media` | `media_index` | Page Discover (TMDB + addons streams) |
| GET | `/media/search` | `media_search` | Recherche TMDB |
| GET | `/media/details/{type}/{id}` | `media_details` | Détails TMDB + IMDb ID |
| GET | `/media/season/{id}/{season}` | `media_season` | Épisodes d'une saison TMDB |
| POST | `/media/streams` | `media_streams` | Agrège les streams des addons |
| POST | `/media/debrid` | `media_debrid` | Torrent stream → Alldebrid → queue |
| POST | `/media/settings` | `media_settings` | Sauvegarde clé/langue TMDB |
| POST | `/media/addons/validate` | `media_addons_validate` | Valide un manifest HTTPS public |
| POST | `/media/addons` | `media_addons_save` | Sauvegarde les addons streams |
| POST | `/media/catalog/items` | `media_catalog_items` | Récupère les items d’un catalogue |
| GET | `/media/collections` | `media_collections` | Collections Nuvio importées |
| POST | `/media/collections/import` | `media_collections_import` | Import du JSON Nuvio |
| POST | `/media/collections/delete` | `media_collections_delete` | Supprime une collection importée |
| POST | `/media/collections/folder/delete` | `media_collections_folder_delete` | Supprime un dossier d’une collection |
| POST | `/media/collections/clear` | `media_collections_clear` | Supprime les collections importées |
| POST | `/media/resolve-item` | `media_resolve_item` | Résout un item catalogue vers TMDB |

### Musique (recherche / fichiers)
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/` | `music` | Accueil = recherche Spotify |
| POST | `/music-add` | `music_add` | Métadonnées Spotify + enqueue musique |
| GET | `/music/spotify/playlists` | `spotify_playlists` | Liste les playlists du compte (privées incluses) via token Zotify |
| GET | `/music/spotify/search` | `spotify_search` | Recherche publique Spotify (titres, albums, playlists) |
| GET | `/music-files` | `music_files` | Redirection vers `/music-library?root=downloads` |
| GET | `/music/stream` | `music_stream` | Streaming audio (`root=downloads|library` + chemin relatif, ou chemin absolu sous une racine) avec requêtes HTTP Range |
| POST | `/file-tags` | `file_tags` | Extrait les tags d'un fichier |
| POST | `/update-tags` | `update_tags` | Met à jour des tags |
| POST | `/music/update-genres` | `music_update_genres` | Lance `tag_rename_move.py --tags-only` |
| POST | `/music/fetch-lyrics` | `fetch_lyrics` | Lance `lyrics_fetcher.py` |
| POST | `/music/move-to-library` | `move_to_library` | Lance `tag_rename_move.py` (déplacement) |
| GET | `/music-library` | `music_library` | Navigateur dossier de la bibliothèque musicale |
| GET | `/music-library/folder` | `music_library_folder` | Liste JSON des sous-dossiers et fichiers d'un dossier |
| POST | `/music-library/tags` | `music_library_tags` | Lit les tags d'un fichier |
| POST | `/music-library/update-tags` | `music_library_update_tags` | Met à jour une liste blanche de tags (tags only) |
| POST | `/music-library/fetch-lyrics` | `music_library_fetch_lyrics` | Récupère les lyrics d'une piste (fetch unitaire) |
| POST | `/music-library/lyrics-audit` | `music_library_lyrics_audit` | Audit lecture seule des lyrics d'un dossier |

### Configuration / debug
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET/POST | `/config` | `config` | Réglages |
| POST | `/config/ai/models` | `config_ai_models` | Catalogue des modèles OpenRouter |
| POST | `/config/ai/test` | `config_ai_test` | Test provider/clé/modèle par usage |
| POST | `/config/spotify-auth` | `config_spotify_auth` | Génère `credentials.json` via `librespot-auth` |
| POST | `/config/update-hash-db` | `config_update_hash_db` | `composer update` du paquet DB + import CSV |
| GET | `/config/export` | `config_export` | ZIP `config.json` + `credentials.json` |
| POST | `/config/import` | `config_import` | Restaure le ZIP |
| GET | `/debug-storage` | `debug_storage` | Diagnostic répertoire storage |
| GET | `/debug-api` | `debug_api` | Échantillons de réponses Alldebrid |
| GET | `/debug-grouping` | `debug_grouping` | Résumé du groupement par pack |

---

## 9. Services en détail

### AlldebridService

- Base Guzzle : `https://api.alldebrid.com/v4/`.
- Paramètres communs : `agent=downloader-app`, `apikey` (lu dans `config`).
- Méthodes :
  - `validateApiKey()` : `GET user`.
  - `getRecentLinks()` : `GET user/history` → `data.links`.
  - `purgeHistory()` : `GET user/history/delete?all=true`.
  - `getMagnets()` : `GET /v4.1/magnet/status` (chemin absolu pour contourner
    la base `/v4/`).
  - `getSavedLinks()` : `GET user/links`.
  - `getMagnetFiles(ids)` : `POST magnet/files` (`id[]`).
  - `uploadMagnet([...])` : `POST magnet/upload`.
  - `uploadTorrent(file, name)` : `POST magnet/upload/file` (multipart).
  - `saveMagnet(id)` : `GET /v4.1/magnet/status?id=...` (nom trompeur : lit le
    statut, ne "sauvegarde" rien).
  - `unlockLink(link)` : `GET link/unlock` → `data.link`.
  - `unlockLinkFull(link)` : idem + `filename`, `filesize`, `host`.
  - `getStreamingLink(link)` : `GET link/streaming`.
  - `getRecentLinksGrouped()` : agrège l'historique par "pack" en croisant
    les fichiers des magnets prêts (`statusCode === 4`).
  - `flattenMagnetFiles()` : parcours récursif des entrées `e`, calcule le
    `subpath`, enlève la racine redondante.
  - `extractPackFromFilename()` : heuristique de nom de série/pack.
  - `testAllEndpoints()` : debug.

Statuts magnet Alldebrid : `statusCode === 4` = prêt. Le code interrompt
l'attente au-delà de 4, ou dès que des liens sont disponibles.

### DebridHistoryService

- Cache JSON `alldebrid_history_cache.json` alimenté par `AlldebridService::getRecentLinks()`.
- `page(offset, limit, refresh)` : trie les liens par date décroissante, applique
  un TTL de 60 s, conserve au plus 500 entrées et renvoie `links`, `total`,
  `has_more`.
- `invalidate()` : vide le cache (appelé après une purge d'historique).
- Utilisé par la route `debrid_recent` ; ne charge jamais les magnets ni leurs
  fichiers, ce qui évite la lenteur de `getRecentLinksGrouped()` sur la vue par défaut.

### DownloadManager

- Téléchargement **HTTP natif PHP** via `fopen` + contexte de flux
  (`stream_context_create`), pas Guzzle (Guzzle n'est conservé que par
  compatibilité de constructeur).
- `ignore_user_abort(true)`, `set_time_limit(0)`.
- Callback `STREAM_NOTIFY_PROGRESS` : calcule vitesse et pourcentage, écrit
  `progress_<id>.json`, appelle le heartbeat worker toutes les 5 s.
- Mutex applicatif : si `progress_<id>.json` a `status=downloading` et
  `last_update < 15 s`, considère déjà en cours.
- `overwrite=false` et fichier existant → échec `File already exists.`.
- Post-vérification : si taille < 50 Ko et contenu HTML → supprime et lève
  une exception (« expired link? »).
- `isDownloaded(filename)` : simple recherche du nom dans l'historique.

### AiService / GrokService

`AiService` centralise les providers OpenAI-compatibles (OpenRouter, xAI),
résout le modèle selon l’usage (`renaming`, `music_genre`, `default`), gère le
fallback xAI et le catalogue OpenRouter. `GrokService` reste un wrapper de
renommage média.

### GrokService (wrapper)

- Délègue à `AiService` avec l’usage `renaming` (OpenRouter ou xAI).
- Demande une sortie JSON et tolère les réponses entourées de ```json.
- Construit un prompt de renommage standardisé (Séries/Anime/Films/Musique/
  Ebooks) ou utilise `grok_renaming_prompt`.
- Convention vidéo orientée Jellyfin : séries/anime
  `Series Name (Year) - SxxExx - Episode Title.ext` dans
  `Video/TV Shows|Anime/Series Name (Year)/Season XX/`, films
  `Movie Name (Year).ext` dans `Video/Movies/Movie Name (Year)/`.
- Retourne un mapping `{ original_filename: { filename, path } }`.

### TmdbService / MediaAddonService / MediaDownloadService

- `TmdbService` : recherche multi, trending, détails film/série, saisons,
  résolution IMDb via `external_ids`, cache Symfony.
- `MediaAddonService` : charge les manifests `media_addons`, vérifie les URLs
  HTTPS publiques (anti-SSRF basique), agrège les streams des addons compatibles
  `tt`, normalise `infoHash`, `fileIdx`, tailles/seeders, déduplique et limite
  le nombre de résultats. Gère aussi les catalogues (`/catalog/...`) et les
  items (`_tmdbId`, `imdb_id`), y compris les catalogues absents du manifest.
- `MediaDownloadService` : construit un magnet depuis un stream, l’envoie à
  Alldebrid, attend les fichiers, sélectionne le fichier (`fileIdx`, nom, ou
  plus gros fichier vidéo), débride et ajoute à `QueueManager`.
- `NuvioCollectionService` importe les exports Nuvio Collections, conserve les
  sources de tous les addons et les active dynamiquement selon `media_addons`.
  Les sources d’un addon non configuré restent « en attente » (pas besoin de
  réimporter après ajout de l’addon). Seules les sources sans `catalogId` ou
  `addonId` sont ignorées et comptées dans le rapport.
- Le drawer “Media addons” de Discover sauvegarde `tmdb_api_key`,
  `tmdb_language` et `media_addons`, et permet d’importer le JSON Nuvio.
  Aucune URL locale/privée n’est acceptée.

### SpotifyService

- `Client Credentials` sur `accounts.spotify.com/api/token`.
- Token mis en cache en mémoire (buffer 60 s).
- `parseUrl()` accepte `/track/`, `/album/`, `/playlist/` avec segments de
  locale (`/intl-fr/`, `/fr-fr/`) et query `?si=`.
- `getMetadata(url)` retourne `['name' => ..., 'tracks' => [['artist','album','song_name'], ...]]`.
- `search(query, type, limit)` interroge `/v1/search` en Client Credentials
  (`track`, `album`, `playlist`) et retourne des résultats normalisés
  (`id`, `type`, `badge`, `name`, `subtitle`, `meta`, `image`, `url`).
- `getLogs()` expose un journal horodaté utilisé pour écrire
  `history_<id>.log` à l'enqueue.
- `testCredentials()` vérifie l'obtention d'un token.

### SpotifyUserService

- Wrapper PHP autour de `cli/spotify_user.py`, exécuté via `PythonExecutor`
  dans le venv musique.
- Le script ouvre une session `librespot` avec `music_creds` et demande un
  token utilisateur avec les scopes `playlist-read-private`,
  `playlist-read-collaborative`, `user-library-read`, `user-follow-read`.
- `listPlaylists()` retourne les playlists privées et publiques du compte via
  `/v1/me/playlists`, avec pagination.
- Cache `var/cache/spotify_playlists.json` (TTL 24 h ; cache partiel 60 s). En
  cas de `429`, le cache existant est renvoyé avec `stale=true`.
- `getPlaylistMetadata()` récupère le nom et les pistes d'une playlist via
  `/v1/playlists/{id}/tracks` (utilisé par `musicAdd` pour les playlists
  privées, en fallback des Client Credentials).

### TorrentDbService

- Base : `var/db/torrents.db` (SQLite).
- Colonnes exploitées : `name`, `hash_info`, `size`, `description`,
  `parent_category`, `category`, `category_slug`.
- `getCategories()` : `DISTINCT`, filtre `xxx/` si non autorisé, label
  `Type > Sous-type`, tri Z→A.
- `search(categoryId, query, limit)` : `categoryId="all"` = recherche globale ;
  sinon format `"parent-category"` ; normalisation accents/underscores/tirets,
  remplacement des espaces par `%`.
- `getDescription(hash)` : `gzuncompress` de `description`, conversion UTF-8.

### MediaTypeHelper

Mapping extension → type (`video`, `text`, `ebook`, `archive`, `image`,
`photo`, `audio`, `other`) + icônes emoji. Utilisé par la queue pour typer les
téléchargements vidéo/autres.

### AuthService

Comparaison directe avec `admin_user`/`admin_password` (défauts `admin`/`admin`).
Session : `authenticated`, `username`.

---

## 10. Scripts Python (`cli/`)

Tous lisent la config dans `var/storage/config.json` (chemin résolu depuis
`__file__`, un niveau au-dessus de `cli/`). Leur **stdout doit rester du JSON
valide** quand un mode JSON est utilisé (les logs annexes vont sur stderr).

### `music_downloader.py`

Wrapper autour de `music_binary` (Zotify). Modes :

| Invocation | Comportement |
| --- | --- |
| `--verify <dir> [--recursive]` | Parcourt les fichiers audio, lit les tags via `music_tag`, retourne un JSON (artist, album, albumartist, song_name, filename, rel_path, full_path, size, mtime, genre, year, tracknumber, discnumber, comment, lyrics, lyrics_synced). Normalise les séparateurs d'artistes (`/`, `;` → `,`). |
| `--tags <file>` | Retourne un JSON de tous les tags d'un fichier, dont `albumartist`. |
| `--update-tags <file> --<tag> <value>...` | Met à jour les tags via `music_tag`. Une valeur vide sur `year`/`tracknumber`/`discnumber` efface le tag (`None`). |
| `<args...>` | Exécute `music_binary <args...>` en héritant stdout/stderr, propage le code retour. |

> `music_tag` est importé dans `verify_directory` mais **absent de
> `requirements.txt`** (il est probablement fourni par Zotify ou installé dans
> l'environnement). À vérifier lors d'un rebuild.

### `lyrics_audit.py`

- Invocation : `--root <music_root> --path <folder> [--recursive]`.
- Audit **lecture seule** : aucune écriture dans les fichiers audio ni dans les
  tags.
- Détections : mojibake/encodage, timestamps LRC invalides ou non monotones
  (métadonnées `[offset:]`/`[length:]` et timestamps enhanced `<mm:ss.xx>`
  gérés), HTML/JSON/URL/boilerplate, répétition/lignes dupliquées, longueur
  anormale (trop court ou pavé trop long), lyrics manquantes.
- Les symlinks sont ignorés pour rester confiné à la racine.
- Sortie JSON : `counts` agrégés (`ok`, `warning`, `error`, `missing`,
  `flagged`, `by_type`) et `files` avec `issues` (type, severity, message,
  excerpt).
- `MusicLibraryService` met le résultat en cache dans
  `var/storage/music_library_audits.json` pour afficher les compteurs par
  dossier sans relancer le scan.

### `lyrics_fetcher.py`

- Chaîne de providers : LRCLIB (`/get` puis `/search`), Genius, puis lyrics.ovh
  en dernier recours. Deezer n'est pas utilisé : son endpoint lyrics public
  exige désormais OAuth.
- LRCLIB `/search` score les candidats sur titre, artiste, album et durée, avec
  un seuil de confiance pour éviter les mauvais matches. Le provider et la
  confiance sont exposés dans le JSON.
- Lit/écrit MP3 (`ID3`/`USLT`) et M4A (`MP4` `©lyr`). Sur MP3, l'ancien frame
  `USLT` est supprimé avant écriture pour éviter les doublons.
- Détecte les paroles synchronisées de façon stricte (`[mm:ss.xx]` ou
  `<mm:ss.xx>`), sans confondre les en-têtes `[Refrain :]`.
- Options : `--recursive`, `--force-save`, `--force-dl-all`,
  `--force-dl-unsync`, `--add-unsync`, `--json`.
- `--force-dl-unsync` permet de retenter une piste dont les lyrics existants
  sont unsynced.
- Ignore les genres contenant `instrumental` ou `ambiance`.
- Sortie JSON (avec `--json`) consommée par `/music/fetch-lyrics`.

### `tag_rename_move.py`

- Parcourt **uniquement** `*.mp3` du `--source`.
- Détermine l'artiste principal (`albumartist` prioritaire, sinon premier
  artiste), extrait les featurings et enrichit le titre `(feat. ...)`.
- Genre :
  1. mapping regex (`--mapping`, clé `genre_patterns`) si genre existant ;
  2. sinon genre d'un fichier déjà présent à la destination ;
  3. sinon cache session `{artist, album}` ;
  4. sinon genres Spotify artiste (cache token + cache genres) ;
  5. sinon IA (`--ai-*`, avec genres Spotify dans le prompt) ; si l’IA ne
     normalise pas, conserve le premier genre Spotify brut ;
  6. sinon `Unknown`.
- Écrit les tags (`artist`, `title`, `date`, `genre`) puis force `TDRC` via
  `ID3` complet.
- Avec `--tags-only` : s'arrête après les tags (pas de déplacement).
- Sinon : crée `library/<Artist>/` et déplace vers
  `<artist> - <album> - <tracknum 2 chiffres> - <title>.<ext>`.
- Gère les erreurs `EPERM` (CIFS/exFAT) après copie réussie : supprime la
  source et considère le déplacement comme réussi.
- Retour : `{"results": [ {status: success|warning|error, logs, metadata_before,
  final_path, artist, title, genre, genre_source, ...} ]}`.

> Limitations connues : les formats supportés dépendent de `music_tag`
> (MP3/FLAC/M4A/OGG/Opus) ; les noms d'artistes avec `/` sont neutralisés dans
> `sanitize_name` (remplacés par `,`). La couverture des genres Spotify est
> partielle et dépend des artistes.

---

## 11. Flux métier

### 11.1 Vidéo / torrent via Alldebrid

1. L'utilisateur uploade un magnet/`.torrent` ou utilise la recherche locale.
2. `upload_torrent` / `magnet_info` uploade le magnet, interroge le statut
   jusqu'à obtenir les fichiers/liens (max 10 essais, 1 s d'intervalle).
3. Les liens sont débridés (`unlockLink`) puis éventuellement transformés en
   liens de streaming.
4. L'UI affiche les fichiers, permet de choisir chemin/nom (avec option IA),
   puis appelle `/download`.
5. `/download` débride le lien et enqueue un item `video`.
6. Le worker télécharge en HTTP, met à jour la progression et l'historique.

`getRecentLinksGrouped()` sert à reconstituer les « packs » (saisons, albums)
en croisant l'historique Alldebrid et les fichiers des magnets. Le `subpath`
permet de recréer l'arborescence.

### 11.2 Musique

1. `/music-add` : vérifie Spotify, extrait les métadonnées via l'API, écrit un
   log `history_<id>.log`, enqueue avec `expected_tracks`. Pour une playlist,
   `SpotifyUserService` tente d'abord les métadonnées via le token utilisateur
   Zotify (fonctionne aussi pour les playlists privées), puis retombe sur
   `SpotifyService`.
2. Worker : `music_downloader.py` → Zotify télécharge dans `music_root_path`.
3. Vérification `--verify` et calcul succès/warning/erreur + retries.
4. `/music-library` fusionne l'ancien Music Downloads et le navigateur de
   bibliothèque. Deux racines au même niveau : `downloads`
   (`music_root_path`) et `library` (`music_library_path`). Chaque racine est
   affichée si elle est définie, lisible et accessible.
5. Actions UI : édition de tags par fichier ou en masse (liste blanche),
   `Fetch lyrics` et `Fetch genre` sur la sélection courante, lecture audio
   simple (flux `/music/stream` avec `root` + chemin relatif), audit lyrics à
   la demande par dossier (`lyrics_audit.py`, lecture seule), colonne genre sur
   tous les dossiers. `Fetch lyrics` ignore les lyrics déjà synced, mais retente
   les lyrics unsynced avec `--force-dl-unsync` (le fetch unitaire fait de même).
   `lyrics_fetcher.py` remplace l'ancien frame USLT au lieu d'en ajouter un
   second.
6. `Move to library` n'apparaît que sur le dossier racine `downloads`. Il
   réutilise `move-to-library` et déplace dans `music_library_path` avec la
   nomenclature `Artist/Artist - Album - N - Titre.ext`, historise et émet une
   notification.
7. `music_library_path` prime comme racine par défaut ; les chemins sont
   confinés à leur racine respective (realpath + rejet des traversées et des
   liens sortants). Le fetch lyrics unitaire, l'AI genre et `Clear lyrics` sont
   disponibles dans la popup d'édition. Une mise à jour de tags ou un fetch
   lyrics invalide l'audit lyrics du fichier et recalcule les compteurs des
   scopes concernés (cache préfixé par racine). Aucun endpoint de cette section
   ne supprime, déplace ou renomme un fichier en dehors de `Move to library`.

### 11.3 Génération des credentials Spotify

`/config/spotify-auth` :
1. Lance `librespot-auth --name "Speaker <timestamp>"` avec `HOME=var`.
2. Lit `var/credentials.json` généré.
3. Transforme : `auth_type` → `type=AUTHENTICATION_STORED_SPOTIFY_CREDENTIALS`,
   `auth_data` → `credentials`.
4. Écrit `/var/www/html/var/home/.local/credentials.json`.

### 11.4 Mise à jour de la base torrents

`/config/update-hash-db` :
1. Supprime `vendor/gautardos/hash-db`.
2. `composer clear-cache` puis `composer update gautardos/hash-db`.
3. Copie `torrents.db` ou extrait l'archive splittée `torrents.zip.00*` avec
   `cat` + `7z` (nécessite `p7zip-full`).
4. Crée l'index unique `idx_hash_info` et importe `tos_all_hash.txt`
   (format `nom###hash`) dans `torrents` avec `INSERT OR IGNORE`.
5. Écrit dans `var/db/torrents.db`.

> ⚠️ Cette route exécute `composer` au runtime et nécessite un accès réseau +
> des droits d'écriture sur `vendor/`. En image immuable, préférer mettre à jour
> l'image plutôt que d'appeler cette route.

---

## 12. Front-end (Twig)

- Pas de bundler, pas de framework JS : CSS inline dans `base.html.twig`,
  JavaScript inline par template.
- `base.html.twig` fournit :
  - navbar responsive avec burger ;
  - sidebar de notifications + toasts + notifications navigateur ;
  - **tracker de téléchargements** global (`#download-tracker`) ;
  - polling : `queue_status` toutes les 3 s, `progress/{id}`,
    `notifications_poll` toutes les 5 s ;
  - persistance locale des logs (`localStorage.notif_logs`, 100 max) et de
    l'état du tracker.
- Le tracker affiche la tâche active et la file. Pour les tâches musique, pas de
  pourcentage réel : barre « pulsante » et mention des logs.
- `locked.html.twig` est affiché quand la configuration requise manque.
- Les pages métier embarquent leur logique JS (`fetch` vers les routes
  ci-dessus). Les noms de routes Twig (`path('...')`) sont la source de vérité
  pour les URLs.

Conventions UI : textes en anglais, commentaires/quelques messages en français,
emoji comme icônes. Éviter d'introduire une dépendance front sans nécessité.

---

## 13. Docker / déploiement

### Image (`Dockerfile`)
- Base `php:8.3-apache`.
- `www-data` remappé en UID/GID **1000**.
- Extensions : `redis`, `pdo_mysql`, `zip`, `gd`, `intl`, `curl`, `mbstring`.
- Composer installé (`composer:2`), `composer install --no-dev --optimize-autoloader`.
- venv Python `/opt/venv` + `cli/requirements.txt`.
- `librespot-auth` cloné depuis `Gautardos/librespot-auth` et compilé en Rust.
- Apache sert `/var/www/html/public`, écoute **8000**, `mod_rewrite` activé.
- Sessions PHP forcées sur Redis (`zz-redis-sessions.ini`).
- Dossiers runtime créés et appartenant à `www-data`.
- `CMD` = `supervisord`.

### Processus (`supervisord.conf`)
- `redis` (priorité 1), `worker` (5), `apache2` (10).
- Logs : `/var/log/redis*.log`, `/var/log/apache2*.log`,
  `/var/log/supervisord.log` ; les logs worker/automation sont dans
  `var/storage/logs/`.

### Volumes persistants attendus (côté hôte)
- `var/storage` : config, queue, historique, logs applicatifs.
- `var/home` : `HOME` applicatif (credentials Spotify, archive Zotify).
- Les répertoires de téléchargement (`/downloads`, `/music`, bibliothèque)
  doivent être montés dans le conteneur.

> ⚠️ Les chemins saisis dans les réglages sont des chemins **internes au
> conteneur**. Ne pas y mettre des chemins hôte.

### Environnement
- `.env` versionné : `APP_ENV=dev`, `APP_SECRET=...`.
- Pour un déploiement production, définir `APP_ENV=prod` et un secret propre
  (variable d'environnement ou `.env.local`, non versionné).
- `docker-compose.yml` n'est **pas** présent dans le dépôt (le README y fait
  référence). À recréer si nécessaire.

### Commandes utiles
```bash
docker exec -it <container> cat /var/www/html/var/storage/logs/worker.log
docker exec -it <container> supervisorctl restart worker
docker compose pull && docker compose up -d
```

---

## 14. Développement local

### Pré-requis
- PHP 8.1+ avec extensions `ctype`, `iconv`, `zip`, `gd`, `intl`, `mbstring`,
  `curl`, `pdo_sqlite`, `redis`.
- Composer.
- Redis local sur `127.0.0.1:6379` (obligatoire : sessions).
- Python 3 + venv pour les scripts `cli/`.
- `zotify` (fork) et éventuellement `7z`, `ffmpeg`.

### Installation
```bash
composer install
# Démarrer Redis (sinon les sessions échouent)
redis-server --daemonize yes
# Optionnel : environnement Python
python3 -m venv venv
. venv/bin/activate
pip install -r cli/requirements.txt
```

### Lancer l'application
- Serveur web : pointer le document root sur `public/`
  (ex. `php -S 127.0.0.1:8000 -t public`), ou Apache/Nginx.
- Worker en avant-plan pour voir les logs :
  ```bash
  php bin/console app:download-worker
  ```
- En production, le worker est lancé par Supervisor ; en local il peut être
  lancé automatiquement par `QueueManager::triggerWorker()` lors d'un enqueue.

### Vérifications / qualité
La batterie de non-régression est constituée de smoke tests PHP standalone
(sans PHPUnit ni dépendance supplémentaire). Vérifications minimales après
modification :
```bash
find src config public -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;
composer validate --no-check-publish
php bin/console lint:twig templates
php bin/console lint:container
php bin/console debug:router
```
Suites disponibles :
```bash
composer test:auth          # auth/session/logout/idle timeout/password legacy + routes
composer test:security:all  # auth + session_secrets + csrf + ssrf
composer test:automation
composer test:backfill
composer test:jellyfin
composer test:functional    # functional_flow + automation + backfill + jellyfin
composer test:all           # security:all + functional + lint Twig/container
composer test:http-auth     # live optionnel, read-only, BASE_URL=http://127.0.0.1:8000
```
Les tests unitaires/fonctionnels ne font **aucun appel réseau** : TMDB, listes,
addons, streams, downloads et Alldebrid sont remplacés par des fakes. Ils
utilisent un projet temporaire, une DB SQLite temporaire et des racines NAS
factices. Ne jamais pointer ces tests vers le NAS, la DB ou les addons de
production.

Le dossier `tests/` n'est pas bind-mounté dans le conteneur `downloader-dev`
(seuls `src/`, `templates/`, `cli/`, `public/` et `config/` le sont), et
`composer.json` n'est pas monté non plus. Pour exécuter les tests dans Docker,
copier les fichiers ou reconstruire l'image :
```bash
docker cp tests/auth_security_smoke.php downloader-dev:/var/www/html/tests/
docker cp tests/functional_flow_smoke.php downloader-dev:/var/www/html/tests/
docker cp tests/http_auth_smoke.php downloader-dev:/var/www/html/tests/
docker cp composer.json downloader-dev:/var/www/html/composer.json
docker exec downloader-dev composer dump-autoload --optimize --classmap-authoritative
docker exec downloader-dev php bin/console cache:clear
docker exec downloader-dev composer test:auth
docker exec downloader-dev composer test:functional
```
La suite HTTP live (`test:http-auth`) est optionnelle, en lecture seule, sans
identifiants réels et sans suivi de redirection. Elle est ignorée proprement si
`BASE_URL` n'est pas joignable.

Pour les scripts Python :
```bash
python3 -m py_compile cli/*.py
```

---

## 15. Conventions de code et de contribution

- **PSR-4** : namespace `App\` → `src/`.
- Style Symfony standard (autowiring, autoconfiguration dans `services.yaml`).
- Les routes utilisent les attributs `#[Route(...)]` (pas de YAML).
- Les contrôleurs restent fins autant que possible ; la logique métier va dans
  `src/Service`. `DashboardController` concentre beaucoup de logique
  (dette technique assumée).
- Ne pas ajouter de commentaires inutiles ; le code existant est peu commenté.
- Les réponses AJAX sont du JSON `{success, message, ...}`. Conserver ce
  contrat.
- Les scripts CLI doivent écrire un JSON exploitable sur stdout et les logs
  humains sur stderr.
- UI : rester cohérent avec les variables CSS de `base.html.twig`.
- Git : branche `main`, remote `origin`
  (`https://github.com/Gautardos/downloader.git`). Ne pas commiter sans demande
  explicite.

---

## 16. Pièges, dette technique et points de vigilance

### Sécurité
- **CSRF global** : `CsrfSubscriber` protège tous les POST/PUT/PATCH/DELETE
  hors `/jellyfin`, avec le token de session `automation_csrf_token`
  (`_token`, `csrf`, `X-CSRF-Token`). Les formulaires et fetch AJAX injectent
  le token ; Jellyfin reste exempté car il utilise son propre token.
- **Identifiants admin/Jellyfin** : hashés via `PasswordService` à la première
  authentification réussie ; un ancien mot de passe en clair peut subsister
  jusqu'à cette première connexion.
- **Polling authentifié** : `queue_status`, `progress`, `notifications_poll`,
  `queue_active_log` exigent une session ; `notifications_poll` est read-only
  et l'acquittement passe par `notifications_ack` en POST/CSRF.
- **Routes de debug** (`/debug-storage`, `/debug-api`, `/debug-grouping`) et
  **`public/info.php`** exposent des informations sensibles : à supprimer ou
  protéger en production.
- **Injection de commandes** : de nombreuses commandes shell sont construites
  par concaténation (worker musique, tags, move-to-library, spotify-auth,
  update-hash-db). Les valeurs issues de la config ou des noms de fichiers
  peuvent contenir `"`, `$`, `;`, etc. Utiliser `escapeshellarg` partout ou
  passer par `Process` avec un tableau d'arguments. C'est un chantier prioritaire
  si l'application est exposée au-delà d'un réseau de confiance.
- Les liens débridés et les clés API transitent par des requêtes serveur ;
  ne jamais logguer de secrets.

### Concurrence / intégrité
- `JsonStorage` : read-modify-write non atomique (voir §5).
- Plusieurs sources écrivent `server_notifications` (worker + contrôleurs) :
  risque de perte d'entrées en cas d'écritures simultanées.
- `triggerWorker()` combine verrou + heartbeat ; course possible entre le
  relâchement du verrou et le démarrage du worker, atténuée par le heartbeat.
- `getQueue()` relance le worker à chaque appel (y compris depuis l'UI).

### Chemins et environnement
- Chemins `/var/www/html/...` codés en dur dans plusieurs endroits
  (`music_creds`, archive, credentials, `config_spotify_auth`) : à adapter si
  le `WORKDIR` change.
- `music_venv_path` est interprété de façons différentes selon le contrôleur
  (absolu/relatif/fallback). Centraliser serait utile.
- Le worker utilise `python3` et suppose le venv activé ; en local sans venv,
  les tâches musique échouent.
- `music_downloader.py` importe `music_tag` sans l'avoir déclaré dans
  `requirements.txt`.
- `tag_rename_move.py` traite les formats gérés par `music_tag` ; la couverture
  des genres Spotify est partielle (certains artistes n’ont aucun genre).

### Front / UX
- CSS/JS inline volumineux (surtout `base.html.twig` et
  `dashboard/index.html.twig`) : toute modification impacte plusieurs pages.
- Pas de build d'assets : les fichiers statiques sont servis directement depuis
  `public/`.
- Notifications côté client persistées en `localStorage` (pas de synchronisation
  multi-navigateurs), complétées par les notifications serveur éphémères
  (vidées au poll).

### Divers
- `APP_ENV=dev` par défaut dans l'image si la variable n'est pas surchargée :
  penser à `APP_ENV=prod` et `APP_DEBUG=0` en production.
- Le paquet `gautardos/hash-db` est en `dev-master` : builds non reproductibles
  par nature.
- La route de mise à jour de la base exécute `composer` au runtime (réseau +
  écriture `vendor/`) ; non adaptée à un conteneur immuable.
- Aucun test automatisé : privilégier des vérifications manuelles ciblées après
  chaque changement (routes, worker, scripts Python).

---

## 17. Recettes pour les tâches courantes

### Ajouter un réglage
1. Ajouter le champ dans `templates/config/index.html.twig`.
2. Lire/écrire la clé dans `ConfigController::index` (attention : la sauvegarde
   réécrit toutes les clés).
3. Ajouter un helper dans `JsonStorage` si le réglage conditionne l'affichage
   d'une page.
4. Documenter dans le tableau §7.

### Ajouter une route
1. Méthode publique dans un contrôleur avec `#[Route]`.
2. Respecter l'auth (ou l'ajouter à `STATELESS_ROUTES` **uniquement** pour du
   polling sans données sensibles).
3. Ajouter le lien/nav si nécessaire dans `base.html.twig`.
4. Mettre à jour le tableau §8.

### Ajouter un type de média
1. Étendre `MediaTypeHelper::EXTENSIONS` et `ICONS` (+ `getLabel`).
2. Mettre à jour les maps d'icônes JS dans `base.html.twig` et les pages
   concernées.

### Faire évoluer le pipeline musique
- Le contrat Python↔PHP est du **JSON sur stdout** : ne rien imprimer d'autre.
- Toute nouvelle option doit être ajoutée à la fois dans
  `DownloadWorkerCommand` (ou le contrôleur concerné) et dans `argparse`.
- Après modification de `processMusicTask`, tester : tâche réussie, partielle
  (`warning`), totale (`error` + retry), `expected_tracks` vide.

### Modifier le worker
- Toujours conserver le heartbeat régulier, sinon `getActiveTask()` considère la
  tâche comme fantôme (seuil 120 s hors musique).
- Conserver le mutex `worker.lock` sous peine de workers multiples.
- Conserver le contrat d'historique (`QueueManager::recordHistory`).

---

## 18. Résumé des fichiers les plus critiques

| Fichier | Pourquoi c'est critique |
| --- | --- |
| `src/Controller/DashboardController.php` | Cœur fonctionnel (1248 lignes) : torrents, musique, queue, logs, DB |
| `src/Command/DownloadWorkerCommand.php` | Exécution asynchrone, retries, vérification musique |
| `src/Service/QueueManager.php` | Persistance de la file + lancement worker + historique |
| `src/Service/JsonStorage.php` | Toute la persistance applicative |
| `src/Service/AlldebridService.php` | Toute l'intégration Alldebrid + groupement des packs |
| `src/Service/DownloadManager.php` | Téléchargement HTTP + progression |
| `cli/spotify_user.py` | Liste playlists privées/publiques et métadonnées via token librespot |
| `cli/music_downloader.py` | Contrat entre PHP et Zotify (JSON/verify/tags) |
| `cli/tag_rename_move.py` | Tags, genres, bibliothèque |
| `templates/base.html.twig` | Layout, polling, tracker, notifications |
| `Dockerfile` + `supervisord.conf` | Runtime complet de l'image |

---

## 19. Automations (Lot 0/1 — index NAS)

Cette section décrit le socle de la synchronisation automatique de listes
externes (Scrob / MDBList) vers le NAS. L'ingestion des listes, le matching
dry-run avec l'index et la résolution/téléchargement automatique des **films**
sont branchés. La résolution et le téléchargement automatique des **épisodes
de séries** (backfill par épisode, packs refusés, multi-épisodes parqués) sont
également disponibles.

### Stockage

- `var/db/automation.db` : base SQLite dédiée, créée par
  `App\Service\Automation\AutomationStore` (WAL, `busy_timeout` 15 s,
  `synchronous=NORMAL`, `PRAGMA user_version` et ajout de colonnes idempotent).
- Tables actuelles : `settings`, `scan_runs`, `resolution_runs`,
  `rename_runs`, `metadata_cache`, `library_entries`, `list_runs`,
  `list_items`, `intents`, `intent_runs`, `series_numbering`.
- Les écritures SQLite passent par `withRetry()` (SQLITE_BUSY/LOCKED, backoff
  exponentiel) pour éviter les warnings `database is locked` pendant un scan
  concurrent avec une résolution.
- `var/storage/automation_worker.lock` / `automation_scan.lock` : mutex.
- `var/storage/logs/automation_worker.log` : log du worker (rotation 5 Mo / 5 sauvegardes via `LogRotator`).
- `var/storage/logs/automation_worker_error.log` : stderr du worker (rotation 5 Mo / 5 sauvegardes via Supervisor).
- Les réglages sont stockés dans `settings` sous la clé
  `automation_settings` (JSON), pas dans `config.json`.

### Services et commandes

| Élément | Rôle |
| --- | --- |
| `src/Service/Automation/AutomationStore.php` | Accès SQLite, settings, scans, index |
| `src/Service/Automation/AutomationSettings.php` | Defaults et fusion des réglages |
| `src/Service/Automation/LibraryNameParser.php` | Parsing films/séries/anime + NFO |
| `src/Service/Automation/LibraryIndexService.php` | Scan incrémental des racines, lock, stats |
| `src/Service/Automation/LibraryMetadataResolver.php` | Résolution TMDB, cache, mise à jour des clés |
| `src/Service/Automation/LibraryRenameService.php` | Preview/apply du renommage Jellyfin |
| `src/Controller/AutomationLibraryController.php` | Page Library index + endpoints renommage |
| `src/Command/LibraryScanCommand.php` | `app:library-scan [--root=movies]` |
| `src/Command/LibraryReconcileMissingCommand.php` | `app:library-reconcile-missing --root=anime [--dry-run]` |
| `src/Command/LibraryResolveCommand.php` | `app:library-resolve [--limit=50]` |
| `src/Service/Automation/LibraryTitleRefreshService.php` | Rafraîchit les titres films et séries TMDB dans `metadata.title_language` |
| `src/Command/LibraryTitleRefreshCommand.php` | `app:library-title-refresh [--limit=] [--language=] [--dry-run]` |
| `src/Command/LibraryPruneEmptyDirsCommand.php` | `app:library-prune-empty-dirs [--root=] [--dry-run]` |
| `src/Command/AutomationWorkerCommand.php` | `app:automation-worker [--once] [--force-scan]` |
| `src/Service/Automation/ScrobListClient.php` | Client API Scrob (`/api/proxy/lists/{id}`) |
| `src/Service/Automation/MdbListClient.php` | Client API MDBList (pagination `unified=true`) |
| `src/Service/Automation/ListSyncService.php` | Ingestion, dry-run, scheduling, lock |
| `src/Service/Automation/ListMatcher.php` | Matching films/séries avec l'index NAS |
| `src/Command/ListsSyncCommand.php` | `app:lists-sync [--source=scrob] [--scheduled]` |
| `src/Controller/AutomationListsController.php` | Page Lists, configuration, dry-run, ignore |
| `src/Service/Automation/ReleaseMetadataParser.php` | Parsing résolution/codec/HDR/audio/groupe/taille/seeders |
| `src/Service/Automation/ReleasePreferenceService.php` | Filtres durs + score pondéré + explication lisible |
| `src/Service/Automation/ReleaseResolverService.php` | Identité film/série, streams, sélection, enqueue Alldebrid |
| `src/Service/Automation/ReleaseIntentService.php` | Intents, déduplication, plafond/queue guard, lifecycle |
| `src/Service/Automation/MediaStreamProviderAdapter.php` | Adaptateur `MediaAddonService` → provider de streams |
| `src/Service/Automation/MediaDownloaderAdapter.php` | Adaptateur `MediaDownloadService` → downloader |
| `src/Service/Automation/TmdbMovieIdentityResolver.php` | IMDb/TMDB → identité film canonique |
| `src/Service/Automation/TmdbSeriesIdentityResolver.php` | IMDb/TMDB → identité série canonique |
| `src/Service/Automation/TmdbEpisodeCatalog.php` | Liste ordonnée des épisodes TMDB (titres, dates) |
| `src/Service/Automation/EpisodeNumberingService.php` | Mapping TMDB ↔ numérotation d'acquisition (TVDB/Cinemeta/TVmaze/local) |
| `src/Service/Automation/AiometadataSeriesNumberingProvider.php` | Récupère les `videos` via AIOMetadata |
| `src/Service/Automation/CinemetaSeriesNumberingProvider.php` | Fallback Cinemeta |
| `src/Service/Automation/TvmazeSeriesNumberingProvider.php` | Fallback TVmaze |
| `src/Service/Automation/FallbackSeriesNumberingProvider.php` | Chaîne AIOMetadata → Cinemeta → TVmaze |
| `src/Command/SeriesNumberingCommand.php` | `app:series-numbering [--tmdb=] [--imdb=] [--refresh] [--limit=]` |
| `src/Service/Automation/AutomationNotifier.php` | Notifications in-app groupées |
| `src/Command/ReleaseIntentsCommand.php` | `app:release-intents [--queue] [--episodes] [--run] [--auto] [--max=]` |
| `src/Command/ReleaseIntentReconcileCommand.php` | `app:release-intents-reconcile [--dry-run]` |
| `src/Service/Automation/ReleaseDryRunService.php` | Preview des releases/sources sans queue ni download |
| `src/Command/ReleaseDryRunCommand.php` | `app:release-dry-run [--limit=] [--source=]` |
| `src/Controller/AutomationDownloadsController.php` | Page Downloads : intents, runs, actions |
| `src/Controller/AutomationConfigurationController.php` | Page Configuration : roots/scanner, qualité, limites |
| `src/Controller/AutomationController.php` | Page, scan, status, settings |

Supervisor lance `automation-worker` en plus du worker de téléchargement.
Le worker vérifie le heartbeat et déclenche les scans planifiés.

### Routes

| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/automation` | `automation` | Page Automations |
| POST | `/automation/scan` | `automation_scan` | Lance un scan en arrière-plan |
| POST | `/automation/resolve` | `automation_resolve` | Demande la résolution TMDB |
| POST | `/automation/attention/search` | `automation_attention_search` | Recherche TMDB pour un groupe ambigu |
| POST | `/automation/attention/resolve` | `automation_attention_resolve` | Applique un choix TMDB manuel |
| POST | `/automation/attention/ignore` | `automation_attention_ignore` | Ignore ou restaure un groupe |
| GET | `/automation/library` | `automation_library` | Index filtrable : Flat / Folders / Discovery |
| POST | `/automation/library/folder/entries` | `automation_library_folder_entries` | Charge les fichiers d’un dossier (AJAX) |
| POST | `/automation/library/folder/contents` | `automation_library_folder_contents` | Arbre : sous-dossiers + fichiers + breadcrumb |
| POST | `/automation/library/element` | `automation_library_element` | Fiche TMDB light + fichiers d’un élément |
| POST | `/automation/library/match/search` | `automation_library_match_search` | Recherche TMDB manuelle (Fix match) |
| POST | `/automation/library/match/details` | `automation_library_match_details` | Détails du match TMDB courant |
| POST | `/automation/library/rename/preview` | `automation_library_rename_preview` | Preview du renommage en masse |
| POST | `/automation/library/rename/apply` | `automation_library_rename_apply` | Applique le renommage |
| POST | `/automation/library/title-refresh` | `automation_library_title_refresh` | Rafraîchit les titres films en anglais |
| GET | `/automation/library/title-refresh/status` | `automation_library_title_refresh_status` | Statut JSON du refresh des titres |
| POST | `/automation/config/metadata` | `automation_config_metadata` | Sauvegarde la langue des titres |
| GET | `/automation/activity` | `automation_activity` | Historique scans/résolutions et état courant |
| GET | `/automation/status` | `automation_status` | Statut JSON (worker, scan, résolution, stats) |
| POST | `/automation/settings` | `automation_settings` | Sauvegarde racines et scanner |
| GET | `/automation/lists` | `automation_lists` | Sources Scrob/MDBList + résultats du dry-run |
| POST | `/automation/lists/sync` | `automation_lists_sync` | Lance un dry-run en arrière-plan |
| POST | `/automation/lists/settings` | `automation_lists_settings` | Sauvegarde URL, clés et listes |
| POST | `/automation/lists/test` | `automation_lists_test` | Teste la connexion et liste les listes disponibles |
| POST | `/automation/lists/ignore` | `automation_lists_ignore` | Ignore/restaure un item de liste |
| POST | `/automation/lists/queue-episodes` | `automation_lists_queue_episodes` | Crée les intents épisodes des sources validées |
| POST | `/automation/lists/numbering` | `automation_lists_numbering` | Détecte/rafraîchit la numérotation d'une série |
| GET | `/automation/lists/status` | `automation_lists_status` | Statut JSON du dry-run |
| GET | `/automation/downloads` | `automation_downloads` | Intents films et épisodes, profil qualité, limites |
| GET | `/automation/config` | `automation_config` | Configuration globale de l’automation |
| POST | `/automation/downloads/quality` | `automation_downloads_quality` | Sauvegarde du profil qualité (redirige vers Configuration) |
| POST | `/automation/downloads/settings` | `automation_downloads_settings` | Sauvegarde plafonds/retries/queue guard (redirige vers Configuration) |
| POST | `/automation/downloads/queue` | `automation_downloads_queue` | Crée les intents des films missing |
| POST | `/automation/downloads/queue-episodes` | `automation_downloads_queue_episodes` | Crée les intents des épisodes missing |
| POST | `/automation/downloads/run` | `automation_downloads_run` | Lance `app:release-intents --run` |
| POST | `/automation/downloads/dry-run` | `automation_downloads_dry_run` | Lance un preview des releases sans téléchargement |
| GET | `/automation/downloads/dry-run/status` | `automation_downloads_dry_run_status` | Statut JSON du dry-run de releases |
| POST | `/automation/downloads/intent/sources` | `automation_downloads_intent_sources` | Liste les sources/addons pour un intent |
| POST | `/automation/downloads/intent/download` | `automation_downloads_intent_download` | Queue manuellement une source choisie |
| POST | `/automation/downloads/intent/retry` | `automation_downloads_intent_retry` | Réessaie un intent |
| POST | `/automation/downloads/intent/ignore` | `automation_downloads_intent_ignore` | Ignore/restaure un intent |
| POST | `/automation/downloads/intent/restore-ignored` | `automation_downloads_intent_restore_ignored` | Restaure tous les intents ignorés en `pending` |
| POST | `/automation/downloads/intent/delete` | `automation_downloads_intent_delete` | Supprime un intent ignoré |
| GET | `/automation/downloads/status` | `automation_downloads_status` | Statut JSON des intents |

`automation_status` et `automation_lists_status` sont dans
`SessionIdleSubscriber::READ_ONLY_ROUTES` pour éviter le lock de session
pendant le polling.

### Organisation de l'UI automation

- **Overview** : tableau de bord en lecture seule (santé worker, alertes, KPI,
  résumés Lists / Media Library / Downloads, activité en cours).
- **Lists** : sources Scrob/MDBList, credentials, auto-download, dry-run.
- **Media Library** : index, Folders/Discovery, renommage, Needs attention,
  Ignored et Metadata resolution.
- **Downloads** : intents films, erreurs, queue et historique des runs.
- **Activity** : historique détaillé (scans, résolutions, listes, intents,
  renommages).
- **Configuration** : roots & scanner, profil qualité, limites d'intents. Les
  sources et leurs credentials restent sur Lists.

### Listes Scrob / MDBList (dry-run)

- Les sources sont stockées dans `settings` sous `sources` (une entrée Scrob,
  une entrée MDBList). Chaque source accepte plusieurs listes via `list_ids`,
  sélectionnées dans un multi-select. La page Lists teste automatiquement la
  connexion de chaque source configurée (indicateur Connected / Connection
  error / Not configured) et recharge les listes disponibles ; le résultat est
  mémorisé dans `config.available_lists` pour afficher les noms au prochain
  chargement. Chaque carte source est repliée par défaut et peut être dépliée ;
  l'état est mémorisé dans `localStorage`. En mode replié, l'en-tête affiche
  l'état Active/Disabled et le nombre de listes configurées.
- Scrob : `base_url` (ex. `http://scrob:7330`), `api_prefix` par défaut
  `/api/proxy`, `api_key`, `list_ids` numériques. Le client appelle
  `GET {base_url}{api_prefix}/lists/{id}` avec `X-Api-Key`/`api_key`.
- MDBList : `api_key`, `list_ids` numériques ou `username/slug` ; le client
  utilise `GET https://api.mdblist.com/lists/{ref}/items?unified=true` avec
  pagination par curseur (`X-Next-Cursor` / `next_cursor`).
- Tables ajoutées : `list_runs` (historique par source) et `list_items`
  (items normalisés, statut de matching, compteurs d'épisodes).
- Le matching est **dry-run uniquement** : aucun téléchargement, aucune
  intention de téléchargement, aucun ajout à la file.
- Films : présence via `tmdb_id`/`imdb_id`, sinon fallback titre + année et
  `confidence >= 0.8`.
- Séries : les saisons/épisodes déjà diffusés sont énumérés via TMDB
  (`details` + `season`). Les épisodes futurs sont exclus (sauf
  `lists.include_unaired`). Les spéciaux suivent `backfill.exclude_specials`.
  Une série est `present` si tous les épisodes diffusés sont dans l'index,
  `missing` sinon, avec `episodes_present` / `episodes_missing` et la liste
  des épisodes manquants.
- Statuts : `present`, `missing`, `ambiguous`, `ignored` (+ `pending` avant
  matching). Ambigu = identité non résolue, titre en collision sur plusieurs
  dossiers, ou liste d'épisodes TMDB indisponible. Ignoré = `ignored_at` sur
  le groupe de l'index ou sur l'item de liste.
- Les items absents d'un fetch réussi sont marqués `removed` (conservés en
  base). Si le fetch retourne un warning ou zéro item, aucune suppression
  n'est faite : les items précédents sont préservés.
- Le worker automation lance les sources dues selon `schedule.scrob` et
  `schedule.mdblist` ; le bouton de la page Lists lance `app:lists-sync` en
  arrière-plan. Verrou dédié : `automation_lists.lock`.
- Pendant un dry-run, `list_runs` est mis à jour en cours de route (items
  récupérés, présent/manquant/ambigu/ignoré). La page Lists affiche la
  progression, et l'onglet Activity affiche les list runs récents. Un point
  clignotant apparaît à côté de l'onglet Activity quand un scan, une résolution
  ou un dry-run de listes est en cours ou en attente.
- Si un dry-run reste `requested` sans verrou actif, la page Lists l'indique
  comme non démarré après 60 s et invite à vérifier/redémarrer le worker.
- Le cache des listes d'épisodes TMDB est journalier (clé avec la date) et
  stocké dans `metadata_cache`; budget de lookups via
  `lists.max_show_lookups_per_run`.

### Résolution et téléchargement des films et séries

- Films : activables par source via `config.auto_download` (case “Auto download
  missing movies”). Épisodes : activables séparément via
  `config.auto_download_series` (défaut `false`, case “Auto download series
  episodes”). Un intent ne peut être créé que pour un item `list_items`
  `match_status = missing`, non ignoré, après un dry-run terminé avec au moins
  un item. Les deux flags sont indépendants.
- Films : `queueMissingMovies()` applique le même queue guard et le même
  plafond `backfill.max_intents_per_run` que les épisodes, avec un warning et
  un arrêt propre lorsque le plafond est atteint.
- `missing_json` conserve la liste canonique complète des épisodes manquants,
  bornée à 5000 entrées. L'UI n'affiche que les 50 premières et la queue les
  traite par lots selon `backfill.max_intents_per_run`.
- Épisodes : `queueMissingEpisodes()` lit `missing_json` (liste de `SxxExx`
  déjà diffusés), ignore les spéciaux si `backfill.exclude_specials`, vérifie
  les entrées `library_entries` actives/ignorées de la même série et les
  intents existants, puis crée un intent par épisode avec
  `media_key = series:tmdb:<id>:sXXeYY` et les colonnes `season`/`episode`.
  Le plafond `backfill.max_intents_per_run` et le queue guard s'appliquent.
- Tables : `intents` (identité, saison/épisode, statut, tentatives, prochaine
  tentative, download, hash, file index, release choisie, score, explication,
  erreur) et `intent_runs` (historique des runs). Index dédiés par type/statut
  et par `(tmdb_id, season, episode)`.
- Tables : `intents` (identité, statut, tentatives, prochaine tentative,
  download, hash, file index, release choisie, score, explication, erreur) et
  `intent_runs` (historique des runs).
- Statuts d'intent : `pending`, `resolving`, `queued`, `downloading`, `success`,
  `error`, `no_source`, `ignored`. Les statuts `pending`, `no_source` et `error`
  sont repris quand `next_attempt_at` est dû et que le plafond de tentatives
  n'est pas atteint. Le worker récupère aussi les intents `queued`/`downloading`
  stale (> 2 h, download absent de la queue, de la tâche active et de
  l'historique) en les remettant `pending` avec backoff.
- Déduplication : `library_entries` actif (avec `ignored_at` pour les épisodes),
  intent existant pour le même `media_key`/TMDB/IMDb (et saison/épisode pour
  une série), item déjà présent dans `server_queue`/`active_worker_task` (par
  `automation_intent_id`/`automation_media_key`, ou `media_title` pour les
  films), puis `next_attempt_at` pour les échecs récents. Après succès,
  l'intent reste `success` et une indexation ciblée de la racine films ou
  séries est demandée. Si un intent `success` n'a plus d'entrée library active
  correspondante (fichier supprimé ou déplacé), il est **réactivé** sur place
  (`pending`, attempts 0, download nettoyé) au lieu de créer un doublon.
- Profil qualité stocké sous `settings.quality_profile` : résolution min/max et
  ordre, codecs autorisés, HDR (`ignore`/`prefer`/`require`), langues audio,
  seeders minimum, **taille max film** (`max_movie_size_gb`), **taille max
  épisode** (`max_episode_size_gb`), tags exclus, priorité addon. L'ancienne clé
  `max_size_gb` reste un alias de rétrocompatibilité pour les films.
- Filtres durs : une release avec résolution, codec, langue, seeders ou taille
  non vérifiable est refusée. Aucun fallback hors profil n'est téléchargé.
  Le score combine résolution, codec, HDR, audio, seeders, adéquation de taille
  et priorité addon.
- `ReleaseResolverService` résout l'identité film ou série (IMDb direct, sinon
  TMDB `details`/`findByImdb`/recherche titre+année via
  `TmdbMovieIdentityResolver` ou `TmdbSeriesIdentityResolver`). Films :
  `MediaAddonService::getStreams('movie', 'tt...')`. Séries :
  `getStreams('series', 'tt...:<season>:<episode>')`, scoring avec
  `max_episode_size_gb`. Les candidats multi-épisodes sont refusés. Le fichier
  est écrit directement dans la convention Jellyfin :
  - films `<root>/<Title> (<Year>)/<Title> (<Year>).<ext>` ;
  - épisodes `<root>/<Series> (<Year>)/Season XX/<Series> (<Year>) - SxxExx.<ext>`
    (racine `series` ou `anime`, jamais de titre d'épisode).
  Un scan ultérieur les voit donc déjà `Conform`.
- Les métadonnées `automation_intent_id`, `automation_media_key`, `automation`,
  `season` et `episode` sont propagées dans l'item de queue. Le worker de
  téléchargement passe l'intent en `downloading`, puis `success` ou `error`
  (avec backoff). La sélection du fichier est déterministe : pour un épisode,
  recherche exacte `SxxExx` (hors multi-épisodes) ; un `file_idx` n'est
  prioritaire que s'il correspond à l'index torrent d'origine (`torrent_index`),
  jamais à l'index réindexé après filtrage. Sans fichier exact, le pack est
  refusé. Pour un film, `file_idx` fiable puis nom de fichier demandé ; un pack
  ambigu sans preuve n'est jamais remplacé par son plus gros fichier.
- `intent_runs` est mis à jour en cours de run. Un `queue guard`
  (`backfill.queue_guard`, défaut 20) suspend le traitement si la file contient
  déjà trop de téléchargements et efface la demande de run pour éviter un
  bandeau bloqué. Plafond par run : `backfill.max_intents_per_run` (défaut 50,
  éditable). La page Downloads suit le run par polling et ne se recharge
  qu'à la fin, pas périodiquement.
- Le worker automation crée les intents auto films et épisodes (throttle
  300 s), réinitialise les intents `resolving` bloqués, puis traite les intents
  dus après les scans et les dry-runs. Commande manuelle :
  `app:release-intents [--queue] [--episodes] [--run]`.
- Notifications in-app groupées par run/fenêtre : résumé succès/erreurs des
  téléchargements, no source, échec
  Alldebrid/téléchargement, disque plein, téléchargement interrompu.
- Chaque intent expose une action **Sources** : dialogue avec fiche TMDB et
  tous les streams addons évalués (acceptés et filtrés). L’utilisateur peut
  queue manuellement une autre source ; ce choix contourne volontairement le
  profil qualité et met à jour l’intent avec le nouveau `download_id`.

### Réconciliation des intents avec les listes

- Les listes synchronisées représentent l'état désiré ; les intents représentent
  l'exécution. Après un dry-run de listes réussi, le worker et
  `app:lists-sync` lancent une réconciliation globale des intents.
- L'état désiré est l'union des `list_items` actifs, non ignorés,
  `match_status = missing`, des sources activées et des `list_ids` sélectionnés.
  Pour une série, chaque code de `missing_json` est pris en compte.
- Sont supprimés uniquement les intents non désirés aux statuts `pending`,
  `no_source`, `error` et `ignored`. Un `ignored` n'est supprimé que si plus
  aucune liste active ne demande ce média.
- Les statuts `success`, `resolving`, `queued` et `downloading` sont toujours
  conservés. Les `success` restent en historique même si leur liste disparaît.
- La comparaison se fait via une clé normalisée TMDB/IMDb/titre + saison/épisode,
  afin de reconnaître les anciens `media_key`.
- Aucune source activée : la réconciliation est ignorée pour éviter une purge
  accidentelle.
- Prévisualisation et réparation manuelle :
  `php bin/console app:release-intents-reconcile --dry-run` puis sans option.
- La désynchronisation d'une liste ne supprime rien à la sauvegarde : c'est le
  prochain dry-run de listes réussi qui marque les items `removed` puis
  réconcilie les intents.

### Numérotation des séries (backfill)

- Le découpage d'acquisition peut différer de TMDB. Exemple : TMDB expose
  `Rurouni Kenshin 2023` en `1×47`, tandis que TVDB/Cinemeta/TVmaze et les
  releases utilisent `S01 24 + S02 23`.
- `series_numbering` stocke par série : `tmdb_id`, `imdb_id`, `source`,
  `source_ref`, `segments_json`, `mapping_json`, confiance, statut, snapshot
  TMDB, `target_mode` (`local`/`tmdb`) et `jellyfin_mode` (`local`/`tmdb`).
- `EpisodeNumberingService` aligne les épisodes TMDB et les `videos`
  d'AIOMetadata par titre normalisé + date à ±3 jours, avec fallback ordinal
  uniquement si les deux listes sont complètes. Les fichiers locaux servent de
  validation, jamais de base de calcul.
- Providers : AIOMetadata en priorité (réutilise la config Nuvio/TVDB), puis
  Cinemeta et TVmaze. Aucun ne nécessite de clé API. Les réponses sont mises en
  cache dans `metadata_cache`.
- Si le mapping est absent ou ambigu : la série passe en `ambiguous`, aucun
  intent épisode n'est créé automatiquement, et l'UI propose une re-détection.
- Un mapping de confiance < 0,75 (souvent un alignement ordinal) est affiché
  `Review` et doit être confirmé via **Confirm mapping** avant d'être utilisé
  par le matching, la queue ou le resolver. La confirmation passe la source en
  `manual`.
- `ListMatcher` utilise le mapping pour compter un fichier local `S02E01` comme
  l'épisode TMDB `S01E25` ; `missing_json` reste canonique TMDB.
- Les intents stockent `season`/`episode` canoniques **et**
  `release_season`/`release_episode` pour les addons et le nommage. Le resolver
  interroge `tt...:<release_season>:<release_episode>`.
- `target_mode=local` écrit `<Series> (<Year>) - SxxExx.ext` avec la
  numérotation d'acquisition ; `target_mode=tmdb` aplatit sur TMDB.
- La passerelle Jellyfin peut exposer les épisodes en mode `local` ou `tmdb`
  sans déplacer les fichiers : l'ID reste `ep_<entry_id>` et le stream pointe
  toujours vers le fichier local.
- Commande utile après un changement de provider : `app:series-numbering
  --refresh` (ou `--tmdb=<id>` pour une seule série). L'UI Connected Lists
  expose aussi **Detect numbering** / **Re-detect** et le mode Jellyfin.

### Dry-run de sélection des releases

- Bouton **Dry-run automation** sur Downloads : parcourt les films `missing` des
  sources dont le dry-run de listes est validé, résout l'identité TMDB/IMDb,
  interroge les addons, applique le profil qualité et enregistre les candidats.
- Aucun appel à Alldebrid, aucun intent, aucun ajout à la queue : le service
  `ReleaseDryRunService` n'utilise pas `MediaDownloadService`.
- Résultats stockés dans `var/storage/automation_release_dry_run.json` :
  film, source/liste, identité, release sélectionnée, top candidats acceptés,
  candidats filtrés avec raisons, nombre de streams.
- Exécution en arrière-plan via `app:release-dry-run` (verrou
  `automation_release_dry_run.lock`), statut consultable par polling sur
  `automation_downloads_dry_run_status`. Le state contient la progression
  (`processed/total`), le film courant et les derniers événements ; le log est
  écrit dans `var/storage/automation_release_dry_run.log`. Une demande bloquée
  en `requested` depuis plus de 60 s est considérée comme stale et peut être
  relancée.
- Le dry-run affiche l'état de cache uniquement s'il est connu : `Cached`,
  `Uncached`, sinon `Cache status unknown` (ou `Direct` pour un stream direct).
  Un torrent non caché est envoyé à Alldebrid qui le met en cache à la demande :
  l'attente est portée à 60 s (au lieu de 15 s pour un candidat annoncé cached),
  puis l'intent repasse en erreur avec retry différé si le torrent n'est pas prêt.
- `DownloadManager` rejette les téléchargements manifestement invalides : page
  HTML, fichier < 1 Mo, contenu plus court que `Content-Length`, ou taille
  inférieure à 90 % de la taille attendue. Le fichier partiel est supprimé et
  l'intent passe en erreur/retry au lieu d'être marqué `success`. Le message
  d'erreur ajoute un diagnostic redacted (`host`, `content-type`, `bytes`,
  `head_hex`) sans URL signée. Les petits payloads récurrents observés côté
  Alldebrid/CDN restent traités comme des erreurs d'intégrité : les seuils ne
  sont pas affaiblis.
- `app:release-verify-downloads [--apply] [--quarantine]` vérifie les intents
  `success` contre la taille **exacte** enregistrée dans l'historique
  (`actual_size`, issue des octets réellement téléchargés). Il n'utilise jamais
  `release_explanation` ni la taille d'un pack. Pour les anciennes entrées sans
  `actual_size`, seuls les contrôles durs (fichier absent, HTML, < 1 Mo) sont
  appliqués. `--apply` exige `--quarantine` : le fichier invalide est renommé en
  `.invalid-<date>` (aucune suppression), l'intent repasse en `pending` et
  l'entrée d'index est marquée `missing`.

### Taille des téléchargements automation

- `QueueSizeResolver` enrichit les items de `queue-status` avec une taille
  estimée quand `size` est absent : l'intent lié fournit la taille dans
  `release_explanation` (ex. « 8.5 Go »). Cela permet au tracker de calculer un
  pourcentage même pour les anciens items de queue.
- Les nouveaux téléchargements stockent aussi la taille exacte Alldebrid dans
  l'item de queue ; `DownloadManager` utilise en plus `Content-Length` quand il
  est présent. Si aucune taille n'est connue, le tracker affiche une barre
  indéterminée et les octets téléchargés.

### Langue des titres et rattrapage

- `settings.metadata.title_language` (défaut `en-US`) est utilisé par
  `TmdbMovieIdentityResolver` pour les nouveaux téléchargements et par
  `LibraryTitleRefreshService` pour les entrées existantes.
- La résolution continue de chercher avec la langue TMDB configurée, mais les
  détails finaux (titre/année) sont récupérés dans `title_language`.
- Bouton **Refresh titles** sur Media Library ou commande
  `app:library-title-refresh` : met à jour `library_entries.title` pour les
  films et les séries ayant un `tmdb_id`. Pour les séries, `show_folder` est
  également aligné sur `Titre (Année)`. `--dry-run` permet de prévisualiser.
- Le refresh ne déplace pas les fichiers : utiliser ensuite **Rename matched**
  pour prévisualiser/appliquer le renommage Jellyfin avec les titres anglais.
- Les entrées sans `tmdb_id` doivent d'abord passer par Resolve metadata.
- Après un renommage, **Clean empty folders** ou
  `app:library-prune-empty-dirs [--root=movies] [--dry-run]` supprime les
  dossiers vides laissés derrière, sans toucher aux fichiers, aux racines, aux
  dossiers non vides ni aux dossiers cachés.

### Racines et parsing

- Défauts : `/nas/Video/Movies`, `/nas/Video/TV Shows`,
  `/nas/Video/Anime` (Anime désactivé par défaut).
- Films : titre + année, avec fallback NFO.
- Séries/anime : dossier série + `Season XX` + `SxxExx`, fallback
  numérotation d'épisode seule.
- NFO : `movie.nfo`, `<fichier>.nfo`, `tvshow.nfo`, `season.nfo` ;
  extraction de `tmdbid`, `imdbid`, `uniqueid`, titre, année, saison,
  épisode.
- Les entrées peu fiables (`confidence < 0.8`, épisode manquant) sont
  comptées dans “Needs attention” et ne doivent pas servir à la déduplication
  automatique tant qu'elles ne sont pas résolues.
- Les entrées ambiguës sont regroupées par dossier de série (`show_folder`)
  ou par film (`title_normalized + year`). La page “Needs attention” permet
  de résoudre un groupe via TMDB ou de l’ignorer.
- `ignored_at` masque un groupe de la résolution automatique et de la
  déduplication ; l’action Restore le réactive. La résolution manuelle d’un
  groupe ignoré le restaure automatiquement.
- La page `/automation/activity` affiche l’état du worker, le scan courant,
  la résolution demandée, les 12 derniers `scan_runs` et les 12 derniers
  `resolution_runs`. Un lien “Activity” est présent dans l’en-tête de la page
  Automations.
- Les résolutions sont historisées dans `resolution_runs` ; `last_resolution`
  reste aussi stocké dans `settings` pour l’affichage rapide.
- Le bouton Resolve metadata lance directement `app:library-resolve` (avec son
  propre verrou), pas un one-shot du worker. Cela évite de rester bloqué si le
  worker long-running a été démarré avant une mise à jour du code. Le resolver
  remet `library_resolve_requested` à `false` dans son `finally`.
- Après une mise à jour du code en dev avec bind mount, redémarrer le worker
  d’automation pour qu’il charge les nouvelles classes/méthodes.
- La page `/automation/library` affiche tout l’index avec filtres
  `all/matched/unmatched/ignored/missing`, type et recherche. Trois vues
  (`?view=flat|folder|discovery`, défaut `folder`) :
  - `Flat` : tableau global paginé (ancienne vue) ;
  - `Folders` : vrai navigateur de fichiers avec arbre de dossiers à gauche
    (`root_key + folder_path`), breadcrumb et contenu du dossier à droite.
    L’arbre initial ne contient que les racines, repliées ; chaque niveau est
    chargé à la demande via `folder/contents` (`subfolders[].has_children`).
    L’option `scanner.full_folder_tree` permet de rendre l’arbre complet
    d’un coup si la bibliothèque est petite. Cliquer sur le libellé d’un
    dossier le sélectionne et déplie son niveau. Films et séries utilisent le
    même arbre.
  - `Discovery` : navigation Type > Élément > fiche TMDB light > fichiers.
    Les séries/anime sont groupées par `show_folder`, les films par
    `tmdb_id` ou `title_normalized + year`. Les cartes visibles sont enrichies
    via `TmdbService::details` (poster, backdrop, note, synopsis, genres).
    L’endpoint élément renvoie les détails TMDB (`details`) et les fichiers
    avec naming/statut. La carte ouvre un hero TMDB + tableau des fichiers.
- Le renommage en masse agit uniquement sur les entrées `active`, non
  ignorées, avec `tmdb_id`/`imdb_id`, et après un preview explicite.
- `folder_path` est stocké par le parser et backfillé pour les anciennes
  entrées.
- Une colonne **Naming** indique `Conform`, `To rename`, `Conflict`,
  `Unmatched`, `Ignored`, `Missing` ou `Skipped`. Le résumé global compte les
  conformes / à renommer / conflits / ignorés. Le résumé et les badges utilisent
  un calcul “light” sans accès NAS ni TMDB (`lightPreview`) pour garder la page
  rapide ; le preview/apply réel du renommage reste complet.
- Chaque ligne matched dispose d’une action **Fix** (ou **Match** si non
  matché) : dialogue avec le match TMDB courant, recherche libre et
  application manuelle. L’application utilise les endpoints
  `automation_attention_resolve` et met à jour tout le groupe (film ou
  dossier de série).
- Le preview de renommage expose aussi `conform` pour les fichiers déjà
  correctement nommés ; l’application ne traite que les statuts `ok`.
- Nomenclature appliquée par `LibraryRenameService` :
  - Films : `<root>/<Title> (<Year>)/<Title> (<Year>).<ext>`
  - Séries/anime : `<root>/<Title> (<Year>)/Season XX/<Title> (<Year>) - SxxExx.<ext>`
  - Les noms d’épisodes ne sont **jamais** inclus dans le nom de fichier :
    seuls le titre de la série, l’année et le code SxxExx sont utilisés.
  - Les sidecars du même stem (`.srt`, `.nfo`, etc.) suivent le fichier ;
    `movie.nfo` suit le film. Les `tvshow.nfo`/`season.nfo` de dossier ne sont
    pas déplacés automatiquement.
  - Les conflits de cible, fichiers sources absents, racines non inscriptibles
    et spéciaux (saison 0) sont marqués `skip`/`conflict` dans le preview.
  - `rename_runs` conserve l’historique ; la page Activity l’affiche.
  - Chaque run stocke un journal complet des déplacements (fichier principal +
    sidecars, source → cible). La commande
    `app:library-rename-undo [--run=ID] [--apply]` permet de prévisualiser puis
    d’annuler un run (les chemins DB sont restaurés ; les dossiers vides peuvent
    rester).
- Chaque scan possède un `scan_id`. Les fichiers vus sont marqués avec ce
  `scan_id`, puis les entrées actives de la racine qui n'ont pas ce `scan_id`
  passent en `missing`. Une racine absente ou sans candidat vidéo ne marque
  rien `missing` : les entrées précédentes sont conservées et un warning est
  persisté.
- Les champs d'enrichissement (`tmdb_id`, `imdb_id`, `media_key`,
  `resolved_at`, `resolution_source`) sont préservés lors des scans suivants
  tant que le parser n'apporte pas de nouvel ID.
- Le parser est versionné (`LibraryNameParser::VERSION`) et l'upsert distingue
  `inserted`, `updated` et `unchanged` (taille/mtime/version identiques).
- `show_folder` est stocké pour chaque série. En fin de scan,
  `reconcileSeriesAmbiguity()` promeut à 0,84 les séries sans année dont le
  dossier est unique, et maintient à 0,78 les vrais collisions de titre
  (même titre, dossiers différents). Les multi-épisodes restent ambigus.

### Réglages par défaut

- Profil qualité : `2160p > 1080p > 720p`, codecs `x265/h264/AV1`, HDR
  préféré, audio `VF/VOSTFR/MULTI`, minimum 5 seeders, max film 25 Go,
  max épisode série 5 Go.
- Backfill : activé, spéciaux exclus, surveillance des futurs épisodes,
  max 50 intents par run, pause à 20 téléchargements automatiques.
- Intents films/séries : backoff 60 min (×2 par tentative, plafond 24 h), 5 tentatives
  max, `resolving` stale remis en pending après 30 min, intervalle minimum de
  run 60 s.
- Scanner : extensions vidéo courantes, skip samples, lecture NFO,
  intervalle 6 h.
- Métadonnées : `metadata.max_lookups_per_run` (50), cache positif 30 jours,
  cache négatif 2 jours.
- Numérotation séries : provider AIOMetadata → Cinemeta → TVmaze → local,
  cache 6–12 h, re-détection des profils auto après 7 jours, mapping ambigu
  retenté après 6 h, modes par défaut `target_mode=local` et
  `jellyfin_mode=local`.
- Listes : `lists.max_items_per_run` (500),
  `lists.max_show_lookups_per_run` (150), cache épisodes positif 7 jours /
  négatif 1 jour, `lists.include_unaired` désactivé. Le dry-run est le seul
  mode disponible dans ce lot.
- Notifications : in-app uniquement pour la v1.
- CSRF global sur les routes state-changing hors Jellyfin (`_token` /
  `X-CSRF-Token`), validation des racines sous `/nas`, `/mnt`, `/media` ou
  `/storage`.
- Verrous : `automation_scan.lock` et `automation_worker.lock` distinguent
  verrou détenu et erreur d'ouverture ; le heartbeat est maintenu pendant
  les scans longs et les runs stale sont marqués en erreur au démarrage du
  worker.

### Tests

- `tests/automation_smoke.php` : parser (années dans le titre, Unicode,
  séries homonymes, multi-épisodes, NFO), presets `inserted/updated/unchanged`,
  préservation des enrichissements, remplacement des listes de réglages,
  résolution TMDB avec faux service (séries, films, collisions, cache négatif),
  normalisation Scrob/MDBList et dry-run d'ingestion
  (présent/manquant/ambigu/ignoré, épisodes diffusés, suppressions),
  parsing de releases (résolution/codec/HDR/audio/source/groupe/taille),
  scoring et filtres durs, persistance/déduplication des intents films et
  épisodes, création depuis `missing_json`, plafond/queue guard, résolution
  série avec fakes, nommage sans titre d'épisode, refus des packs/multi-épisodes
  et lifecycle succès/échec, ainsi que le mapping TMDB ↔ TVDB/Cinemeta
  (segments, intents release, résolution sur la saison release et affichage
  Jellyfin virtuel).
- Lancement : `composer test:automation` ou `php tests/automation_smoke.php`.
- `composer test:security` ou `php tests/session_secrets_smoke.php` : hashing
  legacy, idle timeout, redaction des logs, permissions et secrets config.
- `composer test:ssrf` ou `php tests/ssrf_smoke.php` : URLs privées/réservées,
  résolution DNS simulée, politique de redirection et filtrage des streams
  directs d'addons, sans réseau.
- `composer test:csrf` ou `php tests/csrf_smoke.php` : portée du rejet CSRF,
  formulaires/JSON/multipart, exemption Jellyfin et inventaire des routes
  state-changing.
- `composer test:backfill` ou `php tests/backfill_smoke.php` : intégrité des
  téléchargements, reprise des intents, plafonds, sélection de fichiers et
  notifications de backfill.
- `composer test:auth` ou `php tests/auth_security_smoke.php` : inventaire des
  routes via `debug:router`, redirection login de toutes les routes GET
  protégées et polling, exemptions login/Jellyfin/framework, AuthService
  (plaintext legacy, hash, logout, idle timeout 2 h, polling sans refresh),
  JellyfinAuthService (token, refus, révocation, routes publiques/protégées).
- `composer test:http-auth` ou `php tests/http_auth_smoke.php` : contrôle live
  optionnel et read-only des redirections login, du CSRF `POST /login`, des
  routes Jellyfin publiques/protégées. `BASE_URL` par défaut
  `http://127.0.0.1:8000`.
- `composer test:functional` ou `php tests/functional_flow_smoke.php` : flux
  complets avec fakes (liste → dry-run → queue → resolve → payload/naming →
  succès → scan → présent), split TVDB 24/23 (TMDB S01E25 → release S02E01),
  épisode local/déplacé/supprimé, plafond/queue guard, release-verify,
  sélection déterministe de fichier, scan/racine vide/déplacement entre
  racines/reconcile-missing, restauration/suppression des intents ignorés,
  réconciliation globale avec les listes et Jellyfin local/TMDB avec IDs stables.
- `composer test:security:all` : `auth` + `security` (session/secrets) +
  `csrf` + `ssrf`.
- `composer test:all` : `security:all` + `functional` + `lint:twig` +
  `lint:container`.
- Les tests de stockage nécessitent `pdo_sqlite` ; utiliser l'image Docker si
  le PHP local ne l'a pas. La suite HTTP live utilise `ext-curl` si présent,
  sinon le fallback stream.

Critère d'acceptation sécurité prioritaire : authentification/session,
protection des routes web/polling, CSRF, exemptions Jellyfin et auth par token.
Les smoke tests XSS/SSRF avancé/DNS rebinding et dépendances restent exécutés
pour non-régression, mais ne sont pas des critères GO produit de ce lot.

### Limites connues de ce lot

- Le dry-run n'enregistre aucune intention de téléchargement. Les intents sont
  créés séparément depuis la page Downloads (ou automatiquement si une source a
  `auto_download` et un dry-run validé).
- Les épisodes multi-épisodes (`S01E01E02`) ne sont jamais queue/téléchargés
  automatiquement. Les packs de saison ne sont téléchargés que si un fichier
  correspond exactement à l'épisode ; sinon l'intent est parqué en
  `no_source`/`error` avec backoff.
- La numérotation multi-saisons des animes est gérée par `series_numbering`
  quand les providers exposent un découpage cohérent. Les packs de saison
  complets restent hors périmètre, et un mapping ambigu doit être validé
  manuellement.
- Les téléchargements automatiques utilisent le premier chemin racine films
  activé, sinon `default_path`, et écrivent directement
  `<Title> (<Year>)/<Title> (<Year>).<ext>` pour être conformes au renommage
  Jellyfin/IA (`LibraryRenameService`). Le renommage reste disponible pour les
  autres fichiers, mais ne devrait pas être nécessaire pour ces downloads.
- Les notifications de release (no source, échec, interruption, disque plein)
  utilisent `server_notifications` et sont groupées par run ; aucun envoi
  externe n'est branché.
- Les anciens journaux `var/storage/logs/history_*.log` peuvent contenir des
  secrets historiques. Ils sont redacted à la lecture par les endpoints, mais
  restent sensibles au repos : les purger ou les roter manuellement.
- Les URLs directes des addons passent par `SafeUrlGuard` : HTTP/HTTPS, refus
  des userinfo, ports de services internes, localhost/`.local`, IP loopback,
  privées, réservées, link-local et multicast, résolution DNS A/AAAA contrôlée.
  Les redirections sont suivies manuellement (maximum 5) et chaque cible est
  revalidée. Risque résiduel : **DNS rebinding** entre la résolution et la
  connexion, car l'IP n'est pas pinnée ; un résolveur contrôlé ou un proxy
  sortant serait nécessaire pour le réduire complètement.
- Le matching d'un item de liste ne résout pas automatiquement son identité
  TMDB : il s'appuie sur les IDs fournis par Scrob/MDBList ou sur le fallback
  titre/année de l'index.
- Les listes MDBList de type saison/épisode sont normalisées, mais le cas
  majoritaire reste film/série.
- La résolution TMDB est faite par groupes non résolus : films par
  `title_normalized + year`, séries par `media_type + title_normalized + year`.
  Une série sans année n'est résolue que si `show_folder` est unique. Les
  résultats sont mis en cache dans `metadata_cache` (positif/négatif).
- Le matching utilise le titre localisé et `original_title` renvoyé par
  `TmdbService::search`, puis retente la recherche en `en-US` si la langue
  configurée ne donne aucun match (utile pour les titres romaji/anime).
- Une comparaison secondaire ignore les mots parasites (`the`, `a`, `an`,
  `movie`, `film`) pour rapprocher les variantes de titres.
- Si plusieurs séries ont exactement le même titre et des années différentes,
  le resolver compare les saisons/épisodes présents sur le NAS avec
  `details.seasons` de chaque candidat. Une seule correspondance structurelle
  est requise ; sinon le groupe reste ambigu.
- Les chemins sont des chemins internes au conteneur.
- Le scan ne supprime jamais de fichier du NAS ; il marque seulement les
  entrées absentes comme `missing`.
- Une racine vide ou absente ne marque rien `missing` pour éviter les faux
  positifs d'un montage NAS démonté. Après un déplacement manuel de fichiers
  entre racines, utiliser `app:library-reconcile-missing --root=<racine>
  [--dry-run]` : la commande vérifie l'existence réelle des fichiers actifs et
  marque les entrées déplacées comme `missing` sans toucher aux fichiers.
- Une racine absente ou sans candidat vidéo ne marque rien `missing` ; elle
  produit un warning et conserve l'index précédent.
- Les fichiers multi-épisodes (`S01E01E02`) sont détectés et volontairement
  marqués ambigus (confiance <= 0,6) : ils ne doivent pas servir à la
  déduplication automatique tant que l'expansion multi-épisodes n'est pas
  implémentée.
- La numérotation absolue des animes (ex. épisode 27 dans une saison 1) n'est
  pas encore mappée vers la saison/épisode TMDB ; les cas sans `SxxExx` sont
  ambigus.
- L'installation locale PHP doit disposer de `pdo_sqlite` et `simplexml` ;
  l'image Docker officielle `php:8.3-apache` les inclut.

---

## 20. Passerelle Jellyfin / Nuvio

L'application expose un sous-ensemble **lecture seule** de l'API Jellyfin pour
que Nuvio puisse se connecter via son intégration Jellyfin native. La source de
vérité reste `library_entries` dans `var/db/automation.db`.

### Activation

- Réglages dans `/config` : `jellyfin_enabled`, `jellyfin_username`,
  `jellyfin_password`, `jellyfin_server_name`.
- L'API est disponible sous `/jellyfin`. Dans Nuvio, utiliser l'adresse
  `http://<hôte>:<port>/jellyfin` (le port par défaut est `8000`).
- L'adresse racine `http://<hôte>:<port>` fonctionne aussi : Nuvio appelle
  `/System/Info/Public`, qui est redirigé en 302 vers
  `/jellyfin/System/Info/Public`. Le client adopte ensuite le préfixe
  `/jellyfin` pour les appels suivants.
- Le compte Jellyfin est séparé du compte admin. Tant que
  `jellyfin_password` est vide, l'authentification échoue.
- Les tokens sont persistés dans `var/storage/jellyfin_auth.json` (hash
  SHA-256, TTL d'un an, maximum 50 tokens). Les serverId/userId sont générés
  une fois puis conservés.

### Routes

| Méthode | Route | Rôle |
| --- | --- | --- |
| GET | `/jellyfin/System/Info/Public` | découverte, version `10.9.0` |
| GET | `/jellyfin/System/Info` | même information côté authentifié |
| POST | `/jellyfin/Users/AuthenticateByName` | login et émission du token |
| GET | `/jellyfin/Users/{userId}` | profil utilisateur |
| POST | `/jellyfin/Sessions/Logout` | révocation du token |
| GET | `/jellyfin/UserViews` | bibliothèques issues des racines Automation |
| GET | `/jellyfin/Items` | listage, recherche, pagination |
| GET | `/jellyfin/Items/{itemId}` | fiche film/série/épisode |
| GET | `/jellyfin/Shows/{seriesId}/Episodes` | épisodes d'une série |
| GET | `/jellyfin/UserItems/Resume` | reprise de lecture |
| POST/DELETE | `/jellyfin/UserPlayedItems/{itemId}` | marquer vu/non vu |
| POST | `/jellyfin/Items/{itemId}/PlaybackInfo` | sources de lecture |
| GET/HEAD | `/jellyfin/Videos/{itemId}/stream` | flux direct avec HTTP Range |
| GET | `/jellyfin/Videos/{itemId}/{mediaSourceId}/Subtitles/{index}/Stream.{format}` | sous-titres externes |
| GET | `/jellyfin/Items/{itemId}/Images/{imageType}/{index?}` | affiches/backdrops TMDB |
| GET | `/jellyfin/Genres` | réponse vide (pas de filtre par genre) |
| POST | `/jellyfin/Sessions/Playing[/Progress,/Stopped]` | progression de lecture |

### Mapping

- Une racine Automation activée et non vide devient une vue
  `CollectionFolder` (`movies`, `tvshows` ou `mixed`).
- Films : regroupés par `tmdb_id`, sinon `imdb_id`, sinon
  `title_normalized + year`. Chaque fichier du groupe devient une
  `MediaSource`.
- Séries/anime : regroupés par `show_folder` (fallback titre + année), chaque
  fichier devient un `Episode` avec `ParentIndexNumber` (saison) et
  `IndexNumber` (épisode).
- Les entrées `status = missing` sont exclues ; les entrées `active` sont
  exposées, y compris les groupes `ignored_at`.
- Les IDs Jellyfin sont encodés en base64url et préfixés (`lib_`, `mov_`,
  `ser_`, `ep_`) par `App\Service\Jellyfin\JellyfinIds`.
- Les IDs de séries sont stables : `series:tmdb:<id>` puis
  `series:imdb:<id>`, avec fallback `folder:`/`title:`. Les anciens IDs
  `folder:` restent résolus pour éviter de casser une bibliothèque Nuvio
  déjà synchronisée après un renommage.
- `ProviderIds` expose `Tmdb`, `Imdb` et `Tvdb` (quand un profil de
  numérotation TVDB existe) pour le matching Nuvio/Trakt.
- Les métadonnées et affiches sont enrichies via `TmdbService` uniquement si
  `fields` contient `Overview`/`Genres`/`People`/`Studios` ou si les images
  sont demandées. Les appels restent soumis au cache TMDB existant.

### Lecture

- `PlaybackInfo` renvoie les `MediaSources` avec `SupportsDirectPlay` et
  `SupportsDirectStream`, sans `TranscodingUrl` : la v1 est **direct play
  uniquement**.
- `/jellyfin/Videos/.../stream` utilise `BinaryFileResponse` et le support
  natif de `Range`/`If-Range`.
- `JellyfinMediaService` vérifie que le fichier réel est sous une racine
  activée (`realpath`) avant toute lecture.
- `ffprobe` alimente les pistes vidéo/audio/sous-titres et la durée, avec un
  cache de 7 jours et un cache d'échec de 5 minutes. Les sous-titres externes
  `.srt/.ass/.ssa/.vtt/.sub` du même dossier sont exposés en `External`.
- La progression et le statut vu sont stockés dans
  `var/storage/jellyfin_playback_state.json`.

### Sécurité et intégration Symfony

- `JellyfinAuthSubscriber` valide le token pour toutes les routes
  `/jellyfin/*`, sauf `System/Info/Public`, `Users/AuthenticateByName` et les
  images. Il lit `Authorization: MediaBrowser ... Token="..."`,
  `X-Emby-Token`, `X-MediaBrowser-Token` ou `?api_key=`.
- `AuthSubscriber` ignore les chemins `/jellyfin` pour ne jamais rediriger un
  client Jellyfin vers `/login`.
- `SessionIdleSubscriber` ferme la session web si elle a été démarrée sur une
  route `jellyfin_*`.
- Les images sont publiques car le chargeur d'images de Nuvio n'envoie pas le
  token ; elles ne contiennent que des affiches TMDB.

### Tests

- `composer test:jellyfin` ou `php tests/jellyfin_smoke.php`.
- Le test couvre : IDs, authentification/tokens, vues, regroupement
  films/séries, `MediaSources`, résolution du fichier, `PlaybackInfo`,
  épisodes, état vu/reprise et URL d'image.
- Les tests de bibliothèque nécessitent `pdo_sqlite` ; utiliser l'image Docker
  si le PHP local ne l'a pas.

### Limites

- Vidéo uniquement (pas de musique).
- Direct play uniquement ; pas de transcodage HLS. Un client qui refuse le
  direct play ne pourra pas lire les fichiers non compatibles.
- Pas de collections/boxsets Jellyfin.
- La bibliothèque doit avoir été scannée par Automation au préalable.
- Les gros fichiers mobilisent un worker Apache pendant la lecture ; un
  `X-Sendfile` ou un reverse proxy pourra être ajouté ultérieurement.
