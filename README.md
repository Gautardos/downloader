# Downloader App

Une application web puissante construite avec Symfony et Python pour gérer vos téléchargements (vidéos, torrents) et votre bibliothèque musicale avec des fonctionnalités avancées basées sur l'IA.

## 🚀 Fonctionnalités

### 🎬 Vidéos & Torrents
- **Upload simple** : Support des liens magnets et des fichiers `.torrent`.
- **🔍 Moteur de recherche interne** : Intégration d'une base de données locale (CSV) pour rechercher des torrents par titre et auto-remplir les magnets.
- **👀 Preview Magnet** : Visualisation du contenu d'un magnet (liste des fichiers, tailles, statut Alldebrid) avant l'upload.
- **Intégration Alldebrid** : Débridage automatique des liens pour un téléchargement à vitesse maximale.
- **Organisation intelligente** : Groupement automatique des fichiers par "packs" (séries, albums) et création récursive des dossiers.
- **📁 Création groupée de dossiers** : Bouton dédié pour créer tous les dossiers manquants en une fois lors d'un import en lot.
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
   pip install -r cli/requirements.txt
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

### 🎵 Configuration Spotify (Obligatoire pour la Musique)

Pour que l'application puisse récupérer les métadonnées et télécharger de la musique, vous devez créer une application sur le portail développeur de Spotify :

1.  Rendez-vous sur le [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2.  Connectez-vous avec votre compte Spotify.
3.  Cliquez sur **"Create app"**.
4.  Donnez un nom et une description (ex: `My Downloader`).
5.  Dans **Redirect URIs**, vous pouvez mettre `http://localhost:8000/callback` (bien qu'non utilisé pour cette application cli, il est requis par Spotify).
6.  Acceptez les conditions et cliquez sur **Save**.
7.  Sur la page de votre application, cliquez sur **Settings**.
8.  Vous y trouverez votre **Client ID** et votre **Client Secret** (cliquez sur "View client secret").
9.  Copiez ces deux valeurs dans l'onglet **Settings** de l'application Downloader.

> [!TIP]
> Pour plus de détails, consultez la [documentation officielle Spotify](https://developer.spotify.com/documentation/web-api/concepts/apps).

---

## Focus sur le système Alldebrid

L'application n'est pas un client BitTorrent classique. Elle délègue le téléchargement des fichiers P2P au service **Alldebrid**, ce qui permet de télécharger à la vitesse maximale de votre connexion internet sans exposer votre adresse IP.

### Fonctionnement technique :
1. **Soumission** : Vous envoyez un lien magnet ou un fichier `.torrent` via le dashboard.
2. **Transfert Cloud** : Alldebrid télécharge le contenu sur ses serveurs haute performance.
3. **Récupération des liens** : L'application interroge l'API v4.1 pour l'état du magnet. Une fois prêt (Status 4), elle extrait récursivement tous les fichiers du pack.
4. **Débridage & Streaming** : Chaque lien de fichier est "débridé" (unlocked) pour générer un lien direct HTTP. Si possible, un lien de streaming optimisé est également généré.
5. **Worker Local** : Si vous choisissez de télécharger localement, le `DownloadWorkerCommand` prend le relais pour transférer ces fichiers HTTP vers votre stockage local de manière séquentielle.

---

## 📝 Exemples de Configuration

Pour vous aider à configurer l'application, voici des exemples concrets à saisir dans l'onglet **Settings** :

### 🏷️ Mapping des Genres (JSON)
Permet de regrouper des sous-genres complexes sous des catégories simplifiées dans votre bibliothèque.
```json
{
  "genre_patterns": {
    "tech house|deep house|minimal": "House",
    "drill|trap|boom bap": "Hip-Hop",
    "liquid|neurofunk": "Drum & Bass",
    "synthwave|retrowave": "Electronic"
  }
}
```

### 🤖 Prompt Grok pour les Genres
Exemple de prompt pour affiner la détection IA :
> "Tu es un expert musical. Classe cet album. Réponds uniquement par : Pop, Rock, Rap, Electro, Jazz, Classique ou Metal. Sois précis sur les artistes hybrides."

### 📂 Modèle de nommage (Musique)
Variables disponibles : `{artist}`, `{album}`, `{song_name}`, `{track_number}`, `{year}`, `{ext}`.
- Standard : `{artist}/{album}/{track_number} - {song_name}.{ext}`
- Simple : `{artist} - {song_name}.{ext}`

### 📍 Chemins (Relative vs Absolute)
- **Venv Path** : `./venv` (si à la racine)
- **Music Root** : `C:/Downloads/Music/Temp` (Windows) ou `/mnt/data/music/temp` (Linux)
- **Library Path** : `//NAS/Music/Library` (Support des lecteurs réseau)

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
