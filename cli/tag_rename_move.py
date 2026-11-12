import os
import time
import argparse
import json
import re
import shutil
import unicodedata
import requests
import music_tag

# In-memory cache for genre detection during the current session
GENRE_CACHE = {}
AUDIO_EXTENSIONS = ('.mp3', '.flac', '.m4a', '.opus', '.ogg')

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CONFIG_PATH = os.path.join(BASE_DIR, 'var', 'storage', 'config.json')
SPOTIFY_TOKEN_CACHE_FILE = os.path.join(BASE_DIR, 'var', 'cache', 'spotify_token.json')
SPOTIFY_GENRE_CACHE_FILE = os.path.join(BASE_DIR, 'var', 'cache', 'spotify_genres_cache.json')
SPOTIFY_TOKEN_URL = 'https://accounts.spotify.com/api/token'
SPOTIFY_API_URL = 'https://api.spotify.com/v1'
SPOTIFY_CACHE_TTL = 30 * 86400

def load_config():
    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}

def read_json_cache(path):
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}

def write_json_cache(path, data, private=False):
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        temp_path = path + '.tmp'
        with open(temp_path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle, ensure_ascii=False)
        os.replace(temp_path, path)
        if private:
            try:
                os.chmod(path, 0o600)
            except Exception:
                pass
    except Exception:
        pass

def normalize_search_text(value):
    value = unicodedata.normalize('NFKD', str(value or ''))
    value = ''.join(char for char in value if not unicodedata.combining(char))
    return re.sub(r'[^a-z0-9]+', ' ', value.lower()).strip()

def get_spotify_token(config):
    client_id = str(config.get('spotify_client_id') or '').strip()
    client_secret = str(config.get('spotify_client_secret') or '').strip()
    if not client_id or not client_secret:
        return None

    now = time.time()
    cached = read_json_cache(SPOTIFY_TOKEN_CACHE_FILE)
    if (
        cached.get('access_token')
        and cached.get('client_id') == client_id
        and float(cached.get('expires_at') or 0) > now + 60
    ):
        return cached['access_token']

    try:
        response = requests.post(
            SPOTIFY_TOKEN_URL,
            data={'grant_type': 'client_credentials'},
            auth=(client_id, client_secret),
            timeout=15
        )
        response.raise_for_status()
        data = response.json()
        token = data.get('access_token')
        if not token:
            return None

        write_json_cache(SPOTIFY_TOKEN_CACHE_FILE, {
            'access_token': token,
            'client_id': client_id,
            'expires_at': now + int(data.get('expires_in') or 3600),
        }, private=True)

        return token
    except Exception:
        return None

def spotify_search_track(token, artist, album, title):
    title_clean = re.sub(r'"', '', str(title or '')).strip()
    artist_clean = re.sub(r'"', '', str(artist or '')).strip()
    album_clean = re.sub(r'"', '', str(album or '')).strip()

    parts = []
    if title_clean and title_clean.lower() not in ('untitled', 'unknown'):
        parts.append(f'track:"{title_clean}"')
    if artist_clean and artist_clean.lower() not in ('unknown',):
        parts.append(f'artist:"{artist_clean}"')
    if not parts and album_clean and album_clean.lower() not in ('unknown',):
        parts.append(f'album:"{album_clean}"')
    if not parts:
        return None

    response = requests.get(
        f'{SPOTIFY_API_URL}/search',
        headers={'Authorization': f'Bearer {token}'},
        params={'q': ' '.join(parts), 'type': 'track', 'limit': 5},
        timeout=15
    )
    response.raise_for_status()
    items = response.json().get('tracks', {}).get('items', []) or []

    target_artist = normalize_search_text(artist)
    target_title = normalize_search_text(title)

    for item in items:
        artist_names = ' '.join(a.get('name', '') for a in item.get('artists', []))
        item_title = normalize_search_text(item.get('name', ''))
        if target_artist and target_artist not in normalize_search_text(artist_names):
            continue
        if target_title and target_title not in item_title and item_title not in target_title:
            continue
        return item

    return items[0] if items else None

