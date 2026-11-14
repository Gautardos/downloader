#!/usr/bin/env python3
"""Spotify user API helper.

Uses the same stored librespot credentials as Zotify to obtain a user token
with playlist scopes. This makes private and followed playlists available
without an OAuth application flow.
"""

import argparse
import json
import os
import sys
from urllib.parse import quote

import requests
from librespot.core import Session

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CONFIG_PATH = os.path.join(BASE_DIR, 'var', 'storage', 'config.json')
DEFAULT_CREDS = '/var/www/html/var/home/.local/credentials.json'
SPOTIFY_API_URL = 'https://api.spotify.com/v1'
SPOTIFY_SCOPES = (
    'playlist-read-private',
    'playlist-read-collaborative',
    'user-library-read',
    'user-follow-read',
    'user-read-email',
)


class SpotifyApiError(RuntimeError):
    def __init__(self, status, message, retry_after=None):
        super().__init__(message)
        self.status = status
        self.retry_after = retry_after


def load_config(path=None):
    try:
        with open(path or CONFIG_PATH, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def get_credentials_path(config):
    path = str(config.get('music_creds') or DEFAULT_CREDS).strip()
    return path or DEFAULT_CREDS


def create_session(credentials_path):
    if not os.path.isfile(credentials_path):
        raise RuntimeError(f'Spotify credentials file not found: {credentials_path}')

    configuration = Session.Configuration.Builder().set_store_credentials(False).build()
    return Session.Builder(configuration).stored_file(credentials_path).create()


def get_user_token(session):
    return session.tokens().get_token(*SPOTIFY_SCOPES).access_token


def get_client_token(config):
    client_id = str(config.get('spotify_client_id') or '').strip()
    client_secret = str(config.get('spotify_client_secret') or '').strip()
    if not client_id or not client_secret:
        raise RuntimeError('Spotify Client ID or Client Secret is missing.')

    response = requests.post(
        'https://accounts.spotify.com/api/token',
        auth=(client_id, client_secret),
        data={'grant_type': 'client_credentials'},
        timeout=30,
    )
    if response.status_code != 200:
        raise RuntimeError(f'Spotify authentication failed with HTTP {response.status_code}.')

    return response.json().get('access_token')


def get_username(credentials_path):
    try:
        with open(credentials_path, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
            return str(data.get('username') or '').strip() or None
    except Exception:
        return None


def spotify_get(token, path, params=None):
    url = path if path.startswith('http') else SPOTIFY_API_URL + path
    headers = {
        'Authorization': f'Bearer {token}',
        'Accept': 'application/json',
        'app-platform': 'WebPlayer',
    }

    response = requests.get(url, headers=headers, params=params or {}, timeout=30)

    if response.status_code == 429:
        retry_after = response.headers.get('Retry-After')
        try:
            retry_after = int(retry_after) if retry_after is not None else None
        except ValueError:
            retry_after = None
        raise SpotifyApiError(429, 'Spotify API rate limit exceeded.', retry_after)

    if response.status_code != 200:
        message = f'Spotify API returned HTTP {response.status_code}.'
        try:
            payload = response.json()
            error = payload.get('error') or {}
            if isinstance(error, dict) and error.get('message'):
                message = str(error['message'])
        except Exception:
            pass
        raise SpotifyApiError(response.status_code, message)

    return response.json()


def map_playlist(playlist):
    playlist_id = str(playlist.get('id') or '')
    owner = playlist.get('owner') or {}
    tracks = playlist.get('tracks') or {}
    images = playlist.get('images') or []
    external_urls = playlist.get('external_urls') or {}

    return {
        'id': playlist_id,
        'name': playlist.get('name') or 'Playlist',
        'description': playlist.get('description') or '',
        'public': playlist.get('public'),
        'collaborative': bool(playlist.get('collaborative')),
        'owner': owner.get('display_name') or owner.get('id') or '',
        'track_count': int(tracks.get('total') or 0),
        'image': images[0].get('url') if images else None,
        'url': external_urls.get('spotify') or f'https://open.spotify.com/playlist/{playlist_id}',
    }


def fetch_playlists(token, path):
    playlists = []
    offset = 0
    limit = 50
    total = None

    while True:
        data = spotify_get(token, path, {'limit': limit, 'offset': offset})
        batch = data.get('items') or []
        playlists.extend(map_playlist(item) for item in batch if item)

        total = data.get('total', total)
        offset += len(batch)

        if not batch or len(batch) < limit:
            break
        if total is not None and offset >= total:
            break

    return {
        'success': True,
        'total': int(total) if total is not None else len(playlists),
        'playlists': playlists,
    }


def list_playlists(config, credentials_path):
    try:
        session = create_session(credentials_path)
        token = get_user_token(session)
        return fetch_playlists(token, '/me/playlists')
    except Exception as exc:
        if isinstance(exc, SpotifyApiError) and exc.status not in (401, 403, 429):
            raise

        username = get_username(credentials_path)
        if not username:
            raise

        try:
            client_token = get_client_token(config)
            result = fetch_playlists(client_token, f'/users/{quote(username, safe="")}/playlists')
        except Exception:
            raise exc

        result['partial'] = True
        result['warning'] = 'Spotify limite temporairement l’accès aux playlists privées. Liste publique affichée.'
        return result


def get_playlist_metadata_fallback(config, credentials_path, playlist_id):
    try:
        session = create_session(credentials_path)
        return get_playlist_metadata(get_user_token(session), playlist_id)
    except Exception as exc:
        if isinstance(exc, SpotifyApiError) and exc.status not in (401, 403, 429):
            raise

        try:
            result = get_playlist_metadata(get_client_token(config), playlist_id)
        except Exception:
            raise exc

        result['partial'] = True
        result['warning'] = 'Métadonnées récupérées avec un accès public uniquement.'
        return result


def get_playlist_metadata(token, playlist_id):
    encoded_id = quote(playlist_id, safe='')
    info = spotify_get(
        token,
        f'/playlists/{encoded_id}',
        {'fields': 'id,name,owner(display_name),tracks(total)'},
    )

    name = info.get('name') or 'Playlist'
    owner = (info.get('owner') or {}).get('display_name') or ''
    tracks = []
    offset = 0
    limit = 100

    while True:
        data = spotify_get(
            token,
            f'/playlists/{encoded_id}/tracks',
            {'limit': limit, 'offset': offset},
        )
        batch = data.get('items') or []

        for item in batch:
            track = (item or {}).get('track') or (item or {}).get('item') or {}
            if not track or track.get('is_local') or not track.get('name'):
                continue

            artists = [
                str(artist.get('name'))
                for artist in (track.get('artists') or [])
                if artist and artist.get('name')
            ]
            album = (track.get('album') or {}).get('name') or ''

            tracks.append({
                'artist': ', '.join(artists) or owner,
                'album': album,
                'song_name': track.get('name'),
            })

        offset += len(batch)
        if not batch or len(batch) < limit:
            break

    return {
        'success': True,
        'playlist': {
            'id': playlist_id,
            'name': name,
            'owner': owner,
            'tracks': tracks,
        },
    }


def build_parser():
    parser = argparse.ArgumentParser(description='Spotify user playlists helper')
    parser.add_argument('--list-playlists', action='store_true', help='List the user playlists')
    parser.add_argument('--playlist', metavar='ID', help='Get a playlist metadata and tracks')
    parser.add_argument('--config', metavar='PATH', help='Path to config.json')
    return parser


def main():
    parser = build_parser()
    args = parser.parse_args()

    if not args.list_playlists and not args.playlist:
        parser.error('one of --list-playlists or --playlist is required')

    try:
        config = load_config(args.config)
        credentials_path = get_credentials_path(config)

        if args.list_playlists:
            result = list_playlists(config, credentials_path)
        else:
            result = get_playlist_metadata_fallback(config, credentials_path, str(args.playlist))

        sys.stdout.write(json.dumps(result, ensure_ascii=False))
        return 0
    except SpotifyApiError as exc:
        error = {
            'success': False,
            'error': str(exc),
            'status': exc.status,
            'retry_after': exc.retry_after,
        }
        sys.stdout.write(json.dumps(error, ensure_ascii=False))
        print(str(exc), file=sys.stderr)
        return 1
    except Exception as exc:
        error = {'success': False, 'error': str(exc)}
        sys.stdout.write(json.dumps(error, ensure_ascii=False))
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
