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
# var/storage/config.json is 1 level up from cli/
CONFIG_FILE = os.path.join(os.path.dirname(SCRIPT_DIR), 'var', 'storage', 'config.json')

# Sources API possibles
SOURCES = {
    'lrclib': 'https://lrclib.net/api/',
    'genius': 'https://api.genius.com/',
    'spotify': 'https://api.spotify.com/v1/'
}

class LyricsFetcher:
    def __init__(self, config_file=None, force_dl_all=False, force_dl_unsync=False, add_unsync=False):
        self.config = self.load_config()
        self.results = {}
        self.force_dl_all = force_dl_all
        self.force_dl_unsync = force_dl_unsync
        self.add_unsync = add_unsync
        
        # Initialisation de Spotify avec spotipy
        spotify_cid = self.config.get("spotify_client_id", "")
        spotify_secret = self.config.get("spotify_client_secret", "")
        
        self.sp = None
        if spotify_cid and spotify_secret:
            try:
                self.sp = spotipy.Spotify(auth_manager=SpotifyClientCredentials(
                    client_id=spotify_cid,
                    client_secret=spotify_secret
                ))
            except Exception as e:
                print(f"Spotify Init Warn: {e}", file=sys.stderr)

    def load_config(self):
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r') as f:
                    return json.load(f)
            except:
                pass
        return {}

    def fetch_synced_lyrics(self, title, artist):
        try:
            params = {
                'track_name': title,
                'artist_name': artist
            }
            token = self.config.get("lrclib_token")
            headers = {'Authorization': f'Bearer {token}'} if token else {}
            response = requests.get(f"{SOURCES['lrclib']}get", params=params, headers=headers, timeout=10)
            data = response.json()
            if data.get('syncedLyrics'):
                return data['syncedLyrics'], True
        except Exception as e:
            pass # Silently fail for fetchers

        return None, False

    def fetch_unsynced_lyrics(self, title, artist):
        try:
            token = self.config.get("genius_api_token")
            if not token:
                return None, False
                
            headers = {'Authorization': f'Bearer {token}'}
            params = {'q': f'{title} {artist}'}
            response = requests.get(f"{SOURCES['genius']}search", headers=headers, params=params, timeout=10)
            data = response.json()
            if data.get('response', {}).get('hits'):
                song_url = data['response']['hits'][0]['result']['url']
                page_response = requests.get(song_url, headers={'User-Agent': 'Mozilla/5.0'}, timeout=10)
                if page_response.status_code == 200:
                    soup = BeautifulSoup(page_response.text, 'html.parser')
                    lyrics_containers = soup.find_all('div', {'data-lyrics-container': 'true'})
                    if lyrics_containers:
                        lyrics = ''
                        for container in lyrics_containers:
                            for br in container.find_all('br'):
                                br.replace_with('\n')
                            lyrics += container.get_text(separator='\n') + '\n'
                        
                        lyrics_lines = []
                        for line in lyrics.split('\n'):
                            line = line.strip()
                            if line:
                                if line.startswith('['):
                                    lyrics_lines.append('')
                                lyrics_lines.append(line)
                        return '\n'.join(lyrics_lines), False
        except Exception as e:
            pass

        return None, False

    def has_synced_lyrics(self, lyrics):
        if not lyrics: return False
        for line in lyrics.split('\n'):
            line = line.strip()
            if line.startswith('[') and ']' in line:
                content = line.split(']')[0][1:]
                try:
                    parts = content.split(':')
                    if len(parts) >= 2: return True
                except: continue
        return False

    def get_genre(self, file_path):
        try:
            audio = EasyID3(file_path)
            return audio.get('genre', [''])[0].lower()
        except: return ''

    def process_file(self, file_path):
        try:
            audio = EasyID3(file_path)
            title = audio.get('title', ['Untitled'])[0]
            artist = audio.get('artist', ['Unknown'])[0]
            album = audio.get('album', ['Unknown'])[0]
            
            report_data = {
                'artist': artist,
                'album': album,
                'title': title,
                'file': os.path.basename(file_path)
            }

            genre = self.get_genre(file_path)
            if 'instrumental' in genre or 'ambiance' in genre:
                return {**report_data, 'status': 'skipped', 'reason': 'instrumental/ambiance'}
            
            existing_lyrics = None
            is_existing_synced = False
            if file_path.lower().endswith('.mp3'):
                try:
                    audio_full = ID3(file_path)
                    lyrics_tags = audio_full.getall('USLT')
                    if lyrics_tags:
                        existing_lyrics = lyrics_tags[0].text
                        is_existing_synced = self.has_synced_lyrics(existing_lyrics)
                except: pass
            elif file_path.lower().endswith('.m4a'):
                try:
                    audio_full = MP4(file_path)
                    if audio_full.get('\xa9lyr', []):
                        existing_lyrics = audio_full['\xa9lyr'][0]
                        is_existing_synced = self.has_synced_lyrics(existing_lyrics)
                except: pass

            if existing_lyrics:
                if not self.force_dl_all:
                    if not (self.force_dl_unsync and not is_existing_synced):
                        return {**report_data, 'status': 'skipped', 'reason': 'already present'}

            lyrics, is_synced = self.fetch_synced_lyrics(title, artist)
            if not lyrics and self.add_unsync:
                lyrics, is_synced = self.fetch_unsynced_lyrics(title, artist)

            if lyrics:
                return {**report_data, 'status': 'found', 'lyrics': lyrics, 'synced': is_synced}
            return {**report_data, 'status': 'not_found'}
        except Exception as e:
            return {'file': os.path.basename(file_path), 'status': 'error', 'message': str(e)}

    def process_directory(self, path, recursive=False):
        files = []
        if os.path.isfile(path):
            if path.lower().endswith(('.mp3', '.m4a')):
                files.append(path)
        elif os.path.isdir(path):
            if recursive:
                for root, _, filenames in os.walk(path):
                    for filename in filenames:
                        if filename.lower().endswith(('.mp3', '.m4a')):
                            files.append(os.path.join(root, filename))
            else:
                for filename in os.listdir(path):
                    file_path = os.path.join(path, filename)
                    if os.path.isfile(file_path) and filename.lower().endswith(('.mp3', '.m4a')):
                        files.append(file_path)
        
        # Ensure tqdm writes to stderr to avoid polluting stdout (JSON)
        for file_path in tqdm(files, desc="Fetching lyrics", disable=len(files) < 2, file=sys.stderr):
            result = self.process_file(file_path)
            self.results[file_path] = result

    def save_lyrics(self):
        saved_count = 0
        for file_path, result in self.results.items():
            if isinstance(result, dict) and result.get('status') == 'found':
                try:
                    if file_path.lower().endswith('.mp3'):
                        audio = ID3(file_path)
                        audio.add(USLT(encoding=3, lang='eng', text=result['lyrics']))
                        audio.save()
                        saved_count += 1
                    elif file_path.lower().endswith('.m4a'):
                        audio = MP4(file_path)
                        audio['\xa9lyr'] = [result['lyrics']]
                        audio.save()
                        saved_count += 1
                except Exception as e:
                    print(f"Error saving {file_path}: {e}", file=sys.stderr)
        return saved_count