def spotify_artist_genres(token, artist_ids):
    artist_ids = [artist_id for artist_id in artist_ids if artist_id][:50]
    if not artist_ids:
        return []

    response = requests.get(
        f'{SPOTIFY_API_URL}/artists',
        headers={'Authorization': f'Bearer {token}'},
        params={'ids': ','.join(artist_ids)},
        timeout=15
    )
    response.raise_for_status()

    genres = []
    for artist in response.json().get('artists', []) or []:
        for genre in artist.get('genres', []) or []:
            if genre and genre not in genres:
                genres.append(genre)

    return genres

def spotify_album_genres(token, album_id):
    if not album_id:
        return []

    response = requests.get(
        f'{SPOTIFY_API_URL}/albums/{album_id}',
        headers={'Authorization': f'Bearer {token}'},
        timeout=15
    )
    response.raise_for_status()

    genres = []
    for genre in response.json().get('genres', []) or []:
        if genre and genre not in genres:
            genres.append(genre)

    return genres

def get_spotify_genres(artist, album, title, config, add_log):
    if not config.get('music_spotify_genres', True):
        add_log('Spotify genre lookup disabled.')
        return []

    if not config.get('spotify_client_id') or not config.get('spotify_client_secret'):
        add_log('Spotify credentials not configured; skipping Spotify genres.')
        return []

    cache_key = '|'.join([
        normalize_search_text(artist),
        normalize_search_text(album or title),
    ])
    cache = read_json_cache(SPOTIFY_GENRE_CACHE_FILE)
    now = time.time()
    entry = cache.get(cache_key)
    if entry and float(entry.get('updated_at') or 0) > now - SPOTIFY_CACHE_TTL:
        genres = entry.get('genres') or []
        if genres:
            add_log('Spotify genres from cache: ' + ', '.join(genres))
        return genres

    token = get_spotify_token(config)
    if not token:
        add_log('Could not obtain Spotify token; skipping Spotify genres.')
        return []

    try:
        track = spotify_search_track(token, artist, album, title)
        if not track:
            cache[cache_key] = {'genres': [], 'updated_at': now}
            write_json_cache(SPOTIFY_GENRE_CACHE_FILE, cache)
            add_log('No Spotify match found.')
            return []

        artist_ids = [artist_data.get('id') for artist_data in track.get('artists', [])]
        genres = spotify_artist_genres(token, artist_ids)
        if not genres:
            album_id = (track.get('album') or {}).get('id')
            genres = spotify_album_genres(token, album_id)

        cache[cache_key] = {'genres': genres, 'updated_at': now}
        write_json_cache(SPOTIFY_GENRE_CACHE_FILE, cache)

        if genres:
            add_log('Spotify genres: ' + ', '.join(genres))
        else:
            add_log('Spotify returned no genres.')

        return genres
    except Exception as exc:
        add_log(f'Spotify genre lookup failed: {exc}')
        return []

def get_genre_from_file(file_path):
    """Reads the genre from a specific file if it exists."""
    if not os.path.exists(file_path):
        return None
    try:
        audio = music_tag.load_file(file_path)
        genre = str(audio['genre']).strip()
        if genre and genre.lower() not in ["", "unknown", "none"]:
            return genre
    except:
        pass
    return None

def sanitize_name(name):
    if not name:
        return ""
    name = " ".join(name.split()).strip()
    if name.endswith('.'):
        name = name.rstrip('.')
    return name.replace("/", ",").replace('"', "").replace(":", "").replace("?", "").replace("¿", "")


def parse_filename_metadata(file_path):
    stem = os.path.splitext(os.path.basename(file_path))[0]
    parts = [part.strip() for part in stem.split(' - ')]
    if len(parts) >= 3:
        return parts[0], parts[1], ' - '.join(parts[2:])
    if len(parts) == 2:
        return parts[0], 'Unknown', parts[1]
    return 'Unknown', 'Unknown', stem or 'Untitled'

def map_genre(genre, mapping_json):
    if not genre:
        return "Unknown"
    
    try:
        patterns = json.loads(mapping_json).get('genre_patterns', {})
    except:
        return genre

    genre_lower = str(genre).lower()
    for pattern, mapped_genre in patterns.items():
        try:
            if re.search(pattern, genre_lower):
                return mapped_genre
        except re.error:
            continue
    return genre

