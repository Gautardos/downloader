import os
import argparse
import json
import re
import shutil
import requests
import mutagen.easyid3
from mutagen.easyid3 import EasyID3
from mutagen.id3 import ID3, TDRC

# In-memory cache for genre detection during the current session
GENRE_CACHE = {}

def get_genre_from_file(file_path):
    """Reads the genre from a specific file if it exists."""
    if not os.path.exists(file_path):
        return None
    try:
        audio = EasyID3(file_path)
        genre = audio.get("genre", [None])[0]
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
        audio = EasyID3(file_path)
        audio_full = ID3(file_path) # Full ID3 for TDRC date support
        
        # Capture metadata before
        old_genre = audio.get("genre", ["Unknown"])[0]
        # Robust date extraction
        date = audio.get("originaldate", [None])[0]
        if not date:
            tdrc = audio_full.get('TDRC')
            date = str(tdrc.text[0]) if tdrc and tdrc.text else "0000"
            
        report["metadata_before"] = {
            "title": audio.get("title", ["Unknown"])[0],
            "artist": audio.get("artist", ["Unknown"])[0],
            "album": audio.get("album", ["Unknown"])[0],
            "genre": old_genre,
            "date": date
        }
        add_log(f"Reading metadata for: {report['original_file']}")
        add_log(f"Old genre found: {old_genre}")
        add_log(f"Date found: {date}")
        
        # Tagging logic & Featuring detection
        # ... (Artist, Title, featuring logic omitted for brevity in thought, but included in full replacement)
        # 1. Determine main artist (prioritize albumartist)
        if "albumartist" in audio:
            main_artist = sanitize_name(audio["albumartist"][0])
            add_log(f"Main artist from albumartist: {main_artist}")
        elif "artist" in audio:
            # Split by comma if multiple artists listed
            artists = [sanitize_name(a.strip()) for a in audio["artist"][0].split(",") if a.strip()]
            main_artist = artists[0] if artists else "Unknown"
            add_log(f"Main artist from first listed artist: {main_artist}")
        else:
            main_artist = "Unknown"
            add_log("No artist found, using Unknown")

        # 2. Extract featuring artists
        featuring_artists = []
        if "artist" in audio:
            artist_str = audio["artist"][0]
            # Some apps use comma, some use semicolon
            all_artists = [sanitize_name(a.strip()) for a in re.split(',|;', artist_str) if a.strip()]
            all_artists = list(dict.fromkeys(all_artists)) # Deduplicate
            featuring_artists = [a for a in all_artists if a != main_artist]
        
        # 3. Update Title with featuring
        original_title = sanitize_name(audio.get("title", ["Untitled"])[0])
        final_title = original_title
        if featuring_artists:
            feat_str = f"(feat. {', '.join(featuring_artists)})"
            if "feat. " not in original_title.lower():
                final_title = f"{original_title} {feat_str}"
                add_log(f"Detected features: {', '.join(featuring_artists)}. Title updated.")
            else:
                add_log(f"Features already in title: {original_title}")
        
        artist = main_artist
        album = sanitize_name(audio.get("album", ["Unknown"])[0])
        title = final_title
        tracknum = str(audio.get("tracknumber", ["1"])[0].split("/")[0]).zfill(2)
        ext = os.path.splitext(file_path)[1].lower()
        
        # Save updated tags back via EasyID3
        audio["artist"] = main_artist
        audio["title"] = final_title
        audio["date"] = date
        
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

        audio["genre"] = new_genre
        audio.save()
        
        # Double-save with full ID3 to ensure TDRC persistence
        audio_full = ID3(file_path)
        audio_full['TDRC'] = TDRC(encoding=3, text=date)
        audio_full.save(file_path)
        add_log(f"Saved tags with genre: {new_genre} (Source: {genre_source}) and date: {date}")
        
        # Move
        if not os.path.exists(artist_dir):
            os.makedirs(artist_dir)
            add_log(f"Created directory: {artist_dir}")

        if os.path.exists(final_path):
            add_log(f"Overwriting existing file: {final_name}")
        shutil.move(file_path, final_path)
        add_log(f"Moved to: {final_path}")
        
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
    
    args = parser.parse_args()
    
    results = []
    
    if not os.path.exists(args.source):
        print(json.dumps({"error": f"Source directory {args.source} not found"}))
        return

    for filename in os.listdir(args.source):
        if filename.lower().endswith('.mp3'):
            file_path = os.path.join(args.source, filename)
            results.append(process_file(file_path, args.library, args))
            
    print(json.dumps({"results": results}))

if __name__ == "__main__":
    main()