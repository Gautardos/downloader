#!/usr/bin/env python3

import importlib.util
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPT = os.path.join(ROOT, 'cli', 'music_downloader.py')

spec = importlib.util.spec_from_file_location('music_downloader', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

try:
    import music_tag  # noqa: F401

    HAS_MUSIC_TAG = True
except Exception:
    HAS_MUSIC_TAG = False

HAS_FFMPEG = shutil.which('ffmpeg') is not None

checked = 0


def expect(condition, message):
    global checked
    if not condition:
        raise AssertionError(message)
    checked += 1
    print(f'  ok  {message}')


def skip(message):
    print(f'  skip {message}')


def run_script(path, args, env=None):
    return subprocess.run(
        [sys.executable, path] + args,
        capture_output=True,
        text=True,
        env=env,
    )


def make_tagged_mp3(path):
    subprocess.run(
        [
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=44100:cl=mono',
            '-t',
            '0.4',
            '-q:a',
            '9',
            '-metadata',
            'artist=A / B',
            '-metadata',
            'album=Album',
            '-metadata',
            'title=Title',
            path,
        ],
        check=True,
    )


print('lyrics_are_synced / safe_tag')
expect(module.lyrics_are_synced('[00:01.00] Hello world\n[00:02.00] Again') is True, 'LRC timestamps are synced')
expect(module.lyrics_are_synced('<00:01.20> Hello world') is True, 'enhanced LRC timestamps are synced')
expect(module.lyrics_are_synced('Just a plain line\nAnother line') is False, 'plain lyrics are not synced')
expect(module.lyrics_are_synced(None) is False, 'missing lyrics are not synced')
expect(module.lyrics_are_synced('') is False, 'empty lyrics are not synced')

expect(module.safe_tag({'artist': 'Name'}, 'artist') == 'Name', 'safe_tag reads a plain value')
expect(module.safe_tag({'artist': None}, 'artist') == '', 'safe_tag converts None to empty string')
expect(module.safe_tag({'artist': 42}, 'artist') == '42', 'safe_tag stringifies values')
expect(module.safe_tag({}, 'artist') == '', 'safe_tag returns empty for missing keys')


class ExplodingTag:
    def __getitem__(self, key):
        raise RuntimeError('boom')


expect(module.safe_tag(ExplodingTag(), 'artist') == '', 'safe_tag swallows tag errors')

print('--verify')
with tempfile.TemporaryDirectory() as empty_dir:
    result = run_script(SCRIPT, ['--verify', empty_dir])
    expect(result.returncode == 0, '--verify on an empty directory exits 0')
    expect(json.loads(result.stdout) == [], '--verify on an empty directory returns []')

with tempfile.TemporaryDirectory() as missing_dir:
    result = run_script(SCRIPT, ['--verify', os.path.join(missing_dir, 'nope')])
    expect(json.loads(result.stdout) == [], '--verify on a missing directory returns []')

if not HAS_FFMPEG or not HAS_MUSIC_TAG:
    skip('tagged MP3 checks require ffmpeg and music_tag')
else:
    with tempfile.TemporaryDirectory() as music_dir:
        mp3_path = os.path.join(music_dir, 'track.mp3')
        make_tagged_mp3(mp3_path)

        result = run_script(SCRIPT, ['--verify', music_dir])
        expect(result.returncode == 0, '--verify with an MP3 exits 0')
        entries = json.loads(result.stdout)
        if not entries:
            skip('music_tag could not read the generated MP3')
        else:
            entry = entries[0]
            expect(entry['artist'] == 'A, B', 'verified artist separators are normalized')
            expect(entry['album'] == 'Album', 'verified album is read from tags')
            expect(entry['song_name'] == 'Title', 'verified title is read from tags')
            expect(entry['lyrics'] is False, 'verified lyrics flag is false without lyrics')
            expect(entry['lyrics_synced'] is False, 'verified lyrics_synced flag is false without lyrics')

        print('--tags / --update-tags')
        result = run_script(SCRIPT, ['--update-tags', mp3_path, '--genre', 'Pop', '--year', '2020'])
        expect(result.returncode == 0, '--update-tags exits 0')
        expect(json.loads(result.stdout).get('success') is True, '--update-tags reports success')

        result = run_script(SCRIPT, ['--tags', mp3_path])
        tags = json.loads(result.stdout)
        expect(tags.get('genre') == 'Pop', '--tags reads the updated genre')
        expect(str(tags.get('year')) == '2020', '--tags reads the updated numeric tag')
        expect(tags.get('title') == 'Title', '--tags reads existing tags')

        result = run_script(SCRIPT, ['--update-tags', mp3_path, '--year', ''])
        expect(result.returncode == 0, 'clearing a numeric tag exits 0')
        result = run_script(SCRIPT, ['--tags', mp3_path])
        tags = json.loads(result.stdout)
        expect(str(tags.get('year')) in ('', '0', 'None'), 'clearing a numeric tag empties it', )
        expect(tags.get('genre') == 'Pop', 'clearing one tag keeps the others')

        result = run_script(SCRIPT, ['--tags', os.path.join(music_dir, 'absent.mp3')])
        expect(json.loads(result.stdout).get('error') == 'File not found', '--tags reports a missing file')

print('wrapper')

with tempfile.TemporaryDirectory() as project:
    cli_dir = os.path.join(project, 'cli')
    storage_dir = os.path.join(project, 'var', 'storage')
    fake_dir = os.path.join(project, 'fake bin')
    os.makedirs(cli_dir)
    os.makedirs(storage_dir)
    os.makedirs(fake_dir)

    wrapper = os.path.join(cli_dir, 'music_downloader.py')
    shutil.copyfile(SCRIPT, wrapper)

    captured = os.path.join(project, 'captured.json')
    fake_binary = os.path.join(fake_dir, 'fake-zotify')
    with open(fake_binary, 'w', encoding='utf-8') as handle:
        handle.write(
            '#!/usr/bin/env python3\n'
            'import json, os, sys\n'
            f'with open({captured!r}, "w", encoding="utf-8") as out:\n'
            '    json.dump({"args": sys.argv[1:], "cwd": os.getcwd(), "binary": sys.argv[0]}, out)\n'
            'sys.exit(int(os.environ.get("FAKE_EXIT_CODE", "0")))\n'
        )
    os.chmod(fake_binary, stat.S_IRWXU)

    config_path = os.path.join(storage_dir, 'config.json')
    with open(config_path, 'w', encoding='utf-8') as handle:
        json.dump({'music_binary': fake_binary}, handle)

    marker = os.path.join(project, 'injected-marker')
    tricky_args = [
        '--output',
        '{artist} - {song_name}.{ext}',
        '--skip-previously-downloaded',
        'True',
        'value with spaces',
        f'quote"; touch {marker}; echo `id`',
    ]
    env = dict(os.environ, FAKE_EXIT_CODE='3')
    result = run_script(wrapper, tricky_args, env=env)
    expect(result.returncode == 3, 'wrapper propagates the child exit code')
    expect('--- Music Wrapper ---' in result.stdout, 'wrapper prints its banner')

    with open(captured, encoding='utf-8') as handle:
        child = json.load(handle)
    expect(child['args'] == tricky_args, 'wrapper forwards arguments exactly')
    expect(os.path.realpath(child['binary']) == os.path.realpath(fake_binary), 'wrapper executes the configured binary')
    expect(not os.path.exists(marker), 'wrapper does not interpret shell metacharacters')

    with open(config_path, 'w', encoding='utf-8') as handle:
        json.dump({'music_binary': os.path.join(project, 'does-not-exist')}, handle)

    result = run_script(wrapper, ['--anything'])
    expect(result.returncode == 1, 'wrapper exits 1 when the binary is missing')
    expect('not found' in result.stdout.lower(), 'wrapper reports a missing binary')

print(f'\n{checked} checks passed.')