def detect_genre_with_ai(artist, album, api_key, endpoint, model, prompt, referer="", app_title="", track_title="", year="", spotify_genres=None):
    if not api_key or not model:
        return "Unknown"

    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json"
    }
    if referer:
        headers["HTTP-Referer"] = referer
    if app_title:
        headers["X-Title"] = app_title

    # Default prompt if none provided
    system_prompt = prompt or """
    Tu es un expert en classification musicale. Détermine le genre musical de cet album/artiste.
    Réponds UNIQUEMENT par le nom du genre (ex: Pop, Rock, Rap & Hip-Hop).
    """

    user_lines = [f"Artiste: {artist}", f"Album: {album}"]
    if track_title:
        user_lines.append(f"Titre: {track_title}")
    if year:
        user_lines.append(f"Année: {year}")
    if spotify_genres:
        user_lines.append("Genres Spotify: " + ", ".join(spotify_genres))
        user_lines.append("Utilise en priorité ces genres Spotify et normalise-les selon les règles ci-dessus. Si aucun genre ne peut être normalisé, réponds Unknown.")

    messages = [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": "\n".join(user_lines)}
    ]
    
    payload = {
        "model": model,
        "messages": messages,
        "max_tokens": 5000,
        "temperature": 0.3
    }
    
    for attempt in range(2):
        try:
            response = requests.post(endpoint, headers=headers, json=payload, timeout=30)
            response.raise_for_status()
            content = response.json().get("choices", [{}])[0].get("message", {}).get("content", "Unknown")
            content = (content or "").strip()
            if content and content.lower() not in ["", "unknown", "none"]:
                return content
        except Exception:
            pass

        if attempt == 0:
            time.sleep(0.75)

    return "Unknown"

