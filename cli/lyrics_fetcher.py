#!/usr/bin/env python3

import os
import argparse
import json
import re
import unicodedata
from mutagen import File as MutagenFile
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
from difflib import SequenceMatcher
from urllib.parse import quote

colorama.init()

# Chemins et configuration
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
# var/storage/config.json is 1 level up from cli/
CONFIG_FILE = os.path.join(os.path.dirname(SCRIPT_DIR), 'var', 'storage', 'config.json')

# Sources API possibles
SOURCES = {
    'lrclib': 'https://lrclib.net/api/',
    'lyrics_ovh': 'https://api.lyrics.ovh/v1/',
    'genius': 'https://api.genius.com/',
    'spotify': 'https://api.spotify.com/v1/'
}

BROWSER_USER_AGENT = (
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
    '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
)
GENIUS_PAGE_HEADERS = {
    'User-Agent': BROWSER_USER_AGENT,
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9,fr;q=0.8',
}


def normalize_match_text(value):
    value = unicodedata.normalize('NFKD', str(value or ''))
    value = ''.join(char for char in value if not unicodedata.combining(char))
    value = value.lower()
    value = re.sub(r'\b(feat|ft|featuring)\.?\b.*$', ' ', value)
    value = re.sub(r'[\[\(][^\]\)]*(remaster|version|edit|live)[^\]\)]*[\]\)]', ' ', value)
    value = re.sub(r'[^a-z0-9]+', ' ', value)
    return ' '.join(value.split())

def text_similarity(first, second):
    if not first or not second:
        return 0.0
    return SequenceMatcher(None, first, second).ratio()

def get_track_duration(file_path):
    try:
        audio = MutagenFile(file_path)
        if audio and getattr(audio, 'info', None):
            length = getattr(audio.info, 'length', None)
            if length:
                return float(length)
    except Exception:
        pass
    return None


def candidate_title(item):
    return str(item.get('trackName') or item.get('title') or '')


def candidate_artist(item):
    artist = item.get('artistName')
    if isinstance(artist, str):
        return artist
    artist = item.get('artist')
    if isinstance(artist, dict):
        return str(artist.get('name') or '')
    return str(artist or '')


def candidate_album(item):
    album = item.get('albumName')
    if isinstance(album, str):
        return album
    album = item.get('album')
    if isinstance(album, dict):
        return str(album.get('title') or '')
    return str(album or '')


def score_lyrics_candidate(title, artist, album, duration, item):
    query_title = normalize_match_text(title)
    query_artist = normalize_match_text(artist)
    query_album = normalize_match_text(album)

    item_title = normalize_match_text(candidate_title(item))
    item_artist = normalize_match_text(candidate_artist(item))
    item_album = normalize_match_text(candidate_album(item))

    title_score = text_similarity(query_title, item_title)
    if query_title and (query_title in item_title or item_title in query_title):
        title_score = max(title_score, 0.9)

    artist_score = text_similarity(query_artist, item_artist)
    if query_artist and (query_artist in item_artist or item_artist in query_artist):
        artist_score = max(artist_score, 0.9)

    album_score = text_similarity(query_album, item_album) if query_album and item_album else 0.5

    duration_score = 0.5
    item_duration = item.get('duration')
    if duration and item_duration:
        try:
            item_duration = float(item_duration)
            duration = float(duration)
            duration_score = max(0.0, 1.0 - (abs(item_duration - duration) / max(duration, 30.0)))
        except (TypeError, ValueError):
            pass

    combined = title_score * 0.60 + artist_score * 0.30 + album_score * 0.05 + duration_score * 0.05
    return combined, title_score, artist_score


def candidate_matches(title_score, artist_score, combined):
    if title_score < 0.55:
        return False
    return artist_score >= 0.35 or combined >= 0.55


