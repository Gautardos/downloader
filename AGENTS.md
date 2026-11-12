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
│       ├── MediaTypeHelper.php    # Détection type/icône par extension
│       ├── QueueManager.php       # File d'attente + historique + démarrage worker
│       ├── SpotifyService.php     # Métadonnées Spotify (Client Credentials)
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
| `worker_heartbeat.json` (`worker_heartbeat`) | Timestamp de vie du worker | worker, `QueueManager` |
| `progress_<downloadId>.json` | Progression d'un téléchargement | `DownloadManager`, `/progress` |

Autres fichiers runtime (hors JsonStorage) :
- `var/storage/worker.lock` : mutex `flock` du worker.
- `var/storage/worker.log` : log texte du worker.
- `var/storage/logs/active_worker.log` : log du téléchargement musique en cours.
- `var/storage/logs/history_<downloadId>.log` : log détaillé par téléchargement.
- `var/db/torrents.db` : base SQLite de recherche de torrents.

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
2. `pop()` un item. Si vide : efface la tâche active, heartbeat, `sleep(2)`.
3. Met la tâche active et émet une notification `Starting`.
4. Traite selon `item['type']` :
   - `music` → `processMusicTask()`
   - sinon → `DownloadManager::downloadFile()` (heartbeat toutes les ~5 s via
     callback), puis `recordHistory(..., 'success', 1, résultat)`.
5. Succès/échec → notification serveur + historique (échec : `recordHistory`
   avec statut `error`), efface la tâche active, `usleep(100000)`.

Un `flock(LOCK_EX | LOCK_NB)` sur `worker.lock` empêche les workers concurrents.
Supervisor relance le worker en cas d'arrêt, mais un seul peut vivre à la fois.

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
  'retry_count' => int (ajouté lors des retries)
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
   `artist`/`album`/`song_name` (comparaison insensible à la casse/espaces) :
   - toutes présentes → `success`
   - aucune → `error`
   - partielles → `warning`
   - `expected_tracks` vide → `error` (déclenche retry)
5. Si `error` et `retry_count < 2` : historise `failed_requeued`, incrémente
   `retry_count`, suffixe `download_id` avec `_retry_N`, ré-enqueue.
   Jusqu'à **3 tentatives** au total.
6. Sinon `recordHistory` avec pistes trouvées/manquantes.

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
| `music_format` | `mp3` | Format audio (`mp3`, `ogg`, `flac`, …) |
| `music_root_path` | — | Répertoire de dl musique (interne conteneur) |
| `music_creds` | `/var/www/html/var/home/.local/credentials.json` | Credentials Spotify |
| `music_archive` | `/var/www/html/var/home/log/archive.txt` | Archive Zotify (skip) |
| `music_quality` | `very_high` | Qualité Zotify |
| `music_skip_existing` | `false` | Skip déjà téléchargés |
| `music_lyrics` | `false` | Télécharger les paroles via Zotify |
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
| GET | `/torrent` | `torrent` | Page Debrid (vérifie clé + chemin) |
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
| GET ★ | `/notifications-poll` | `notifications_poll` | Lit puis vide `server_notifications` |
| GET | `/logs` | `logs` | Liste des fichiers de logs |

### Musique (recherche / fichiers)
| Méthode | URL | Nom | Rôle |
| --- | --- | --- | --- |
| GET | `/` | `music` | Accueil = recherche Spotify |
| POST | `/music-add` | `music_add` | Métadonnées Spotify + enqueue musique |
| GET | `/music-files` | `music_files` | Liste des fichiers audio + tags |
| GET | `/music/stream` | `music_stream` | Streaming audio avec support des requêtes HTTP Range |
| POST | `/file-tags` | `file_tags` | Extrait les tags d'un fichier |
| POST | `/update-tags` | `update_tags` | Met à jour des tags |
| POST | `/music/update-genres` | `music_update_genres` | Lance `tag_rename_move.py --tags-only` |
| POST | `/music/fetch-lyrics` | `fetch_lyrics` | Lance `lyrics_fetcher.py` |
| POST | `/music/move-to-library` | `move_to_library` | Lance `tag_rename_move.py` (déplacement) |

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
- Retourne un mapping `{ original_filename: { filename, path } }`.

