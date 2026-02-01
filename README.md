# Downloader App

Une application web puissante construite avec Symfony et Python pour gérer vos téléchargements (vidéos, torrents) et votre bibliothèque musicale avec des fonctionnalités avancées basées sur l'IA.

## 🚀 Fonctionnalités

### 🎬 Vidéos & Torrents
- **Upload simple** : Support des liens magnets et des fichiers `.torrent`.
- **Intégration Alldebrid** : Débridage automatique des liens pour un téléchargement à vitesse maximale.
- **Organisation intelligente** : Groupement automatique des fichiers par "packs" (séries, albums) basé sur les noms de fichiers.
- **Renommage assisté par IA** : Utilisation de Grok pour suggérer des noms de fichiers propres et normalisés.

### 🎵 Musique (Music Explorer)
- **Téléchargement haut de gamme** : Support des liens Spotify via des outils CLI performants.
- **Gestion des Tags** : Éditeur de tags ID3 complet (Artiste, Album, Titre, Année, Genre).
- **Paroles (Lyrics)** : Récupération automatique des paroles synchronisées (LRC) via LRCLib ou Genius.
- **Classification par IA** : Détermination automatique du genre musical via Grok si les tags sont manquants.
- **Automatisation** : Script de déplacement vers la bibliothèque musicale avec renommage dossier/fichier (`Artiste/Artiste - Album - Track - Titre.mp3`).

### 🛠️ Système
- **File d'attente (Queue)** : Gestion séquentielle des téléchargements via un worker en arrière-plan.
- **Historique complet** : Suivi détaillé de chaque action avec logs en temps réel.
- **Multi-plateforme** : Compatible Windows et Linux.

---

## 🛠️ Installation

### Prérequis
- **PHP** 8.1 ou supérieur
- **Composer**
- **Python** 3.10 ou supérieur
- **Venv Python** (recommandé)

### Étapes
1. **Cloner le projet**
   ```bash
   git clone <url-du-repo>
   cd downloader
   ```

2. **Installer les dépendances PHP**
   ```bash
   composer install
   ```

3. **Préparer l'environnement Python**
   ```bash
   python -m venv venv
   # Windows
   .\venv\Scripts\activate
   # Linux
   source venv/bin/activate
   pip install mutagen requests spotipy beautifulsoup4 colorama tqdm
   ```

4. **Lancer le serveur de développement**
   ```bash
   symfony serve
   # OU
   php -S localhost:8000 -t public
   ```

5. **Lancer le worker de téléchargement** (doit tourner pour traiter la file d'attente)
   ```bash
   php bin/console app:download-worker
   ```
   *Note : Il est conseillé d'utiliser un cron ou un gestionnaire de processus (Supervisor) pour s'assurer que le worker tourne en permanence.*

---

## ⚙️ Configuration

Toute la configuration s'effectue directement dans l'interface via l'onglet **Settings**.

### Clés API (Indispensables)
- **Alldebrid API Key** : Obtenue sur votre compte Alldebrid pour le débridage.
- **Grok API Key** : Utilisée pour le renommage intelligent et la détection de genre.

### Configuration Musique
- **Music Root Path** : Chemin où sont stockés les fichiers temporaires téléchargés.
- **Library Path** : Chemin final de votre bibliothèque musicale triée.
- **Venv Path** : Chemin vers votre environnement virtuel (souvent `venv`).
- **Mode de Genre** : `Mapping` (basé sur des règles) ou `AI` (via Grok).

### Spotify & Lyrics
- **Spotify Client ID / Secret** : Requis pour la récupération des métadonnées lors de l'ajout de musique.
- **Genius API Token** : Pour la récupération des paroles non-synchronisées.
- **LRCLib Token** (Optionnel) : Pour les paroles synchronisées.

---

## 📂 Structure du projet

- `src/` : Code source Symfony (Contrôleurs, Services).
- `templates/` : Vues Twig pour l'interface web.
- `cli/` : Scripts Python pour le traitement lourd (download, tags, lyrics).
- `var/storage/` : Stockage des fichiers JSON de configuration, historique et queue.
- `public/` : Points d'entrée web et assets.

---

## 🔧 Utilisation des scripts CLI

Les scripts dans `cli/` peuvent être utilisés manuellement pour des opérations de maintenance :

- **`music_downloader.py`** : Moteur de téléchargement musical.
- **`lyrics_fetcher.py`** : Recherche et injecte des paroles dans les fichiers existants.
- **`tag_rename_move.py`** : Analyse, tag (IA), renomme et déplace les fichiers vers la bibliothèque.

---

## 📝 Notes
L'application utilise un système de stockage basé sur des fichiers JSON (`JsonStorage`) dans `var/storage`, ce qui évite d'avoir recours à une base de données SQL complexe pour une installation personnelle simple.
