<?php

namespace App\Service;

use GuzzleHttp\Client;

class SpotifyService
{
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;
    private array $logs = [];
    private ?string $lastError = null;

    public function __construct(
        private JsonStorage $storage
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
        if ($this->accessToken && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $config = $this->storage->get('config', []);
        $clientId = $config['spotify_client_id'] ?? null;
        $clientSecret = $config['spotify_client_secret'] ?? null;

        if (!$clientId || !$clientSecret) {
            $this->addLog("Missing Spotify API credentials in settings.");
            return null;
        }

        $this->addLog("Authenticating with Spotify...");

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
            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = time() + $data['expires_in'] - 60; // 1 minute buffer

            return $this->accessToken;
        } catch (\Exception $e) {
            $this->addLog("Authentication failed: " . $e->getMessage());
            return null;
        }
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

        $client = new Client(['headers' => ['Authorization' => 'Bearer ' . $token]]);

        try {
            switch ($parsed['type']) {
                case 'track':
                    return $this->getTrackMetadata($client, $parsed['id']);
                case 'album':
                    return $this->getAlbumMetadata($client, $parsed['id']);
                case 'playlist':
                    return $this->getPlaylistMetadata($client, $parsed['id']);
            }
        } catch (\Exception $e) {
            $this->addLog("API call failed: " . $e->getMessage());
        }

        return null;
    }

    public function isConfigured(): bool
    {
        $config = $this->storage->get('config', []);

        return trim((string) ($config['spotify_client_id'] ?? '')) !== ''
            && trim((string) ($config['spotify_client_secret'] ?? '')) !== '';
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

        $token = $this->getAccessToken();
        if (!$token) {
            $this->lastError = 'Failed to get Spotify access token.';
            return null;
        }

        $this->addLog('Searching Spotify for: ' . $query);
        $client = new Client(['headers' => ['Authorization' => 'Bearer ' . $token]]);

        try {
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
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            $this->addLog('Search failed: ' . $e->getMessage());
            return null;
        }
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
