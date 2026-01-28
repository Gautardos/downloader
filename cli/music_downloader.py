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

def main():
    config = load_config()
    music_binary = config.get('music_binary', 'musicdownload')
    
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
