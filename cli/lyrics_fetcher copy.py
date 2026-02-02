#!/usr/bin/env python3

import os
import argparse
import json
from mutagen.easyid3 import EasyID3
from mutagen.id3 import USLT, ID3
from mutagen.mp4 import MP4, MP4FreeForm
import requests
from tqdm import tqdm
import colorama
from colorama import Fore, Style
import sys
import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from bs4 import BeautifulSoup  # Ajout pour parser les pages Genius

colorama.init()

# Chemins et configuration
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_DIR = os.path.join(os.path.dirname(SCRIPT_DIR), 'etc')
CONFIG_FILE = os.path.join(CONFIG_DIR, 'config.json')

# Sources API possibles
SOURCES = {
    'lrclib': 'https://lrclib.net/api/',
    'genius': 'https://api.genius.com/',
    'spotify': 'https://api.spotify.com/v1/'
}

class LyricsFetcher:
    def __init__(self, config_file, force_dl_all=False, force_dl_unsync=False, add_unsync=False):
        self.config = self.load_config()
        self.results = {}
        self.force_dl_all = force_dl_all  # Stockage du flag --force-dl-all
        self.force_dl_unsync = force_dl_unsync  # Stockage du flag --force-dl-unsync
        self.add_unsync = add_unsync  # Stockage du flag --add-unsync
        # Initialisation de Spotify avec spotipy
        self.sp = spotipy.Spotify(auth_manager=SpotifyClientCredentials(
            client_id=self.config["spotify"]["client_id"],
            client_secret=self.config["spotify"]["client_secret"]
        ))

    def load_config(self):
        with open(CONFIG_FILE, 'r') as f:
            return json.load(f)

    def get_genre(self, file_path):
        try:
            audio = EasyID3(file_path)
            return audio.get('genre', [''])[0].lower()
        except:
            return ''

    def fetch_synced_lyrics(self, title, artist):
        try:
            params = {
                'track_name': title,
                'artist_name': artist
            }
            token = self.config["lyrics"]["lrclib"]["token"] if self.config["lyrics"]["lrclib"]["token"] else None
            headers = {'Authorization': f'Bearer {token}'} if token else {}
            response = requests.get(f"{SOURCES['lrclib']}get", params=params, headers=headers)
            data = response.json()
            if data.get('syncedLyrics'):
                return data['syncedLyrics'], True
        except Exception as e:
            print(f"Erreur lrclib : {e}", file=sys.stderr)

        return None, False

    def fetch_unsynced_lyrics(self, title, artist):
        try:
            headers = {'Authorization': f'Bearer {self.config["lyrics"]["genius"]["token"]}'}
            params = {'q': f'{title} {artist}'}
            response = requests.get(f"{SOURCES['genius']}search", headers=headers, params=params)
            data = response.json()
            if data['response']['hits']:
                song_url = data['response']['hits'][0]['result']['url']
                # Requête HTTP pour récupérer la page web de Genius
                page_response = requests.get(song_url, headers={'User-Agent': 'Mozilla/5.0'})
                if page_response.status_code == 200:
                    soup = BeautifulSoup(page_response.text, 'html.parser')
                    # Récupérer tous les conteneurs de paroles
                    lyrics_containers = soup.find_all('div', {'data-lyrics-container': 'true'})
                    if lyrics_containers:
                        lyrics = ''
                        for container in lyrics_containers:
                            # Remplacer les <br> par des sauts de ligne
                            for br in container.find_all('br'):
                                br.replace_with('\n')
                            # Ajouter le texte du conteneur avec un saut de ligne
                            lyrics += container.get_text(separator='\n') + '\n'
                        # Nettoyer le texte : supprimer les espaces multiples et ajouter un saut de ligne avant chaque '['
                        lyrics_lines = []
                        for line in lyrics.split('\n'):
                            line = line.strip()
                            if line:  # Ignorer les lignes vides
                                if line.startswith('['):
                                    lyrics_lines.append('')  # Ajouter une ligne vide avant la section
                                lyrics_lines.append(line)
                        lyrics = '\n'.join(lyrics_lines)
                        return lyrics, False  # Retourne les paroles non synchronisées
                    else:
                        print(f"Erreur : Paroles non trouvées dans la page {song_url}", file=sys.stderr)
                else:
                    print(f"Erreur : Impossible de charger la page {song_url} (statut {page_response.status_code})", file=sys.stderr)
            else:
                print(f"Aucune chanson trouvée pour '{title}' par '{artist}' sur Genius", file=sys.stderr)
        except Exception as e:
            print(f"Erreur Genius : {e}", file=sys.stderr)

        return None, False

    def has_synced_lyrics(self, lyrics):
        """Vérifie si les paroles sont synchronisées (contiennent des timestamps au format [mm:ss.xx])."""
        if not lyrics:
            return False
        for line in lyrics.split('\n'):
            line = line.strip()
            if line.startswith('[') and ']' in line:
                content = line.split(']')[0][1:]  # Extrait le contenu entre [ et ]
                # Vérifie si le contenu ressemble à un timestamp [mm:ss.xx]
                try:
                    parts = content.split(':')
                    if len(parts) == 2:  # Format [mm:ss]
                        minutes, seconds = map(float, parts)
                        if 0 <= minutes < 60 and 0 <= seconds < 60:
                            return True
                    elif len(parts) == 3:  # Format [mm:ss.xx]
                        minutes, seconds = map(float, parts[:2])
                        if 0 <= minutes < 60 and 0 <= seconds < 60:
                            return True
                except ValueError:
                    continue  # Ignorer les lignes non conformes
        return False

    def process_file(self, file_path):
        try:
            audio = EasyID3(file_path)
            title = audio.get('title', [''])[0]
            artist = audio.get('artist', [''])[0]
            
            if 'instrumental' in self.get_genre(file_path).lower():
                return 'skipped (instrumental)'
            
            if 'ambiance' in self.get_genre(file_path).lower():
                return 'skipped (ambiance)'

            # Vérification des paroles existantes
            existing_lyrics = None
            is_existing_synced = False
            if file_path.lower().endswith('.mp3'):
                audio_full = ID3(file_path)
                lyrics_tags = audio_full.getall('USLT')
                if lyrics_tags:
                    existing_lyrics = lyrics_tags[0].text
                    is_existing_synced = self.has_synced_lyrics(existing_lyrics)
            elif file_path.lower().endswith('.m4a'):
                audio_full = MP4(file_path)
                if audio_full.get('\xa9lyr', []):
                    existing_lyrics = audio_full['\xa9lyr'][0]
                    is_existing_synced = self.has_synced_lyrics(existing_lyrics)

            # Conditions pour skipper sauf si force-dl-all ou force-dl-unsync s'applique
            if existing_lyrics:
                if self.force_dl_all:
                    pass  # On force le téléchargement dans tous les cas
                elif self.force_dl_unsync and not is_existing_synced:
                    pass  # On force uniquement si les paroles existantes sont non synchronisées
                else:
                    return 'skipped (lyrics already present)'

            # Priorité aux lyrics synchronisés
            lyrics, is_synced = self.fetch_synced_lyrics(title, artist)
            # Si pas de paroles synchronisées et --add-unsync activé, tenter les paroles non synchronisées
            if not lyrics and self.add_unsync:
                lyrics, is_synced = self.fetch_unsynced_lyrics(title, artist)

            if lyrics:
                return {'lyrics': lyrics, 'synced': is_synced}
            return 'not found'
        except Exception as e:
            return f'error: {str(e)}'

    def process_directory(self, directory, recursive=False):
        files = []
        for root, _, filenames in os.walk(directory) if recursive else [(directory, [], os.listdir(directory))]:
            for filename in filenames:
                if filename.lower().endswith(('.mp3', '.m4a')):
                    files.append(os.path.join(root, filename))

        with tqdm(total=len(files), desc="Fetching lyrics") as pbar:
            for file_path in files:
                result = self.process_file(file_path)
                self.results[file_path] = result
                pbar.update(1)

    def display_report(self):
        print("\n=== Lyrics Fetching Report ===")
        for file_path, result in self.results.items():
            if isinstance(result, dict):
                if result['synced']:
                    color = Fore.GREEN
                    status = "Synced lyrics found"
                else:
                    color = Fore.YELLOW
                    status = "Unsynced lyrics found"
            elif result == 'not found':
                color = Fore.RED
                status = "No lyrics found"
            elif result.startswith('skipped'):
                color = Style.RESET_ALL  # Couleur par défaut pour les fichiers ignorés
                status = result
            else:
                color = Fore.RED
                status = result
            print(f"{color}{file_path}: {status}{Style.RESET_ALL}")

    def save_lyrics(self):
        for file_path, result in self.results.items():
            if isinstance(result, dict):
                if file_path.lower().endswith('.mp3'):
                    try:
                        audio = ID3(file_path)
                        audio.add(USLT(encoding=3, lang='eng', text=result['lyrics']))
                        audio.save()
                    except Exception as e:
                        print(f"Erreur lors de l'enregistrement des paroles dans {file_path}: {e}", file=sys.stderr)
                elif file_path.lower().endswith('.m4a'):
                    try:
                        audio = MP4(file_path)
                        audio['\xa9lyr'] = [result['lyrics']]
                        audio.save()
                    except Exception as e:
                        print(f"Erreur lors de l'enregistrement des paroles dans {file_path}: {e}", file=sys.stderr)

