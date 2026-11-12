import os
import argparse
import json
import re
import shutil
import requests
import music_tag

# In-memory cache for genre detection during the current session
GENRE_CACHE = {}
AUDIO_EXTENSIONS = ('.mp3', '.flac', '.m4a', '.opus', '.ogg')

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

def detect_genre_with_ai(artist, album, api_key, endpoint, model, prompt):
    if not api_key:
        return "Unknown"
    
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json"
    }
    
    # Default prompt if none provided
    system_prompt = prompt or """
    Tu es un expert en classification musicale. Détermine le genre musical de cet album/artiste.
    Réponds UNIQUEMENT par le nom du genre (ex: Pop, Rock, Rap & Hip-Hop).
    """
    
    messages = [
        {"role": "system", "content": system_prompt},
        {"role": "user", "content": f"Artiste: {artist}, Album: {album}"}
    ]
    
    payload = {
        "model": model,
        "messages": messages,
        "max_tokens": 5000,
        "temperature": 0.3
    }
    
    try:
        response = requests.post(endpoint, headers=headers, json=payload, timeout=15)
        response.raise_for_status()
        return response.json().get("choices", [{}])[0].get("message", {}).get("content", "Unknown").strip()
    except:
        return "Unknown"

def process_file(file_path, library_path, args):
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
                cache_key = (artist, album)
                if cache_key in GENRE_CACHE:
                    new_genre = GENRE_CACHE[cache_key]
                    genre_source = "Session Cache"
                    add_log(f"Detected previous detection for this album in cache: {new_genre}")
                # C. Final fallback: AI Detection
                elif args.mode == 'ai':
                    add_log(f"No genre found. Querying Grok for {artist} - {album}...")
                    new_genre = detect_genre_with_ai(artist, album, args.grok_key, args.grok_endpoint, args.grok_model, args.grok_prompt)
                    if new_genre and new_genre.lower() not in ["", "unknown", "none"]:
                        GENRE_CACHE[cache_key] = new_genre
                        genre_source = "AI (Grok)"
                        add_log(f"Grok detected genre: {new_genre}")
                    else:
                        add_log("Grok could not determine a genre.")

        audio["artist"] = main_artist
        audio["tracktitle"] = final_title
        audio["album"] = album
        audio["genre"] = new_genre
        if date and date != "0000":
            audio["year"] = date
        audio.save()
        add_log(f"Saved tags with genre: {new_genre} (Source: {genre_source}) and date: {date}")
        
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
        
        # Flat keys for UI summary
        report["artist"] = artist
        report["title"] = title
        report["genre"] = new_genre
        report["genre_source"] = genre_source
        report["old_genre"] = old_genre
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
    parser.add_argument("--grok-key", default="")
    parser.add_argument("--grok-endpoint", default="https://api.x.ai/v1/chat/completions")
    parser.add_argument("--grok-model", default="grok-beta")
    parser.add_argument("--grok-prompt", default="")
    parser.add_argument("--tags-only", action="store_true", help="Only update tags, do not move files")
    
    args = parser.parse_args()
    
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

    for file_path in files:
        results.append(process_file(file_path, args.library, args))

    print(json.dumps({"results": results}))

if __name__ == "__main__":
    main()