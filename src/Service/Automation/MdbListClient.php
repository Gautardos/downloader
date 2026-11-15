<?php

namespace App\Service\Automation;

use GuzzleHttp\Client;

class MdbListClient implements ListSourceClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.mdblist.com';
    private const PAGE_SIZE = 1000;

    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function provider(): string
    {
        return 'mdblist';
    }

    public function isConfigured(array $source): bool
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];

        return trim((string) ($config['api_key'] ?? '')) !== ''
            && !empty($this->listRefs($config));
    }

    public function canTest(array $source): bool
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];

        return trim((string) ($config['api_key'] ?? '')) !== '';
    }

    public function fetch(array $source, int $maxItems = 500): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')) ?: self::DEFAULT_BASE_URL, '/');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $maxItems = max(1, $maxItems);

        $result = ['items' => [], 'lists' => [], 'warnings' => []];
        if ($apiKey === '') {
            $result['warnings'][] = 'MDBList API key is required.';

            return $result;
        }

        foreach ($this->listRefs($config) as $listRef) {
            if (count($result['items']) >= $maxItems) {
                break;
            }

            $metadata = $this->fetchListMetadata($baseUrl, $apiKey, $listRef);
            $cursor = null;
            $seenCursors = [];
            $listCount = 0;

            do {
                $remaining = $maxItems - count($result['items']);
                if ($remaining <= 0) {
                    break;
                }

                $params = [
                    'apikey' => $apiKey,
                    'limit' => min(self::PAGE_SIZE, $remaining),
                    'unified' => 'true',
                ];
                if ($cursor !== null && $cursor !== '') {
                    $params['cursor'] = $cursor;
                }

                try {
                    $response = $this->client->get($baseUrl . '/lists/' . $this->listPath($listRef) . '/items', [
                        'query' => $params,
                        'headers' => ['Accept' => 'application/json'],
                        'timeout' => 30,
                        'connect_timeout' => 8,
                        'http_errors' => false,
                    ]);
                } catch (\Throwable $e) {
                    $result['warnings'][] = 'MDBList list ' . $listRef . ': ' . $e->getMessage();
                    break;
                }

                $status = $response->getStatusCode();
                if ($status !== 200) {
                    $result['warnings'][] = 'MDBList list ' . $listRef . ' returned HTTP ' . $status . '.';
                    break;
                }

                $payload = json_decode((string) $response->getBody(), true);
                if (!is_array($payload)) {
                    $result['warnings'][] = 'MDBList list ' . $listRef . ' returned invalid JSON.';
                    break;
                }

                $normalized = $this->normalizePayload($payload, $listRef);
                $listCount += count($normalized);
                foreach ($normalized as $item) {
                    if (count($result['items']) >= $maxItems) {
                        break;
                    }
                    $result['items'][] = $item;
                }

                $bodyCursor = is_array($payload['pagination'] ?? null)
                    ? ($payload['pagination']['next_cursor'] ?? null)
                    : null;
                $nextCursor = $response->getHeaderLine('X-Next-Cursor') ?: $bodyCursor;
                $nextCursor = is_string($nextCursor) ? trim($nextCursor) : '';

                if ($nextCursor === '' || count($normalized) === 0) {
                    break;
                }
                if (isset($seenCursors[$nextCursor])) {
                    $result['warnings'][] = 'MDBList list ' . $listRef . ' returned a repeated pagination cursor.';
                    break;
                }
                $seenCursors[$nextCursor] = true;
                $cursor = $nextCursor;
            } while (count($result['items']) < $maxItems);

            $result['lists'][] = [
                'ref' => $listRef,
                'name' => (string) ($metadata['name'] ?? ('MDBList ' . $listRef)),
                'count' => $listCount,
            ];
        }

        return $result;
    }


    public function test(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')) ?: self::DEFAULT_BASE_URL, '/');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'message' => 'MDBList API key is required.', 'lists' => []];
        }

        try {
            $response = $this->client->get($baseUrl . '/lists/user', [
                'query' => [
                    'apikey' => $apiKey,
                    'sort' => 'name',
                    'unified' => 'true',
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 20,
                'connect_timeout' => 8,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage(), 'lists' => []];
        }

        $status = $response->getStatusCode();
        if (in_array($status, [401, 403], true)) {
            return ['success' => false, 'message' => 'Connection failed: invalid MDBList API key.', 'lists' => []];
        }
        if ($status !== 200) {
            return ['success' => false, 'message' => 'Connection failed: MDBList returned HTTP ' . $status . '.', 'lists' => []];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Connection failed: invalid MDBList response.', 'lists' => []];
        }
        if (isset($payload['lists']) && is_array($payload['lists'])) {
            $payload = $payload['lists'];
        }

        $lists = [];
        foreach ($payload as $list) {
            if (!is_array($list) || !isset($list['id'])) {
                continue;
            }
            $lists[] = [
                'ref' => (string) $list['id'],
                'name' => (string) ($list['name'] ?? ('List ' . $list['id'])),
                'count' => (int) ($list['items'] ?? 0),
            ];
        }

        return [
            'success' => true,
            'message' => 'Connection OK — ' . count($lists) . ' list' . (count($lists) === 1 ? '' : 's') . ' found.',
            'lists' => $lists,
        ];
    }

    public function normalizePayload(array $payload, string $listRef): array
    {
        $items = [];
        if (array_is_list($payload)) {
            foreach ($payload as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $kind = strtolower((string) ($entry['mediatype'] ?? $entry['type'] ?? ''));
                $item = $this->normalizeEntry($entry, $listRef, $kind);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        foreach (['movies' => 'movie', 'shows' => 'show', 'seasons' => 'season', 'episodes' => 'episode'] as $bucket => $kind) {
            if (!is_array($payload[$bucket] ?? null)) {
                continue;
            }
            foreach ($payload[$bucket] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryKind = strtolower((string) ($entry['mediatype'] ?? $entry['type'] ?? $kind));
                $item = $this->normalizeEntry($entry, $listRef, $entryKind);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function normalizeEntry(array $entry, string $listRef, string $kind): ?array
    {
        $singular = match ($kind) {
            'movie', 'movies' => 'movie',
            'show', 'series', 'tv', 'shows' => 'show',
            'season', 'seasons' => 'season',
            'episode', 'episodes' => 'episode',
            default => '',
        };
        $data = is_array($entry[$singular] ?? null) ? $entry[$singular] : $entry;
        $ids = is_array($data['ids'] ?? null) ? $data['ids'] : (is_array($entry['ids'] ?? null) ? $entry['ids'] : []);
        $tmdbId = $this->integer($ids['tmdb'] ?? $data['tmdb_id'] ?? $entry['tmdb_id'] ?? null);
        if ($tmdbId === null && in_array($kind, ['movie', 'movies', 'show', 'series', 'tv', 'shows'], true)) {
            $tmdbId = $this->integer($entry['id'] ?? null);
        }
        $imdbId = $this->cleanString($ids['imdb'] ?? $data['imdb_id'] ?? $entry['imdb_id'] ?? $data['imdb'] ?? null);
        $tvdbId = $this->integer($ids['tvdb'] ?? $data['tvdb_id'] ?? $entry['tvdb_id'] ?? null);
        $title = $this->cleanString($data['title'] ?? $data['name'] ?? $entry['title'] ?? null);
        $year = $this->integer($data['release_year'] ?? $entry['release_year'] ?? null)
            ?? $this->yearFromDate($data['release_date'] ?? $entry['release_date'] ?? null);
        $remoteId = $this->cleanString($entry['id'] ?? $data['id'] ?? null);
        $season = $this->integer($data['season_number'] ?? $entry['season_number'] ?? null);
        $episode = $this->integer($data['episode_number'] ?? $entry['episode_number'] ?? null);

        if ($kind === 'season' || $kind === 'seasons') {
            $season = $this->integer($data['number'] ?? $entry['number'] ?? $season);
        }
        if ($kind === 'episode' || $kind === 'episodes') {
            $show = is_array($entry['show'] ?? null) ? $entry['show'] : (is_array($data['show'] ?? null) ? $data['show'] : []);
            $showIds = is_array($show['ids'] ?? null) ? $show['ids'] : [];
            $showTmdbId = $this->integer($showIds['tmdb'] ?? $show['tmdb_id'] ?? $data['show_tmdb_id'] ?? null);
            $showTitle = $this->cleanString($show['title'] ?? $show['name'] ?? $data['show_title'] ?? null);
            $seasonValue = $data['season'] ?? $entry['season'] ?? null;
            if (is_array($seasonValue)) {
                $seasonValue = $seasonValue['number'] ?? null;
            }
            $season = $this->integer($seasonValue ?? $season);
            $episode = $this->integer($data['number'] ?? $data['episode'] ?? $entry['number'] ?? $episode);
            if ($showTitle !== '') {
                $title = $showTitle;
            }
            $tmdbId = $showTmdbId ?: $tmdbId;
        }

        $mediaType = match ($kind) {
            'movie', 'movies' => 'movie',
            'show', 'series', 'tv', 'shows', 'season', 'seasons', 'episode', 'episodes' => 'series',
            default => '',
        };
        if ($mediaType === '' || $title === '') {
            return null;
        }

        return [
            'list_ref' => $listRef,
            'remote_id' => $remoteId,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'tvdb_id' => $tvdbId,
            'title' => $title,
            'original_title' => $this->cleanString($data['original_title'] ?? $data['title_en'] ?? null),
            'year' => $year,
            'season' => $mediaType === 'series' ? $season : null,
            'episode' => $mediaType === 'series' ? $episode : null,
            'raw' => $entry,
        ];
    }

    private function fetchListMetadata(string $baseUrl, string $apiKey, string $listRef): array
    {
        try {
            $response = $this->client->get($baseUrl . '/lists/' . $this->listPath($listRef), [
                'query' => ['apikey' => $apiKey],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
                'connect_timeout' => 8,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }
        if (array_is_list($payload)) {
            return is_array($payload[0] ?? null) ? $payload[0] : [];
        }

        return $payload;
    }

    private function listPath(string $listRef): string
    {
        $segments = array_filter(explode('/', trim($listRef, '/')), static fn ($segment) => $segment !== '');

        return implode('/', array_map('rawurlencode', $segments));
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
