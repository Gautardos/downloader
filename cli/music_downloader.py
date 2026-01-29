import sys
import os
import json
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

def verify_directory(directory):
    import music_tag
    results = []
    if not os.path.isdir(directory):
        return results

    audio_extensions = ('.mp3', '.flac', '.m4a', '.opus', '.ogg', '.wav')
    
    # Map of base filename (without extension) to check for lyrics
    files_in_dir = os.listdir(directory)
    
    for filename in files_in_dir:
        if filename.lower().endswith(audio_extensions):
            file_path = os.path.join(directory, filename)
            try:
                f = music_tag.load_file(file_path)
                
                # Check for lyrics within ID3 tags
                lyrics = str(f['lyrics']).strip()
                has_lyrics = len(lyrics) > 0 and lyrics.lower() != 'none'

                # Normalize artist delimiters to match Spotify format (Artist 1, Artist 2)
                artist = str(f['artist'])
                artist = artist.replace(' / ', ', ').replace(' /', ', ').replace('/ ', ', ').replace('/', ', ')
                artist = artist.replace('; ', ', ').replace(';', ', ')

                results.append({
                    'artist': artist,
                    'album': str(f['album']),
                    'song_name': str(f['title']),
                    'filename': filename,
                    'lyrics': has_lyrics
                })
            except Exception as e:
                continue
    
    return results

def main():
    config = load_config()
    music_binary = config.get('music_binary', 'musicdownload')
    
    # Check for verification mode
    if len(sys.argv) > 2 and sys.argv[1] == '--verify':
        results = verify_directory(sys.argv[2])
        print(json.dumps(results))
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
                'title': str(f['title']),
                'artist': str(f['artist']),
                'album': str(f['album']),
                'year': str(f['year']),
                'genre': str(f['genre']),
                'tracknumber': str(f['tracknumber']),
                'totaltracks': str(f['totaltracks']),
                'comment': str(f['comment']),
                'lyrics': str(f['lyrics']),
                'composer': str(f['composer']),
                'discnumber': str(f['discnumber'])
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