### SpotifyService

- `Client Credentials` sur `accounts.spotify.com/api/token`.
- Token mis en cache en mémoire (buffer 60 s).
- `parseUrl()` accepte `/track/`, `/album/`, `/playlist/` avec segments de
  locale (`/intl-fr/`, `/fr-fr/`) et query `?si=`.
- `getMetadata(url)` retourne `['name' => ..., 'tracks' => [['artist','album','song_name'], ...]]`.
- `getLogs()` expose un journal horodaté utilisé pour écrire
  `history_<id>.log` à l'enqueue.
- `testCredentials()` vérifie l'obtention d'un token.

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
| `--verify <dir> [--recursive]` | Parcourt les fichiers audio, lit les tags via `music_tag`, retourne un JSON (artist, album, song_name, filename, rel_path, full_path, size, mtime, genre, lyrics). Normalise les séparateurs d'artistes (`/`, `;` → `,`). |
| `--tags <file>` | Retourne un JSON de tous les tags d'un fichier. |
| `--update-tags <file> --<tag> <value>...` | Met à jour les tags via `music_tag`. |
| `<args...>` | Exécute `music_binary <args...>` en héritant stdout/stderr, propage le code retour. |

> `music_tag` est importé dans `verify_directory` mais **absent de
> `requirements.txt`** (il est probablement fourni par Zotify ou installé dans
> l'environnement). À vérifier lors d'un rebuild.

### `lyrics_fetcher.py`

- Sources : LRCLib (`/get`, token optionnel), Genius (`/search` puis scraping
  HTML `data-lyrics-container`), Spotify (spotipy, optionnel).
- Lit/écrit MP3 (`ID3`/`USLT`) et M4A (`MP4` `©lyr`).
- Détecte les paroles synchronisées (timestamps `[mm:ss.xx]`).
- Options : `--recursive`, `--force-save`, `--force-dl-all`,
  `--force-dl-unsync`, `--add-unsync`, `--json`.
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
   log `history_<id>.log`, enqueue avec `expected_tracks`.
2. Worker : `music_downloader.py` → Zotify télécharge dans `music_root_path`.
3. Vérification `--verify` et calcul succès/warning/erreur + retries.
4. `/music-files` liste les fichiers (tags, genre, paroles présentes).
5. Actions UI : édition tags, `update-genres` (tags only), `fetch-lyrics`,
   `move-to-library`.
6. `move-to-library` déplace dans `music_library_path` avec la nomenclature
   `Artist/Artist - Album - N - Titre.ext`, historise et émet une notification.

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
- Logs : `/var/log/redis*.log`, `/var/log/worker*.log`,
  `/var/log/apache2*.log`, `/var/log/supervisord.log`.

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
docker exec -it <container> cat /var/log/worker.log
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
Il n'y a **aucune suite de tests** dans le dépôt. Vérifications minimales après
modification :
```bash
find src config public -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;
composer validate --no-check-publish
php bin/console lint:twig templates
php bin/console lint:container
php bin/console debug:router
```
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
- **Pas de protection CSRF** (`framework.yaml` : `csrf_protection` commenté).
- **Identifiants admin en clair** dans `var/storage/config.json`, mot de passe
  par défaut `admin/admin`.
- **Routes de polling non authentifiées** (`queue_status`, `progress`,
  `notifications_poll`, `queue_active_log`).
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
| `cli/music_downloader.py` | Contrat entre PHP et Zotify (JSON/verify/tags) |
| `cli/tag_rename_move.py` | Tags, genres, bibliothèque |
| `templates/base.html.twig` | Layout, polling, tracker, notifications |
| `Dockerfile` + `supervisord.conf` | Runtime complet de l'image |
