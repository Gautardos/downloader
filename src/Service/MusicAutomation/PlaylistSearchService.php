<?php

namespace App\Service\MusicAutomation;

use App\Service\SpotifyService;
use App\Service\SpotifyUserService;

class PlaylistSearchService
{
    public const SCOPES = ['all', 'public', 'private'];

    public function __construct(
        private SpotifyService $spotify,
        private SpotifyUserService $spotifyUser
    ) {
    }

    public static function isValidPlaylistId(string $playlistId): bool
    {
        return preg_match('/^[A-Za-z0-9]{22}$/', trim($playlistId)) === 1;
    }

    public static function isValidPlaylistUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['open.spotify.com', 'www.open.spotify.com'], true)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (!preg_match('#/playlist/([A-Za-z0-9]{22})/?$#', $path, $matches)) {
            return false;
        }

        return true;
    }

    public function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) $scope));
        if (in_array($scope, ['my', 'mine', 'user', 'playlists'], true)) {
            $scope = 'private';
        }

        return in_array($scope, self::SCOPES, true) ? $scope : 'all';
    }

    public function search(string $query, ?string $scope = 'all', int $limit = 20): array
    {
        $query = trim($query);
        $scope = $this->normalizeScope($scope);
        $limit = max(1, min($limit, 50));

        $public = [];
        $private = [];
        $publicError = null;
        $privateError = null;

        if ($scope === 'all' || $scope === 'public') {
            $public = $this->searchPublic($query, $limit, $publicError);
        }

        if ($scope === 'all' || $scope === 'private') {
            $private = $this->searchPrivate($query, $limit, $privateError);
        }

        $results = $this->merge($public, $private, $limit);

        $response = [
            'success' => $results !== [] || ($publicError === null && $privateError === null),
            'scope' => $scope,
            'query' => $query,
            'results' => $results,
            'count' => count($results),
            'public_count' => count($public),
            'private_count' => count($private),
            'public_error' => $publicError,
            'private_error' => $privateError,
        ];

        if ($results === [] && $publicError === null && $privateError === null) {
            $response['message'] = $query === ''
                ? 'Type a playlist name to search.'
                : 'No playlist matched your search.';
        } elseif ($results === []) {
            $response['message'] = $privateError ?? $publicError;
        }

        return $response;
    }

    private function searchPublic(string $query, int $limit, ?string &$error): array
    {
        if ($query === '') {
            return [];
        }

        try {
            $payload = $this->spotify->search($query, 'playlist', $limit);
        } catch (\Throwable $e) {
            $error = 'Public Spotify search is unavailable.';
            return [];
        }

        if (!is_array($payload)) {
            $error = $this->spotify->getLastError() ?: 'Public Spotify search is unavailable.';
            return [];
        }

        $results = [];
        foreach ($payload as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'playlist') {
                continue;
            }
            $mapped = $this->mapPublicItem($item);
            if ($mapped !== null) {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    private function searchPrivate(string $query, int $limit, ?string &$error): array
    {
        try {
            $response = $this->spotifyUser->listPlaylists();
        } catch (\Throwable $e) {
            $error = 'Your Spotify account is unavailable.';
            return [];
        }

        if (empty($response['success'])) {
            $error = $response['error'] ?? null;
            if ($error === null || $error === '') {
                $error = $this->spotifyUser->getLastError();
            }
            if ($error === null || $error === '') {
                $error = 'Your Spotify account is not configured.';
            }
            return [];
        }

        $normalizedQuery = $this->normalizeText($query);
        $results = [];
        foreach (($response['playlists'] ?? []) as $playlist) {
            if (!is_array($playlist)) {
                continue;
            }
            $name = (string) ($playlist['name'] ?? '');
            if ($normalizedQuery !== '' && !str_contains($this->normalizeText($name), $normalizedQuery)) {
                continue;
            }
            $mapped = $this->mapUserPlaylist($playlist);
            if ($mapped !== null) {
                $results[] = $mapped;
            }
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function mapPublicItem(array $item): ?array
    {
        $id = (string) ($item['id'] ?? '');
        if (!self::isValidPlaylistId($id)) {
            return null;
        }

        $owner = (string) ($item['subtitle'] ?? '');
        $owner = preg_replace('/^Par\s+/i', '', $owner) ?? $owner;
        $trackCount = 0;
        if (preg_match('/(\d+)\s+titre/i', (string) ($item['meta'] ?? ''), $matches)) {
            $trackCount = (int) $matches[1];
        }

        return [
            'id' => $id,
            'name' => (string) ($item['name'] ?? 'Playlist'),
            'owner' => $owner,
            'track_count' => $trackCount,
            'url' => (string) ($item['url'] ?? 'https://open.spotify.com/playlist/' . $id),
            'visibility' => 'public',
            'image' => $item['image'] ?? null,
            'source' => 'spotify',
        ];
    }

    private function mapUserPlaylist(array $playlist): ?array
    {
        $id = (string) ($playlist['id'] ?? '');
        if (!self::isValidPlaylistId($id)) {
            return null;
        }

        $isPublic = ($playlist['public'] ?? null) === true;

        return [
            'id' => $id,
            'name' => (string) ($playlist['name'] ?? 'Playlist'),
            'owner' => (string) ($playlist['owner'] ?? ''),
            'track_count' => (int) ($playlist['track_count'] ?? 0),
            'url' => (string) ($playlist['url'] ?? 'https://open.spotify.com/playlist/' . $id),
            'visibility' => $isPublic ? 'public' : 'private',
            'image' => $playlist['image'] ?? null,
            'source' => 'account',
        ];
    }

    private function merge(array $public, array $private, int $limit): array
    {
        $merged = [];
        foreach ($private as $item) {
            $merged[$item['id']] = $item;
        }
        foreach ($public as $item) {
            if (!isset($merged[$item['id']])) {
                $merged[$item['id']] = $item;
            }
        }

        return array_slice(array_values($merged), 0, $limit);
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
