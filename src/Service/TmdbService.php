<?php

namespace App\Service;

use GuzzleHttp\Client;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class TmdbService
{
    private const API_URL = 'https://api.themoviedb.org/3';
    private const IMAGE_URL = 'https://image.tmdb.org/t/p';

    private JsonStorage $storage;
    private CacheInterface $cache;
    private Client $client;

    public function __construct(JsonStorage $storage, CacheInterface $cache)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->client = new Client();
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->getApiKey()) !== '';
    }

    public function getApiKey(): string
    {
        $config = $this->storage->get('config', []);
        return trim((string) ($config['tmdb_api_key'] ?? ''));
    }

    public function getLanguage(): string
    {
        $config = $this->storage->get('config', []);
        return (string) ($config['tmdb_language'] ?? 'fr-FR');
    }

    public function search(string $query, string $type = 'all', ?bool $includeAdult = null, ?string $language = null): array
    {
        $query = trim($query);
        if ($query === '' || !$this->isConfigured()) {
            return [];
        }

        $config = $this->storage->get('config', []);
        $includeAdult = $includeAdult ?? !empty($config['allow_xxx_search']);
        $language = $language ?? $this->getLanguage();

        $endpoint = match ($type) {
            'movie' => 'search/movie',
            'series' => 'search/tv',
            default => 'search/multi',
        };

        $data = $this->request($endpoint, [
            'query' => $query,
            'language' => $language,
            'include_adult' => $includeAdult ? 'true' : 'false',
            'page' => 1,
        ]);

        if (!is_array($data) || !isset($data['results'])) {
            return [];
        }

        $results = [];
        foreach ($data['results'] as $item) {
            $itemType = $type === 'movie' ? 'movie' : ($type === 'series' ? 'series' : ($item['media_type'] ?? null));
            if (!in_array($itemType, ['movie', 'series'], true)) {
                continue;
            }
            $normalized = $this->normalizeSearchResult($item, $itemType);
            if ($normalized) {
                $results[] = $normalized;
            }
        }

        return $results;
    }

    public function trending(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $data = $this->request('trending/all/week', [
            'language' => $this->getLanguage(),
        ]);

        if (!is_array($data) || !isset($data['results'])) {
            return [];
        }

        $results = [];
        foreach ($data['results'] as $item) {
            $type = $item['media_type'] ?? null;
            if (!in_array($type, ['movie', 'tv'], true)) {
                continue;
            }
            $normalized = $this->normalizeSearchResult($item, $type === 'tv' ? 'series' : 'movie');
            if ($normalized) {
                $results[] = $normalized;
            }
            if (count($results) >= 12) {
                break;
            }
        }

        return $results;
    }

    public function details(string $type, int $id, ?string $language = null): ?array
    {
        if (!$this->isConfigured() || !in_array($type, ['movie', 'series'], true)) {
            return null;
        }

        $endpoint = $type === 'movie' ? 'movie/' . $id : 'tv/' . $id;
        $data = $this->request($endpoint, [
            'language' => $language ?? $this->getLanguage(),
            'append_to_response' => 'external_ids',
        ]);

        if (!is_array($data) || empty($data['id'])) {
            return null;
        }

        $isMovie = $type === 'movie';
        $externalIds = $data['external_ids'] ?? [];

        $result = [
            'id' => (int) $data['id'],
            'type' => $type,
            'title' => $data['title'] ?? $data['name'] ?? 'Unknown',
            'original_title' => $data['original_title'] ?? $data['original_name'] ?? null,
            'year' => substr((string) ($data['release_date'] ?? $data['first_air_date'] ?? ''), 0, 4),
            'overview' => $data['overview'] ?? '',
            'poster' => $this->image($data['poster_path'] ?? null, 'w342'),
            'backdrop' => $this->image($data['backdrop_path'] ?? null, 'w780'),
            'rating' => isset($data['vote_average']) ? round((float) $data['vote_average'], 1) : null,
            'genres' => array_values(array_filter(array_map(
                static fn ($genre) => is_array($genre) ? ($genre['name'] ?? null) : null,
                $data['genres'] ?? []
            ))),
            'imdb_id' => $externalIds['imdb_id'] ?? ($data['imdb_id'] ?? null),
            'runtime' => $isMovie ? ($data['runtime'] ?? null) : null,
            'seasons' => [],
        ];

        if (!$isMovie) {
            foreach ($data['seasons'] ?? [] as $season) {
                if (!isset($season['season_number'])) {
                    continue;
                }
                $result['seasons'][] = [
                    'season_number' => (int) $season['season_number'],
                    'name' => $season['name'] ?? ('Season ' . $season['season_number']),
                    'episode_count' => (int) ($season['episode_count'] ?? 0),
                    'poster' => $this->image($season['poster_path'] ?? null, 'w185'),
                ];
            }
            $result['episode_runtime'] = $data['episode_run_time'][0] ?? null;
        }

        return $result;
    }

    public function findByImdb(string $imdbId): ?array
    {
        $imdbId = trim($imdbId);
        if (!$this->isConfigured() || !preg_match('/^tt\d+$/', $imdbId)) {
            return null;
        }

        $data = $this->request('find/' . $imdbId, [
            'external_source' => 'imdb_id',
        ]);

        if (!is_array($data)) {
            return null;
        }

        $movie = $data['movie_results'][0] ?? null;
        if (is_array($movie) && !empty($movie['id'])) {
            return $this->details('movie', (int) $movie['id']);
        }

        $series = $data['tv_results'][0] ?? null;
        if (is_array($series) && !empty($series['id'])) {
            return $this->details('series', (int) $series['id']);
        }

        return null;
    }

    public function season(int $tvId, int $seasonNumber): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $data = $this->request('tv/' . $tvId . '/season/' . $seasonNumber, [
            'language' => $this->getLanguage(),
        ]);

        if (!is_array($data) || !isset($data['episodes'])) {
            return [];
        }

        $episodes = [];
        foreach ($data['episodes'] as $episode) {
            $episodes[] = [
                'episode_number' => (int) ($episode['episode_number'] ?? 0),
                'name' => $episode['name'] ?? ('Episode ' . ($episode['episode_number'] ?? '')),
                'overview' => $episode['overview'] ?? '',
                'air_date' => $episode['air_date'] ?? null,
                'runtime' => $episode['runtime'] ?? null,
                'still' => $this->image($episode['still_path'] ?? null, 'w300'),
            ];
        }

        return $episodes;
    }

    public function testCredentials(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $data = $this->request('configuration', [], 60);
        return is_array($data) && isset($data['images']);
    }

    private function normalizeSearchResult(array $item, string $type): ?array
    {
        if (empty($item['id'])) {
            return null;
        }

        $isMovie = $type === 'movie';

        return [
            'id' => (int) $item['id'],
            'type' => $type,
            'title' => $item['title'] ?? $item['name'] ?? 'Unknown',
            'original_title' => $item['original_title'] ?? $item['original_name'] ?? null,
            'year' => substr((string) ($item['release_date'] ?? $item['first_air_date'] ?? ''), 0, 4),
            'overview' => $item['overview'] ?? '',
            'poster' => $this->image($item['poster_path'] ?? null, 'w342'),
            'backdrop' => $this->image($item['backdrop_path'] ?? null, 'w780'),
            'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
            'genres' => [],
            'imdb_id' => null,
            'runtime' => null,
            'seasons' => [],
        ];
    }

    private function image(?string $path, string $size): ?string
    {
        return $path ? self::IMAGE_URL . '/' . $size . $path : null;
    }

    private function request(string $path, array $query = [], int $ttl = 600): array
    {
        $key = $this->getApiKey();
        if ($key === '') {
            return [];
        }

        $language = $this->getLanguage();
        $query['language'] = $query['language'] ?? $language;

        $cacheKey = 'tmdb_' . md5($path . '|' . http_build_query($query) . '|' . md5($key));
        $loader = function (ItemInterface $item) use ($path, $query, $key, $ttl) {
            $item->expiresAfter($ttl);

            try {
                $isBearer = strlen($key) > 100 || str_starts_with($key, 'eyJ');
                $options = [
                    'timeout' => 15,
                    'connect_timeout' => 8,
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ];

                $requestQuery = $query;
                if ($isBearer) {
                    $options['headers']['Authorization'] = 'Bearer ' . $key;
                } else {
                    $requestQuery['api_key'] = $key;
                }

                $response = $this->client->get(self::API_URL . '/' . ltrim($path, '/'), [
                    ...$options,
                    'query' => $requestQuery,
                ]);

                $data = json_decode((string) $response->getBody(), true);
                if (!is_array($data)) {
                    $item->expiresAfter(60);
                    return ['error' => 'Invalid TMDB response.'];
                }

                return $data;
            } catch (\Throwable $e) {
                $item->expiresAfter(60);
                return ['error' => $e->getMessage()];
            }
        };

        return $this->cache->get($cacheKey, $loader);
    }
}