def main():
    parser = argparse.ArgumentParser(description='Lyrics fetcher and tagger')
    parser.add_argument('directory', help='Directory containing audio files')
    parser.add_argument('--recursive', '-r', action='store_true', help='Process subdirectories recursively')
    parser.add_argument('--force-save', '-s', action='store_true', help='Saves without prompting')
    parser.add_argument('--force-dl-all', '-f', action='store_true', help='Force lyrics download in all cases')
    parser.add_argument('--force-dl-unsync', '-n', action='store_true', help='Force lyrics download only if existing are unsynced')
    parser.add_argument('--add-unsync', '-u', action='store_true', help='Fetch unsynced from Genius if synced not found')
    parser.add_argument('--json', '-j', action='store_true', help='Output results as JSON')
    args = parser.parse_args()

    fetcher = LyricsFetcher(force_dl_all=args.force_dl_all, force_dl_unsync=args.force_dl_unsync, add_unsync=args.add_unsync)
    fetcher.process_directory(args.directory, args.recursive)

    if args.force_save:
        fetcher.save_lyrics()

    if args.json:
        # Simple JSON output for PHP
        summary = []
        for path, res in fetcher.results.items():
            if isinstance(res, dict):
                summary.append({
                    'file': res.get('file'),
                    'artist': res.get('artist'),
                    'album': res.get('album'),
                    'title': res.get('title'),
                    'status': res.get('status'),
                    'type': 'synced' if res.get('synced') else ('unsynced' if res.get('status') == 'found' else 'none'),
                    'reason': res.get('reason')
                })
        print(json.dumps(summary))
    else:
        # Human report
        print("\n=== Lyrics Fetching Report ===")
        for path, res in fetcher.results.items():
            if isinstance(res, dict):
                status = res.get('status')
                color = Fore.WHITE
                if status == 'found':
                    color = Fore.GREEN if res.get('synced') else Fore.YELLOW
                    msg = "Synced" if res.get('synced') else "Unsynced"
                elif status == 'skipped':
                    color = Style.DIM
                    msg = f"Skipped ({res.get('reason')})"
                elif status == 'not_found':
                    color = Fore.RED
                    msg = "Not found"
                else:
                    color = Fore.RED
                    msg = res.get('message', 'Error')
                print(f"{color}{res.get('file')}: {msg}{Style.RESET_ALL}")

if __name__ == "__main__":
    main()