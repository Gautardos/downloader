<?php

namespace App\Service\Automation;

use GuzzleHttp\Client;

class ScrobListClient implements ListSourceClientInterface
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function provider(): string
    {
        return 'scrob';
    }

    public function isConfigured(array $source): bool
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];

        return trim((string) ($config['base_url'] ?? '')) !== ''
            && trim((string) ($config['api_key'] ?? '')) !== ''
            && !empty($this->listRefs($config));
    }

    public function canTest(array $source): bool
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];

        return trim((string) ($config['base_url'] ?? '')) !== ''
            && trim((string) ($config['api_key'] ?? '')) !== '';
    }

    public function fetch(array $source, int $maxItems = 500): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $baseUrl = $this->apiBase($config);
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $maxItems = max(1, $maxItems);

        $result = ['items' => [], 'lists' => [], 'warnings' => []];
        if ($baseUrl === '' || $apiKey === '') {
            $result['warnings'][] = 'Scrob base URL and API key are required.';

            return $result;
        }

        foreach ($this->listRefs($config) as $listRef) {
            if (count($result['items']) >= $maxItems) {
                break;
            }

            $url = $baseUrl . '/lists/' . rawurlencode($listRef);
            try {
                $response = $this->client->get($url, [
                    'query' => ['api_key' => $apiKey],
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Api-Key' => $apiKey,
                    ],
                    'timeout' => 20,
                    'connect_timeout' => 8,
                    'http_errors' => false,
                ]);
            } catch (\Throwable $e) {
                $result['warnings'][] = 'Scrob list ' . $listRef . ': ' . $e->getMessage();
                continue;
            }

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $result['warnings'][] = 'Scrob list ' . $listRef . ' returned HTTP ' . $status . '.';
                continue;
            }

            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                $result['warnings'][] = 'Scrob list ' . $listRef . ' returned invalid JSON.';
                continue;
            }

            $normalized = $this->normalizePayload($payload, $listRef);
            $result['lists'][] = [
                'ref' => $listRef,
                'name' => (string) ($payload['name'] ?? ('Scrob list ' . $listRef)),
                'count' => count($normalized),
            ];

            $remaining = $maxItems - count($result['items']);
            foreach (array_slice($normalized, 0, $remaining) as $item) {
                $result['items'][] = $item;
            }
        }

        return $result;
    }


    public function test(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $baseUrl = $this->apiBase($config);
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($baseUrl === '' || $apiKey === '') {
            return ['success' => false, 'message' => 'Scrob base URL and API key are required.', 'lists' => []];
        }

        try {
            $response = $this->client->get($baseUrl . '/lists', [
                'query' => ['api_key' => $apiKey],
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Api-Key' => $apiKey,
                ],
                'timeout' => 20,
                'connect_timeout' => 8,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage(), 'lists' => []];
        }

        $status = $response->getStatusCode();
        if (in_array($status, [401, 403], true)) {
            return ['success' => false, 'message' => 'Connection failed: invalid Scrob API key.', 'lists' => []];
        }
        if ($status !== 200) {
            return ['success' => false, 'message' => 'Connection failed: Scrob returned HTTP ' . $status . '.', 'lists' => []];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Connection failed: invalid Scrob response.', 'lists' => []];
        }

        $lists = [];
        foreach ($payload['lists'] ?? [] as $list) {
            if (!is_array($list) || !isset($list['id'])) {
                continue;
            }
            $lists[] = [
                'ref' => (string) $list['id'],
                'name' => (string) ($list['name'] ?? ('List ' . $list['id'])),
                'count' => (int) ($list['item_count'] ?? 0),
            ];
        }

        return [
            'success' => true,
            'message' => 'Connection OK — ' . count($lists) . ' list' . (count($lists) === 1 ? '' : 's') . ' found.',
            'lists' => $lists,
        ];
    }

    private function apiBase(array $config): string
    {
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $apiPrefix = trim((string) ($config['api_prefix'] ?? '/api/proxy'));
        if ($apiPrefix !== '' && $baseUrl !== '' && !str_ends_with($baseUrl, '/' . trim($apiPrefix, '/'))) {
            $baseUrl .= '/' . trim($apiPrefix, '/');
        }

        return $baseUrl;
    }

    public function normalizePayload(array $payload, string $listRef): array
    {
        $items = [];
        foreach ($payload['items'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $media = is_array($row['media'] ?? null) ? $row['media'] : $row;
            $item = $this->normalizeMedia($media, $row, $listRef);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function normalizeMedia(array $media, array $row, string $listRef): ?array
    {
        $type = strtolower((string) ($media['type'] ?? ''));
        $tmdbId = $this->integer($media['tmdb_id'] ?? null);
        $imdbId = $this->cleanString($media['imdb_id'] ?? null);
        $tvdbId = $this->integer($media['tvdb_id'] ?? null);
        $title = $this->cleanString($media['title'] ?? null);
        $year = $this->yearFromDate($media['release_date'] ?? null);
        $season = $this->integer($media['season_number'] ?? $row['season_number'] ?? null);
        $episode = $this->integer($media['episode_number'] ?? $row['episode_number'] ?? null);
        $remoteId = $this->cleanString($row['id'] ?? $media['id'] ?? null);

        if ($type === 'movie') {
            if ($title === '') {
                return null;
            }

            return [
                'list_ref' => $listRef,
                'remote_id' => $remoteId,
                'media_type' => 'movie',
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'tvdb_id' => $tvdbId,
                'title' => $title,
                'original_title' => $this->cleanString($media['original_title'] ?? null),
                'year' => $year,
                'season' => null,
                'episode' => null,
                'raw' => $media,
            ];
        }

        if (!in_array($type, ['series', 'season', 'episode'], true)) {
            return null;
        }

        if ($type === 'episode') {
            $showTmdbId = $this->integer($media['show_tmdb_id'] ?? null);
            $showTitle = $this->cleanString($media['show_title'] ?? null);
            $showYear = $this->yearFromDate($media['show_release_date'] ?? null);
            if ($showTitle !== '') {
                $title = $showTitle;
            }
            $tmdbId = $showTmdbId ?: $tmdbId;
            $tvdbId = $this->integer($media['show_tvdb_id'] ?? null) ?: $tvdbId;
            $year = $showYear ?: $year;
        }

        if ($title === '') {
            return null;
        }

        return [
            'list_ref' => $listRef,
            'remote_id' => $remoteId,
            'media_type' => 'series',
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'tvdb_id' => $tvdbId,
            'title' => $title,
            'original_title' => $this->cleanString($media['original_title'] ?? null),
            'year' => $year,
            'season' => $season,
            'episode' => $episode,
            'raw' => $media,
        ];
    }

    private function listRefs(array $config): array
    {
        $raw = $config['list_ids'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $refs, true)) {
                $refs[] = $value;
            }
        }

        return $refs;
    }

    private function integer(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function cleanString(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function yearFromDate(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{4})/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