def score_genius_hit(hit, title, artist):
    result = hit.get('result', {}) if isinstance(hit, dict) else {}
    query_title = normalize_match_text(title)
    query_artist = normalize_match_text(artist)

    hit_title = normalize_match_text(result.get('title') or result.get('full_title'))
    hit_artist = normalize_match_text((result.get('primary_artist') or {}).get('name'))
    artist_names = normalize_match_text(result.get('artist_names'))

    title_score = text_similarity(query_title, hit_title)
    if query_title and (query_title in hit_title or hit_title in query_title):
        title_score = max(title_score, 0.9)

    artist_scores = [text_similarity(query_artist, candidate) for candidate in (hit_artist, artist_names) if candidate]
    artist_score = max(artist_scores) if artist_scores else 0.0
    if query_artist and (query_artist in hit_artist or query_artist in artist_names):
        artist_score = max(artist_score, 0.9)

    combined = title_score * 0.7 + artist_score * 0.3
    return combined, title_score, artist_score, result

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

    def fetch_lrclib_lyrics(self, title, artist, album=None, duration=None):
        token = self.config.get("lrclib_token")
        headers = {'Authorization': f'Bearer {token}'} if token else {}
        include_unsynced = self.add_unsync

        params = {
            'track_name': title,
            'artist_name': artist
        }
        if album:
            params['album_name'] = album
        if duration:
            params['duration'] = str(int(round(duration)))

        try:
            response = requests.get(f"{SOURCES['lrclib']}get", params=params, headers=headers, timeout=10)
            if response.status_code == 200:
                data = response.json()
                if isinstance(data, dict):
                    synced = data.get('syncedLyrics')
                    plain = data.get('plainLyrics')
                    if synced:
                        return synced, True, 'LRCLIB', 0.98
                    if plain and include_unsynced:
                        return plain, False, 'LRCLIB', 0.95
        except Exception:
            pass

        try:
            search_params = {
                'track_name': title,
                'artist_name': artist
            }
            if album:
                search_params['album_name'] = album

            response = requests.get(f"{SOURCES['lrclib']}search", params=search_params, headers=headers, timeout=10)
            response.raise_for_status()
            candidates = response.json()
            if not isinstance(candidates, list):
                return None, False, None, 0.0

            scored = []
            for item in candidates:
                if not isinstance(item, dict) or item.get('instrumental'):
                    continue
                combined, title_score, artist_score = score_lyrics_candidate(title, artist, album, duration, item)
                if candidate_matches(title_score, artist_score, combined):
                    scored.append((combined, item))

            scored.sort(key=lambda entry: entry[0], reverse=True)
            for confidence, item in scored[:8]:
                synced = item.get('syncedLyrics')
                plain = item.get('plainLyrics')
                if synced:
                    return synced, True, 'LRCLIB', round(confidence, 2)
                if plain and include_unsynced:
                    return plain, False, 'LRCLIB', round(confidence, 2)
        except Exception as exc:
            print(f"LRCLIB search failed: {exc}", file=sys.stderr)

        return None, False, None, 0.0

    def fetch_lyrics_ovh(self, title, artist):
        try:
            response = requests.get(
                f"{SOURCES['lyrics_ovh']}{quote(artist)}/{quote(title)}",
                headers={'User-Agent': BROWSER_USER_AGENT},
                timeout=10
            )
            if response.status_code == 200:
                lyrics = str((response.json() or {}).get('lyrics') or '')
                lyrics = lyrics.replace('\r\n', '\n').replace('\r', '\n').strip()
                if lyrics and len(lyrics) >= 20:
                    return lyrics, 'lyrics.ovh', 0.55
        except Exception as exc:
            print(f"lyrics.ovh failed: {exc}", file=sys.stderr)
        return None, None, 0.0

    def fetch_unsynced_lyrics(self, title, artist):
        try:
            token = self.config.get("genius_api_token")
            if not token:
                return None, False

            headers = {'Authorization': f'Bearer {token}'}
            params = {'q': f'{title} {artist}'}
            response = requests.get(f"{SOURCES['genius']}search", headers=headers, params=params, timeout=10)
            if response.status_code != 200:
                print(f"Genius search returned HTTP {response.status_code}", file=sys.stderr)
                return None, False

            data = response.json()
            hits = data.get('response', {}).get('hits', [])
            if not hits:
                print(f"Genius search returned no result for: {title} - {artist}", file=sys.stderr)
                return None, False

            query_artist = normalize_match_text(artist)
            scored_hits = sorted(
                (score_genius_hit(hit, title, artist) for hit in hits),
                key=lambda item: item[0],
                reverse=True
            )

            selected_result = None
            for combined, title_score, artist_score, result in scored_hits:
                if title_score >= 0.6 and (not query_artist or artist_score >= 0.45):
                    selected_result = result
                    break
                print(
                    f"Genius result rejected (title={title_score:.2f}, artist={artist_score:.2f}): "
                    f"{result.get('title') or result.get('full_title')} - "
                    f"{(result.get('primary_artist') or {}).get('name')}",
                    file=sys.stderr
                )

            if not selected_result or not selected_result.get('url'):
                print(f"Genius found no reliable match for: {title} - {artist}", file=sys.stderr)
                return None, False

            song_url = selected_result['url']
            page_response = requests.get(song_url, headers=GENIUS_PAGE_HEADERS, timeout=15)
            if page_response.status_code != 200:
                print(
                    f"Genius page returned HTTP {page_response.status_code} for {song_url}",
                    file=sys.stderr
                )
                return None, False

            soup = BeautifulSoup(page_response.text, 'html.parser')
            page_title = soup.title.get_text(' ', strip=True) if soup.title else ''
            normalized_page_title = normalize_match_text(page_title)
            normalized_query_title = normalize_match_text(title)
            if (
                normalized_query_title
                and normalized_query_title not in normalized_page_title
                and text_similarity(normalized_query_title, normalized_page_title) < 0.45
            ):
                print(
                    f"Genius page rejected: expected '{title}', page title is '{page_title}'",
                    file=sys.stderr
                )
                return None, False

            lyrics_containers = soup.find_all('div', {'data-lyrics-container': 'true'})
            if not lyrics_containers:
                print(f"Genius page contained no lyrics container: {song_url}", file=sys.stderr)
                return None, False

            lyrics = ''
            for container in lyrics_containers:
                for br in container.find_all('br'):
                    br.replace_with('\n')
                lyrics += container.get_text(separator='\n') + '\n'

            lines = [line.strip() for line in lyrics.split('\n')]
            section_start = next(
                (index for index, line in enumerate(lines) if re.match(r'^\[[^\]]+\]$', line)),
                None
            )

            if section_start is not None:
                lines = lines[section_start:]
            else:
                for index, line in enumerate(lines):
                    if line.lower().endswith(' lyrics'):
                        lines = lines[index + 1:]
                        break
                if 'Read More' in lines:
                    lines = lines[lines.index('Read More') + 1:]

            lyrics_lines = []
            for line in lines:
                line = line.strip()
                if not line or line in ('Read More', 'You might also like', 'Embed'):
                    continue
                if line.startswith('['):
                    lyrics_lines.append('')
                lyrics_lines.append(line)

            return '\n'.join(lyrics_lines).strip(), False
        except Exception as e:
            print(f"Genius lyrics error: {e}", file=sys.stderr)

        return None, False

    def has_synced_lyrics(self, lyrics):
        if not lyrics: return False
        for line in lyrics.split('\n'):
            if re.match(r'^\s*(?:\[\d{1,3}:\d{2}(?:[.:]\d{1,3})?\]|<\d{1,3}:\d{2}(?:[.:]\d{1,3})?>)', line):
                return True
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

            duration = get_track_duration(file_path)
            provider = None
            confidence = 0.0

            lyrics, is_synced, provider, confidence = self.fetch_lrclib_lyrics(title, artist, album, duration)
            if not lyrics and self.add_unsync:
                lyrics, is_synced = self.fetch_unsynced_lyrics(title, artist)
                if lyrics:
                    provider = 'Genius'
                    confidence = 0.60
                else:
                    lyrics, provider, confidence = self.fetch_lyrics_ovh(title, artist)
                    is_synced = False

            if lyrics:
                return {
                    **report_data,
                    'status': 'found',
                    'lyrics': lyrics,
                    'synced': is_synced,
                    'provider': provider or 'Unknown',
                    'confidence': confidence,
                }

            reason = 'No lyrics found on LRCLIB, Genius or lyrics.ovh' if self.add_unsync else 'No synced lyrics found on LRCLIB'
            return {**report_data, 'status': 'not_found', 'reason': reason}
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
                        audio.delall('USLT')
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
                    'reason': res.get('reason'),
                    'provider': res.get('provider'),
                    'confidence': res.get('confidence'),
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
                    if res.get('provider'):
                        msg += f" via {res.get('provider')}"
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