def main():
    parser = argparse.ArgumentParser(description='Lyrics fetcher and tagger')
    parser.add_argument('directory', help='Directory containing audio files')
    parser.add_argument('--recursive', '-r', action='store_true', help='Process subdirectories recursively')
    parser.add_argument('--dry', '-d', action='store_true', help='Dry run (no prompt for saving)')
    parser.add_argument('--force-save', '-s', action='store_true', help='Saves without prompting')
    parser.add_argument('--force-dl-all', '-f', action='store_true', help='Force lyrics download in all cases, even if already present')
    parser.add_argument('--force-dl-unsync', '-n', action='store_true', help='Force lyrics download only if existing lyrics are unsynchronized')
    parser.add_argument('--add-unsync', '-u', action='store_true', help='Fetch unsynchronized lyrics from Genius if synced lyrics are not found')
    args = parser.parse_args()

    fetcher = LyricsFetcher(CONFIG_FILE, force_dl_all=args.force_dl_all, force_dl_unsync=args.force_dl_unsync, add_unsync=args.add_unsync)
    
    # Phase 1: Fetching
    fetcher.process_directory(args.directory, args.recursive)
    fetcher.display_report()

    # Phase 2: Validation
    if not args.dry and any(isinstance(r, dict) for r in fetcher.results.values()):
        if args.force_save:
            fetcher.save_lyrics()
            print("Lyrics saved successfully!")
        else:
            print(f"\n{Fore.GREEN}Save lyrics to files? (y/n): {Style.RESET_ALL}", end='')
            choice = input().lower()
            if choice == 'y':
                fetcher.save_lyrics()
                print("Lyrics saved successfully!")
            else:
                print("Lyrics not saved.")

if __name__ == "__main__":
    main()