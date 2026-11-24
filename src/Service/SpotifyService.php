<?php

namespace App\Service;

use App\Service\Spotify\LibrespotTokenProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class SpotifyService
{
    private const SEARCH_CACHE_TTL = 45;
    private const LIBRESPOT_TOKEN_TTL = 300;

    private ?string $accessToken = null;
    private ?string $tokenSource = null;
    private ?int $tokenExpiresAt = null;
    private ?string $appToken = null;
    private ?int $appTokenExpiresAt = null;
    private array $logs = [];
    private ?string $lastError = null;

    public function __construct(
        private JsonStorage $storage,
        private LibrespotTokenProvider $librespot
    ) {
    }

    private function addLog(string $message): void
    {
        $this->logs[] = "[" . date('H:i:s') . "] " . $message;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function testCredentials(): bool
    {
        return $this->getAccessToken() !== null;
    }

    private function getAccessToken(): ?string
    {
        if ($this->accessToken && $this->tokenExpiresAt !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $config = $this->storage->get('config', []);
        $mode = $this->authMode($config);

        if ($mode === 'client_credentials') {
            $appToken = $this->getAppToken($config);
            if ($appToken) {
                $this->rememberToken($appToken, 'app');
                return $appToken;
            }
            $this->addLog('App-only mode: no Spotify app credentials available.');
            return null;
        }

        if ($mode === 'librespot') {
            $token = $this->librespot->getToken();
            if ($token) {
                $this->rememberToken($token, 'librespot');
                $this->addLog('Using Spotify user token (librespot).');
                return $token;
            }
        }

        $appToken = $this->getAppToken($config);
        if ($appToken) {
            $this->rememberToken($appToken, 'app');
            return $appToken;
        }

        $token = $this->librespot->getToken();
        if ($token) {
            $this->rememberToken($token, 'librespot');
            $this->addLog('Using Spotify user token (librespot).');
            return $token;
        }

        $this->addLog('No Spotify access token available (app or librespot).');
        return null;
    }

    private function getAppToken(array $config): ?string
    {
        if ($this->appToken && $this->appTokenExpiresAt !== null && time() < $this->appTokenExpiresAt) {
            return $this->appToken;
        }

        $clientId = trim((string) ($config['spotify_client_id'] ?? ''));
        $clientSecret = trim((string) ($config['spotify_client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $this->addLog('Authenticating with Spotify app (client credentials)...');

        $client = new Client();
        try {
            $response = $client->post('https://accounts.spotify.com/api/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return null;
            }
            $this->appToken = $token;
            $this->appTokenExpiresAt = time() + ((int) ($data['expires_in'] ?? 0)) - 60;

            return $this->appToken;
        } catch (\Exception $e) {
            $this->addLog("App authentication failed: " . $e->getMessage());
            return null;
        }
    }

    private function rememberToken(string $token, string $source): void
    {
        $this->accessToken = $token;
        $this->tokenSource = $source;
        $this->tokenExpiresAt = time() + self::LIBRESPOT_TOKEN_TTL;
    }

    private function authMode(array $config): string
    {
        $mode = strtolower(trim((string) ($config['spotify_auth_mode'] ?? 'auto')));
        return in_array($mode, ['auto', 'client_credentials', 'librespot'], true) ? $mode : 'auto';
    }

    private function makeClient(string $token): Client
    {
        return new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'app-platform' => 'WebPlayer',
            ],
        ]);
    }

    /**
     * Inspects a failed request. On a 401/403/429 with the app token it swaps to
     * the librespot user token and returns a new client to retry once. Returns
     * null when no retry should be attempted.
     */
    private function retryClientAfterFailure(\Exception $error, ?string $failedToken): ?Client
    {
        $status = null;
        $retryAfter = null;
        if ($error instanceof RequestException && $error->hasResponse()) {
            $response = $error->getResponse();
            $status = $response->getStatusCode();
            $header = trim($response->getHeaderLine('Retry-After'));
            $retryAfter = $header !== '' ? (int) $header : null;
        }

        if ($this->tokenSource === 'librespot') {
            if ($status === 429) {
                $this->librespot->recordCooldown($retryAfter, $status, $error->getMessage());
                $this->addLog('Librespot token rate limited; cooldown recorded.');
            }
            return null;
        }

        if (in_array($status, [401, 403, 429], true)) {
            $fallback = $this->librespot->getToken();
            if ($fallback !== null && $fallback !== $failedToken) {
                $this->rememberToken($fallback, 'librespot');
                $this->addLog('App token failed (HTTP ' . $status . '); retrying with librespot token.');
                return $this->makeClient($fallback);
            }
        }

        return null;
    }

    public function getMetadata(string $url): ?array
    {
        $this->logs = [];
        $this->addLog("Extracting metadata for: $url");
        $parsed = $this->parseUrl($url);
        if (!$parsed) {
            $this->addLog("URL pattern not recognized. Expected track, album, or playlist.");
            return null;
        }

        $this->addLog("Detected type: " . $parsed['type'] . ", ID: " . $parsed['id']);

        $token = $this->getAccessToken();
        if (!$token) {
            $this->addLog("Failed to get Spotify access token.");
            return null;
        }

        try {
            return $this->dispatchMetadata($this->makeClient($token), $parsed);
        } catch (\Exception $e) {
            $retryClient = $this->retryClientAfterFailure($e, $token);
            if ($retryClient !== null) {
                try {
                    return $this->dispatchMetadata($retryClient, $parsed);
                } catch (\Exception $retryError) {
                    $this->retryClientAfterFailure($retryError, $this->accessToken);
                    $this->addLog("API call failed: " . $retryError->getMessage());
                    return null;
                }
            }
            $this->addLog("API call failed: " . $e->getMessage());
        }

        return null;
    }

    private function dispatchMetadata(Client $client, array $parsed): array
    {
        switch ($parsed['type']) {
            case 'track':
                return $this->getTrackMetadata($client, $parsed['id']);
            case 'album':
                return $this->getAlbumMetadata($client, $parsed['id']);
            case 'playlist':
                return $this->getPlaylistMetadata($client, $parsed['id']);
        }

        throw new \InvalidArgumentException('Unsupported Spotify type: ' . ($parsed['type'] ?? 'unknown'));
    }

    public function isConfigured(): bool
    {
        $config = $this->storage->get('config', []);

        $hasApp = trim((string) ($config['spotify_client_id'] ?? '')) !== ''
            && trim((string) ($config['spotify_client_secret'] ?? '')) !== '';

        $creds = (string) ($config['music_creds'] ?? '');

        return $hasApp || ($creds !== '' && file_exists($creds));
    }

    public function search(string $query, string $type = 'all', int $limit = 15): ?array
    {
        return $this->performSearch($query, $type, $limit, 15);
    }

    /**
     * Bounded search used by the global palette: fewer results and a short timeout.
     */
    public function searchQuick(string $query, int $limit = 8, int $timeout = 4): ?array
    {
        return $this->performSearch($query, 'all', $limit, $timeout);
    }

    private function performSearch(string $query, string $type, int $limit, int $timeout): ?array
    {
        $this->logs = [];
        $this->lastError = null;
        $query = trim($query);
        if ($query === '') {
            $this->lastError = 'Empty search query.';
            return null;
        }

        $validTypes = ['track', 'album', 'playlist'];
        $types = in_array($type, $validTypes, true) ? [$type] : $validTypes;
        $limit = max(1, min($limit, 50));

        $cacheFile = $this->searchCacheFile($query, $type, $limit);
        $cached = $this->readSearchCache($cacheFile);
        if ($cached !== null) {
            $this->addLog('Search cache hit: ' . $query);
            return $cached;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->lastError = 'Failed to get Spotify access token.';
            return null;
        }

        $this->addLog('Searching Spotify for: ' . $query);

        try {
            $results = $this->runSearch($this->makeClient($token), $query, $types, $limit, $timeout);
        } catch (\Exception $e) {
            $retryClient = $this->retryClientAfterFailure($e, $token);
            if ($retryClient !== null) {
                try {
                    $results = $this->runSearch($retryClient, $query, $types, $limit, $timeout);
                } catch (\Exception $retryError) {
                    $this->retryClientAfterFailure($retryError, $this->accessToken);
                    $this->lastError = $retryError->getMessage();
                    $this->addLog('Search failed: ' . $retryError->getMessage());
                    return null;
                }
            } else {
                $this->lastError = $e->getMessage();
                $this->addLog('Search failed: ' . $e->getMessage());
                return null;
            }
        }

        $this->writeSearchCache($cacheFile, $results);
        return $results;
    }

    private function runSearch(Client $client, string $query, array $types, int $limit, int $timeout): array
    {
        $response = $client->get('https://api.spotify.com/v1/search', [
            'query' => [
                'q' => $query,
                'type' => implode(',', $types),
                'limit' => $limit,
            ],
            'timeout' => $timeout,
            'connect_timeout' => min(2, max(1, $timeout)),
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data)) {
            throw new \Exception('Invalid Spotify search response.');
        }

        return $this->mapSearchResults($data, $types);
    }

    private function searchCacheDir(): string
    {
        return dirname($this->storage->getStorageDir()) . '/cache';
    }

    private function searchCacheFile(string $query, string $type, int $limit): string
    {
        return $this->searchCacheDir() . '/spotify_search_' . sha1($query . '|' . $type . '|' . $limit) . '.json';
    }

    private function readSearchCache(string $file): ?array
    {
        if (!is_file($file) || time() - (int) @filemtime($file) > self::SEARCH_CACHE_TTL) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function writeSearchCache(string $file, array $results): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($file, json_encode($results), LOCK_EX);
    }

    private function mapSearchResults(array $data, array $types): array
    {
        $results = [];

        foreach ($types as $type) {
            $items = $data[$type . 's']['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }

                $mapped = $this->mapSearchItem($type, $item);
                if ($mapped !== null) {
                    $results[] = $mapped;
                }
            }
        }

        return $results;
    }

    private function mapSearchItem(string $type, array $item): ?array
    {
        $id = (string) $item['id'];
        $name = (string) ($item['name'] ?? '');
        $url = (string) ($item['external_urls']['spotify'] ?? '');

        if ($type === 'track') {
            $artists = $this->joinArtists($item['artists'] ?? []);
            $album = $item['album'] ?? [];

            return [
                'id' => $id,
                'type' => 'track',
                'badge' => 'Titre',
                'name' => $name,
                'subtitle' => $artists,
                'meta' => (string) ($album['name'] ?? ''),
                'image' => $this->getSearchImage($item),
                'url' => $url !== '' ? $url : 'https://open.spotify.com/track/' . $id,
            ];
        }

        if ($type === 'album') {
            $artists = $this->joinArtists($item['artists'] ?? []);
            $trackCount = (int) ($item['total_tracks'] ?? 0);

            return [
                'id' => $id,
                'type' => 'album',
                'badge' => 'Album',
                'name' => $name,
                'subtitle' => $artists,
                'meta' => $trackCount . ' titre' . ($trackCount > 1 ? 's' : ''),
                'image' => $this->getSearchImage($item),
                'url' => $url !== '' ? $url : 'https://open.spotify.com/album/' . $id,
            ];
        }

        if ($type === 'playlist') {
            $owner = $item['owner'] ?? [];
            $ownerName = (string) ($owner['display_name'] ?? $owner['id'] ?? '');
            $trackCount = (int) ($item['tracks']['total'] ?? 0);

            return [
                'id' => $id,
                'type' => 'playlist',
                'badge' => 'Playlist',
                'name' => $name,
                'subtitle' => $ownerName !== '' ? 'Par ' . $ownerName : '',
                'meta' => $trackCount . ' titre' . ($trackCount > 1 ? 's' : ''),
                'image' => $this->getSearchImage($item),
                'url' => $url !== '' ? $url : 'https://open.spotify.com/playlist/' . $id,
            ];
        }

        return null;
    }

    private function joinArtists(array $artists): string
    {
        $names = [];
        foreach ($artists as $artist) {
            if (is_array($artist) && !empty($artist['name'])) {
                $names[] = (string) $artist['name'];
            }
        }
        return implode(', ', $names);
    }

    private function getSearchImage(array $item): ?string
    {
        if (!empty($item['images'][0]['url'])) {
            return (string) $item['images'][0]['url'];
        }
        if (!empty($item['album']['images'][0]['url'])) {
            return (string) $item['album']['images'][0]['url'];
        }
        return null;
    }

    public function parseUrl(string $url): ?array
    {
        // Handle common Spotify URL formats, including those with query parameters (?si=...)
        // and various locale segments (e.g. /intl-fr/ or /fr-fr/)
        if (preg_match('/spotify\.com\/(?:[^\/]+\/)?(track|album|playlist)\/([a-zA-Z0-9]+)/i', $url, $matches)) {
            return [
                'type' => $matches[1],
                'id' => $matches[2]
            ];
        }
        return null;
    }

    private function getTrackMetadata(Client $client, string $id): array
    {
        $response = $client->get("https://api.spotify.com/v1/tracks/$id");
        $track = json_decode($response->getBody()->getContents(), true);

        $artistsArr = array_map(fn($a) => $a['name'], $track['artists']);
        $artistName = implode(', ', $artistsArr);

        return [
            'name' => $track['name'],
            'tracks' => [
                [
                    'artist' => $artistName,
                    'album' => $track['album']['name'],
                    'song_name' => $track['name']
                ]
            ]
        ];
    }

    private function getAlbumMetadata(Client $client, string $id): array
    {
        $response = $client->get("https://api.spotify.com/v1/albums/$id");
        $album = json_decode($response->getBody()->getContents(), true);

        $albumArtistsArr = array_map(fn($a) => $a['name'], $album['artists']);
        $albumArtistName = implode(', ', $albumArtistsArr);

        $tracks = [];
        foreach ($album['tracks']['items'] as $item) {
            $trackArtistsArr = array_map(fn($a) => $a['name'], $item['artists']);
            $trackArtistName = !empty($trackArtistsArr) ? implode(', ', $trackArtistsArr) : $albumArtistName;

            $tracks[] = [
                'artist' => $trackArtistName,
                'album' => $album['name'],
                'song_name' => $item['name']
            ];
        }

        return [
            'name' => $album['name'],
            'tracks' => $tracks
        ];
    }

    private function getPlaylistMetadata(Client $client, string $id): array
    {
        $response = $client->get("https://api.spotify.com/v1/playlists/$id");
        $playlist = json_decode($response->getBody()->getContents(), true);

        $tracks = [];
        foreach ($playlist['tracks']['items'] as $item) {
            $track = $item['track'];
            if (!$track)
                continue;

            $trackArtistsArr = array_map(fn($a) => $a['name'], $track['artists']);
            $trackArtistName = implode(', ', $trackArtistsArr);

            $tracks[] = [
                'artist' => $trackArtistName,
                'album' => $track['album']['name'],
                'song_name' => $track['name']
            ];
        }

        return [
            'name' => $playlist['name'],
            'tracks' => $tracks
        ];
    }
}
