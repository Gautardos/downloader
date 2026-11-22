#!/usr/bin/env python3

import importlib.util
import json
import os
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPT = os.path.join(ROOT, 'cli', 'spotify_user.py')

spec = importlib.util.spec_from_file_location('spotify_user', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

checked = 0


def expect(condition, message):
    global checked
    if not condition:
        raise AssertionError(message)
    checked += 1
    print(f'  ok  {message}')


def make_playlist(index):
    return {
        'id': f'pl{index}',
        'name': f'Playlist {index}',
        'description': '',
        'public': True,
        'collaborative': False,
        'owner': {'display_name': 'Listener'},
        'tracks': {'total': 3},
        'images': [{'url': f'https://img/{index}.jpg'}],
        'external_urls': {'spotify': f'https://open.spotify.com/playlist/pl{index}'},
    }


print('map_playlist')
playlist = module.map_playlist(make_playlist(1))
expect(playlist['id'] == 'pl1', 'map_playlist keeps the id')
expect(playlist['name'] == 'Playlist 1', 'map_playlist keeps the name')
expect(playlist['owner'] == 'Listener', 'map_playlist uses owner display name')
expect(playlist['track_count'] == 3, 'map_playlist maps the track count')
expect(playlist['image'] == 'https://img/1.jpg', 'map_playlist maps the cover')
expect(playlist['collaborative'] is False, 'map_playlist maps collaborative')

minimal = module.map_playlist({'id': 'abc'})
expect(minimal['name'] == 'Playlist', 'map_playlist default name')
expect(minimal['url'] == 'https://open.spotify.com/playlist/abc', 'map_playlist fallback URL')
expect(minimal['image'] is None, 'map_playlist default image')
expect(minimal['track_count'] == 0, 'map_playlist default track count')

print('map_playlist_track')
track = module.map_playlist_track(
    {
        'id': 't1',
        'name': 'Song',
        'duration_ms': 1234,
        'artists': [{'name': 'A'}, {'name': 'B'}],
        'album': {'name': 'Album'},
        'external_urls': {'spotify': 'https://open.spotify.com/track/t1'},
    }
)
expect(track['id'] == 't1', 'map_playlist_track keeps the id')
expect(track['artist'] == 'A, B', 'map_playlist_track joins artists')
expect(track['album'] == 'Album', 'map_playlist_track maps the album')
expect(track['song_name'] == 'Song', 'map_playlist_track maps the title')
expect(track['duration_ms'] == 1234, 'map_playlist_track maps the duration')
expect(track['url'] == 'https://open.spotify.com/track/t1', 'map_playlist_track maps the URL')

fallback_track = module.map_playlist_track({'id': 't2', 'name': 'Song'}, owner='Owner')
expect(fallback_track['artist'] == 'Owner', 'map_playlist_track falls back to the owner')
expect(fallback_track['url'] == 'https://open.spotify.com/track/t2', 'map_playlist_track builds a default URL')
no_id = module.map_playlist_track({'name': 'No ID'})
expect(no_id['url'] == '', 'map_playlist_track leaves an empty URL without id')

print('map_playlist_metadata')
metadata = module.map_playlist_metadata(
    'p1',
    {'name': 'Meta', 'snapshot_id': 'snap-1', 'external_urls': {'spotify': 'https://open.spotify.com/playlist/p1'}},
    [track],
    owner='Owner',
)
expect(metadata['success'] is True, 'map_playlist_metadata reports success')
expect(metadata['playlist']['name'] == 'Meta', 'map_playlist_metadata keeps the name')
expect(metadata['playlist']['owner'] == 'Owner', 'map_playlist_metadata keeps the owner')
expect(metadata['playlist']['snapshot_id'] == 'snap-1', 'map_playlist_metadata keeps the snapshot id')
expect(metadata['playlist']['tracks'] == [track], 'map_playlist_metadata keeps the tracks')
expect(metadata['playlist']['url'] == 'https://open.spotify.com/playlist/p1', 'map_playlist_metadata maps the URL')

print('fetch_playlists')
original_spotify_get = module.spotify_get

try:
    calls = []

    def paginated(_, path, params=None):
        offset = int(params.get('offset', 0))
        limit = int(params.get('limit', 50))
        calls.append((path, offset, limit))
        total = 120
        count = max(0, min(limit, total - offset))
        return {'items': [make_playlist(offset + i) for i in range(count)], 'total': total}

    module.spotify_get = paginated
    result = module.fetch_playlists('token', '/me/playlists')
    expect(result['success'] is True, 'fetch_playlists reports success')
    expect(result['total'] == 120, 'fetch_playlists keeps the total')
    expect(len(result['playlists']) == 120, 'fetch_playlists collects every page')
    expect([call[1] for call in calls] == [0, 50, 100], 'fetch_playlists follows offsets')

    calls.clear()

    def exact_total(_, path, params=None):
        offset = int(params.get('offset', 0))
        limit = int(params.get('limit', 50))
        calls.append(offset)
        count = limit if offset == 0 else 0
        return {'items': [make_playlist(i) for i in range(count)], 'total': 50}

    module.spotify_get = exact_total
    result = module.fetch_playlists('token', '/me/playlists')
    expect(len(result['playlists']) == 50, 'fetch_playlists stops at total')
    expect(calls == [0], 'fetch_playlists does not request past total')

    calls.clear()

    def short_page(_, path, params=None):
        offset = int(params.get('offset', 0))
        calls.append(offset)
        count = 30 if offset == 0 else 50
        return {'items': [make_playlist(i) for i in range(count)], 'total': 200}

    module.spotify_get = short_page
    result = module.fetch_playlists('token', '/me/playlists')
    expect(len(result['playlists']) == 30, 'fetch_playlists stops on a short page')
    expect(calls == [0], 'fetch_playlists does not continue after a short page')

    print('get_playlist_metadata')
    requests_seen = []

    def playlist_get(_, path, params=None):
        requests_seen.append((path, params or {}))
        if path == '/playlists/p1':
            return {
                'id': 'p1',
                'name': 'Private Mix',
                'owner': {'display_name': 'Listener'},
                'snapshot_id': 'snap-42',
                'external_urls': {'spotify': 'https://open.spotify.com/playlist/p1'},
            }
        if path == '/playlists/p1/tracks':
            offset = int((params or {}).get('offset', 0))
            if offset == 0:
                items = [
                    {'track': {'id': 't1', 'name': 'One', 'artists': [{'name': 'A'}], 'album': {'name': 'Al'}}},
                    {'track': {'id': 'local', 'name': 'Local', 'is_local': True}},
                    {'track': {'id': 'noname'}},
                    {'item': {'id': 't2', 'name': 'Two', 'artists': [{'name': 'B'}], 'album': {'name': 'Al'}}},
                ] + [{'track': {'id': f'x{i}', 'name': f'X{i}'}} for i in range(96)]
                return {'items': items, 'total': 102}
            return {'items': [{'track': {'id': 't3', 'name': 'Three'}}], 'total': 102}
        raise AssertionError(f'unexpected path {path}')

    module.spotify_get = playlist_get
    result = module.get_playlist_metadata('token', 'p1')
    expect(result['success'] is True, 'get_playlist_metadata reports success')
    expect(result['playlist']['snapshot_id'] == 'snap-42', 'get_playlist_metadata keeps snapshot_id')
    names = [track['song_name'] for track in result['playlist']['tracks']]
    expect('Local' not in names, 'get_playlist_metadata filters local tracks')
    expect('' not in names, 'get_playlist_metadata filters nameless tracks')
    expect(names[0] == 'One' and names[1] == 'Two', 'get_playlist_metadata supports track and item keys')
    expect('Three' in names, 'get_playlist_metadata follows playlist pagination')
    expect(len(requests_seen) == 3, 'get_playlist_metadata requests info and both track pages')

    print('fallback list_playlists')
    with tempfile.TemporaryDirectory() as tmp:
        creds = os.path.join(tmp, 'credentials.json')
        with open(creds, 'w', encoding='utf-8') as handle:
            json.dump({'username': 'listener'}, handle)

        module.create_session = lambda path: object()
        module.get_user_token = lambda session: 'user-token'
        module.get_client_token = lambda config: 'client-token'

        for status in (401, 403, 429):
            def fallback_get(token, path, params=None, status=status):
                if token == 'user-token':
                    raise module.SpotifyApiError(status, f'HTTP {status}')
                if path == '/users/listener/playlists':
                    return {'items': [make_playlist(1)], 'total': 1}
                raise AssertionError(f'unexpected path {path}')

            module.spotify_get = fallback_get
            result = module.list_playlists({}, creds)
            expect(result.get('partial') is True, f'list_playlists marks partial on {status}')
            expect(bool(result.get('warning')), f'list_playlists warns on {status}')
            expect(len(result['playlists']) == 1, f'list_playlists falls back to public playlists on {status}')

        def hard_failure(token, path, params=None):
            raise module.SpotifyApiError(500, 'server exploded')

        module.spotify_get = hard_failure
        try:
            module.list_playlists({}, creds)
            raise AssertionError('list_playlists should re-raise a non 401/403/429 error')
        except module.SpotifyApiError as exc:
            expect(exc.status == 500, 'list_playlists propagates non auth errors')

        print('fallback get_playlist_metadata_fallback')
        for status in (401, 403, 429):
            def metadata_get(token, path, params=None, status=status):
                if token == 'user-token':
                    raise module.SpotifyApiError(status, f'HTTP {status}')
                if path == '/playlists/p1':
                    return {'id': 'p1', 'name': 'Public Mix', 'snapshot_id': 's1'}
                if path == '/playlists/p1/tracks':
                    return {'items': [{'track': {'id': 't1', 'name': 'One'}}], 'total': 1}
                raise AssertionError(f'unexpected path {path}')

            module.spotify_get = metadata_get
            result = module.get_playlist_metadata_fallback({}, creds, 'p1')
            expect(result.get('partial') is True, f'metadata fallback marks partial on {status}')
            expect(bool(result.get('warning')), f'metadata fallback warns on {status}')
            expect(result['playlist']['name'] == 'Public Mix', f'metadata fallback uses public data on {status}')

        def metadata_hard_failure(token, path, params=None):
            raise module.SpotifyApiError(500, 'server exploded')

        module.spotify_get = metadata_hard_failure
        try:
            module.get_playlist_metadata_fallback({}, creds, 'p1')
            raise AssertionError('metadata fallback should re-raise a non 401/403/429 error')
        except module.SpotifyApiError as exc:
            expect(exc.status == 500, 'metadata fallback propagates non auth errors')
finally:
    module.spotify_get = original_spotify_get

print(f'\n{checked} checks passed.')