def process_file(file_path, library_path, args, config=None):
    config = config or {}
    report = {
        "original_file": os.path.basename(file_path),
        "status": "success",
        "error": None,
        "metadata_before": {},
        "final_path": None,
        "final_name": None,
        "logs": []
    }
    
    def add_log(msg):
        report["logs"].append(msg)

    try:
        audio = music_tag.load_file(file_path)

        def tag_value(name):
            try:
                return str(audio[name]).strip()
            except Exception:
                return ""

        title_tag = tag_value("tracktitle")
        artist_tag = tag_value("artist")
        album_tag = tag_value("album")
        albumartist_tag = tag_value("albumartist")
        old_genre = tag_value("genre") or "Unknown"
        date = tag_value("year") or "0000"
        track_tag = tag_value("tracknumber")

        fallback_artist, fallback_album, fallback_title = parse_filename_metadata(file_path)
        if not title_tag:
            title_tag = fallback_title
            add_log(f"No title tag found, using filename: {title_tag}")
        if not artist_tag:
            artist_tag = fallback_artist
            add_log(f"No artist tag found, using filename: {artist_tag}")
        if not album_tag:
            album_tag = fallback_album
            add_log(f"No album tag found, using filename: {album_tag}")

        report["metadata_before"] = {
            "title": title_tag or "Unknown",
            "artist": artist_tag or "Unknown",
            "album": album_tag or "Unknown",
            "genre": old_genre,
            "date": date
        }
        add_log(f"Reading metadata for: {report['original_file']}")
        add_log(f"Old genre found: {old_genre}")
        add_log(f"Date found: {date}")

        # 1. Determine main artist (prioritize albumartist)
        if albumartist_tag:
            main_artist = sanitize_name(albumartist_tag)
            add_log(f"Main artist from albumartist: {main_artist}")
        elif artist_tag:
            artists = [sanitize_name(a.strip()) for a in artist_tag.split(",") if a.strip()]
            main_artist = artists[0] if artists else "Unknown"
            add_log(f"Main artist from first listed artist: {main_artist}")
        else:
            main_artist = "Unknown"
            add_log("No artist found, using Unknown")

        # 2. Extract featuring artists
        featuring_artists = []
        if artist_tag:
            all_artists = [sanitize_name(a.strip()) for a in re.split(',|;', artist_tag) if a.strip()]
            all_artists = list(dict.fromkeys(all_artists))
            featuring_artists = [a for a in all_artists if a != main_artist]

        # 3. Update Title with featuring
        original_title = sanitize_name(title_tag or "Untitled")
        final_title = original_title
        if featuring_artists:
            feat_str = f"(feat. {', '.join(featuring_artists)})"
            if "feat. " not in original_title.lower():
                final_title = f"{original_title} {feat_str}"
                add_log(f"Detected features: {', '.join(featuring_artists)}. Title updated.")
            else:
                add_log(f"Features already in title: {original_title}")

        artist = main_artist
        album = sanitize_name(album_tag or "Unknown")
        title = final_title
        tracknum = str((track_tag or "1").split("/")[0]).zfill(2)
        ext = os.path.splitext(file_path)[1].lower()
        
        # Prepare destination path early for optimization
        artist_dir = os.path.join(library_path, artist)
        final_name = f"{artist} - {album} - {tracknum} - {title}{ext}"
        final_path = os.path.join(artist_dir, final_name)

        # Genre tagging logic
        new_genre = old_genre
        genre_source = "Original"
        cache_key = (artist, album)

        if args.force_ai:
            add_log("Forced AI genre detection requested.")
            if cache_key in GENRE_CACHE:
                new_genre = GENRE_CACHE[cache_key]
                genre_source = "Session Cache"
                add_log(f"Detected previous detection for this album in cache: {new_genre}")
            elif args.mode == 'ai':
                add_log(f"Querying AI for {artist} - {album}...")
                ai_genre = detect_genre_with_ai(artist, album, args.ai_key, args.ai_endpoint, args.ai_model, args.ai_prompt, args.ai_referer, args.ai_title)
                if ai_genre and ai_genre.lower() not in ["", "unknown", "none"]:
                    new_genre = ai_genre
                    GENRE_CACHE[cache_key] = ai_genre
                    genre_source = "AI"
                    add_log(f"AI detected genre: {new_genre}")
                else:
                    add_log("AI could not determine a genre. Keeping existing genre.")
            else:
                add_log("AI is disabled. Keeping existing genre.")
        else:
            # 1. Apply mapping first if genre exists
            if old_genre and old_genre.lower() not in ['', 'unknown', 'none']:
                mapped_genre = map_genre(old_genre, args.mapping)
                if mapped_genre != old_genre:
                    add_log(f"Applied mapping: {old_genre} -> {mapped_genre}")
                    new_genre = mapped_genre
                    genre_source = "Mapping"

            # 2. If genre is still unknown, try optimizations
            if new_genre.lower() in ['', 'unknown', 'none']:
                # A. Check if the PRECISE file already exists in destination
                dest_genre = get_genre_from_file(final_path)
                if dest_genre:
                    new_genre = dest_genre
                    genre_source = "Existing File"
                    add_log(f"Found existing file at destination. Inheriting genre: {new_genre}")
                else:
                    # B. Check memory cache (Session)
                    if cache_key in GENRE_CACHE:
                        new_genre = GENRE_CACHE[cache_key]
                        genre_source = "Session Cache"
                        add_log(f"Detected previous detection for this album in cache: {new_genre}")
                    # C. Spotify signal, then AI normalization
                    elif args.mode == 'ai':
                        spotify_genres = get_spotify_genres(artist, album, title, config, add_log)
                        add_log(f"No genre found. Querying AI for {artist} - {album}...")
                        ai_genre = detect_genre_with_ai(
                            artist,
                            album,
                            args.ai_key,
                            args.ai_endpoint,
                            args.ai_model,
                            args.ai_prompt,
                            args.ai_referer,
                            args.ai_title,
                            track_title=title,
                            year=date,
                            spotify_genres=spotify_genres
                        )
                        if ai_genre and ai_genre.lower() not in ["", "unknown", "none"]:
                            new_genre = ai_genre
                            GENRE_CACHE[cache_key] = new_genre
                            genre_source = "Spotify + AI" if spotify_genres else "AI"
                            add_log(f"AI detected genre: {new_genre}")
                        elif spotify_genres:
                            new_genre = spotify_genres[0]
                            GENRE_CACHE[cache_key] = new_genre
                            genre_source = "Spotify (fallback)"
                            add_log(f"AI could not normalize genre. Using Spotify genre: {new_genre}")
                        else:
                            add_log("AI could not determine a genre.")

        audio["artist"] = main_artist
        audio["tracktitle"] = final_title
        audio["album"] = album
        audio["genre"] = new_genre
        if date and date != "0000":
            audio["year"] = date
        audio.save()
        add_log(f"Saved tags with genre: {new_genre} (Source: {genre_source}) and date: {date}")

        # Flat keys are set before the early return so tags-only callers receive the result.
        report["artist"] = artist
        report["title"] = title
        report["genre"] = new_genre
        report["genre_source"] = genre_source
        report["old_genre"] = old_genre

        # If tags-only mode, stop here
        if args.tags_only:
            add_log("Tags-only mode: skipping move.")
            return report

        # Move
        if not os.path.exists(artist_dir):
            os.makedirs(artist_dir)
            add_log(f"Created directory: {artist_dir}")

        if os.path.exists(final_path):
            add_log(f"Overwriting existing file: {final_name}")
        
        try:
            shutil.move(file_path, final_path)
            add_log(f"Moved to: {final_path}")
        except Exception as move_err:
            # Fallback for cross-device move issues
            if os.path.exists(final_path):
                # EPERM (Errno 1) usually means metadata/chown failure on CIFS/exFAT
                # If file exists, the data is safe. We can ignore this specific error content-wise.
                is_eperm = getattr(move_err, 'errno', 0) == 1
                
                if is_eperm:
                     add_log(f"Info: Metadata preservation excluded by filesystem (EPERM), but data copied successfully.")
                else:
                     add_log(f"Warning: Move operation raised error, but destination exists. Verifying... ({str(move_err)})")
                
                try:
                    os.remove(file_path)
                    if is_eperm:
                        add_log("Source file removed. Move considered successful.")
                    else:
                        add_log("Source file removed manually.")
                        
                    # If it was just EPERM, we don't flag as warning in the main report, keep it 'success'
                    if not is_eperm:
                         report["status"] = "warning"
                         
                except Exception as del_err:
                    add_log(f"Critical: Source file could not be removed: {str(del_err)}")
                    report["status"] = "warning"
            else:
                raise move_err
        
        report["final_path"] = final_path
        report["final_name"] = final_name
        
        report["new_path"] = final_path
        
    except Exception as e:
        report["status"] = "error"
        if isinstance(e, PermissionError) or getattr(e, "errno", None) == 13:
            report["error"] = (
                f"Permission denied: {file_path} is not writable by the application user "
                f"(www-data, UID 1000). Fix ownership on the host with: "
                f"sudo chown -R 1000:1000 <downloads directory>."
            )
        else:
            report["error"] = str(e)

    return report

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--library", required=True)
    parser.add_argument("--mode", default="ai")
    parser.add_argument("--mapping", default="{}")
    parser.add_argument("--ai-endpoint", default="")
    parser.add_argument("--ai-key", default="")
    parser.add_argument("--ai-model", default="")
    parser.add_argument("--ai-referer", default="")
    parser.add_argument("--ai-title", default="")
    parser.add_argument("--ai-prompt", default="")
    parser.add_argument("--grok-endpoint", default="https://api.x.ai/v1/chat/completions")
    parser.add_argument("--grok-key", default="")
    parser.add_argument("--grok-model", default="grok-beta")
    parser.add_argument("--grok-prompt", default="")
    parser.add_argument("--force-ai", action="store_true", help="Force AI genre detection, ignoring the existing genre")
    parser.add_argument("--tags-only", action="store_true", help="Only update tags, do not move files")

    args = parser.parse_args()

    if not args.ai_endpoint:
        args.ai_endpoint = args.grok_endpoint
    if not args.ai_key:
        args.ai_key = args.grok_key
    if not args.ai_model:
        args.ai_model = args.grok_model
    if not args.ai_prompt:
        args.ai_prompt = args.grok_prompt

    results = []
    
    if not os.path.exists(args.source):
        print(json.dumps({"error": f"Source path {args.source} not found"}))
        return

    if os.path.isfile(args.source):
        files = [args.source] if args.source.lower().endswith(AUDIO_EXTENSIONS) else []
    else:
        files = [
            os.path.join(args.source, filename)
            for filename in os.listdir(args.source)
            if filename.lower().endswith(AUDIO_EXTENSIONS)
        ]

    config = load_config()
    for file_path in files:
        results.append(process_file(file_path, args.library, args, config))

    print(json.dumps({"results": results}))

if __name__ == "__main__":
    main()