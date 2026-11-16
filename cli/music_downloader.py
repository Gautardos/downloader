import sys
import os
import json
import re
import subprocess

def load_config():
    # Adjust path if needed, assuming cli/ matches project root/cli/
    # and storage is in var/storage/
    base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
    config_path = os.path.join(base_dir, 'var', 'storage', 'config.json')
    
    if os.path.exists(config_path):
        with open(config_path, 'r') as f:
            return json.load(f)
    return {}

NUMERIC_TAGS = {'year', 'tracknumber', 'discnumber', 'totaltracks', 'totaldiscs'}


def lyrics_are_synced(lyrics):
    if not lyrics:
        return False
    for line in str(lyrics).splitlines():
        if re.match(r'^\s*(?:\[\d{1,3}:\d{2}(?:[.:]\d{1,3})?\]|<\d{1,3}:\d{2}(?:[.:]\d{1,3})?>)', line):
            return True
    return False


def safe_tag(handle, name):
    try:
        value = handle[name]
        return '' if value is None else str(value)
    except Exception:
        return ''


def verify_directory(directory, recursive=False):
    import music_tag
    results = []
    if not os.path.isdir(directory):
        return results

    audio_extensions = ('.mp3', '.flac', '.m4a', '.opus', '.ogg', '.wav')
    
    walker = os.walk(directory) if recursive else [(directory, [], os.listdir(directory))]
    
    for root, _, files_in_dir in walker:
        for filename in files_in_dir:
            if filename.lower().endswith(audio_extensions):
                file_path = os.path.join(root, filename)
                try:
                    f = music_tag.load_file(file_path)
                    
                    # Check for lyrics within ID3 tags
                    lyrics = safe_tag(f, 'lyrics').strip()
                    has_lyrics = len(lyrics) > 0 and lyrics.lower() != 'none'
                    lyrics_synced = has_lyrics and lyrics_are_synced(lyrics)

                    # Normalize artist delimiters
                    artist = safe_tag(f, 'artist')
                    artist = artist.replace(' / ', ', ').replace(' /', ', ').replace('/ ', ', ').replace('/', ', ')
                    artist = artist.replace('; ', ', ').replace(';', ', ')

                    results.append({
                        'artist': artist,
                        'album': safe_tag(f, 'album'),
                        'albumartist': safe_tag(f, 'albumartist'),
                        'song_name': safe_tag(f, 'title'),
                        'filename': filename,
                        'rel_path': os.path.relpath(file_path, directory),
                        'full_path': file_path,
                        'size': os.path.getsize(file_path),
                        'mtime': os.path.getmtime(file_path),
                        'genre': safe_tag(f, 'genre'),
                        'year': safe_tag(f, 'year'),
                        'tracknumber': safe_tag(f, 'tracknumber'),
                        'discnumber': safe_tag(f, 'discnumber'),
                        'comment': safe_tag(f, 'comment'),
                        'lyrics': has_lyrics,
                        'lyrics_synced': lyrics_synced
                    })
                except Exception as e:
                    continue
    
    return results

def main():
    config = load_config()
    music_binary = config.get('music_binary', 'zotify')
    
    # Check for verification mode
    if len(sys.argv) > 2 and sys.argv[1] == '--verify':
        is_recursive = '--recursive' in sys.argv or '-r' in sys.argv
        results = verify_directory(sys.argv[2], recursive=is_recursive)
        print(json.dumps(results))
        return

    # Check for tag update mode
    if len(sys.argv) > 2 and sys.argv[1] == '--update-tags':
        import music_tag
        file_path = sys.argv[2]
        if not os.path.exists(file_path):
            print(json.dumps({'error': 'File not found'}))
            return
        
        try:
            # Parse tags to update from command line
            # Format: --update-tags path --genre "Pop" --title "Song" ...
            f = music_tag.load_file(file_path)
            updated = False
            
            i = 3
            while i < len(sys.argv):
                arg = sys.argv[i]
                if arg.startswith('--') and i + 1 < len(sys.argv):
                    tag_name = arg[2:]
                    tag_value = sys.argv[i+1]
                    if tag_name in NUMERIC_TAGS and tag_value == '':
                        f[tag_name] = None
                    else:
                        f[tag_name] = tag_value
                    updated = True
                    i += 2
                else:
                    i += 1
            
            if updated:
                f.save()
                print(json.dumps({'success': True, 'message': 'Tags updated successfully'}))
            else:
                print(json.dumps({'success': False, 'message': 'No tags provided to update'}))
                
        except Exception as e:
            print(json.dumps({'error': str(e)}))
        return

    # Check for single file tags mode
    if len(sys.argv) > 2 and sys.argv[1] == '--tags':
        import music_tag
        file_path = sys.argv[2]
        if not os.path.exists(file_path):
            print(json.dumps({'error': 'File not found'}))
            return
        try:
            f = music_tag.load_file(file_path)
            # Comprehensive tag extraction
            tags = {
                'title': safe_tag(f, 'title'),
                'artist': safe_tag(f, 'artist'),
                'album': safe_tag(f, 'album'),
                'albumartist': safe_tag(f, 'albumartist'),
                'year': safe_tag(f, 'year'),
                'genre': safe_tag(f, 'genre'),
                'tracknumber': safe_tag(f, 'tracknumber'),
                'totaltracks': safe_tag(f, 'totaltracks'),
                'comment': safe_tag(f, 'comment'),
                'lyrics': safe_tag(f, 'lyrics'),
                'composer': safe_tag(f, 'composer'),
                'discnumber': safe_tag(f, 'discnumber')
            }
            print(json.dumps(tags))
        except Exception as e:
            print(json.dumps({'error': str(e)}))
        return

    # Arguments passed from PHP (excluding the script name itself)
    args = sys.argv[1:]
    
    # Final command
    command = [music_binary] + args
    
    print(f"--- Music Wrapper ---")
    print(f"Executing: {' '.join(command)}")
    print(f"----------------------")
    sys.stdout.flush()

    try:
        # Use Popen without redirection to allow direct inheritance of stdout/stderr.
        # This ensures real-time output without Python-level buffering issues.
        process = subprocess.Popen(command)
        process.wait()
        sys.exit(process.returncode)
    except FileNotFoundError:
        print(f"Error: Command '{music_binary}' not found.")
        sys.exit(1)
    except Exception as e:
        print(f"Error: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    main()
