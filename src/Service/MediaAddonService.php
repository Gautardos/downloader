<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MediaAddonService
{
    private JsonStorage $storage;
    private CacheInterface $cache;
    private Client $client;
    private SafeUrlGuard $urlGuard;
    private array $metaAddonCache = [];

    public function __construct(JsonStorage $storage, CacheInterface $cache, ?SafeUrlGuard $urlGuard = null)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->client = new Client();
        $this->urlGuard = $urlGuard ?? new SafeUrlGuard();
    }

    public function getAddons(): array
    {
        $addons = $this->storage->get('media_addons', []);
        if (!is_array($addons)) {
            return [];
        }

        return array_values(array_map(fn ($addon) => $this->normalizeAddon($addon), array_filter($addons, 'is_array')));
    }

    public function saveAddons(array $addons): void
    {
        $this->storage->set('media_addons', array_values($addons));
    }

    public function validateManifestUrl(string $url): array
    {
        try {
            $fetched = $this->fetchJson($url);
            $manifest = $fetched['data'];

            if (empty($manifest['id']) || empty($manifest['name']) || empty($manifest['resources']) || empty($manifest['types'])) {
                throw new \RuntimeException('Invalid Stremio manifest: missing id, name, resources or types.');
            }

            return [
                'success' => true,
                'manifest' => $manifest,
                'manifest_url' => $fetched['url'],
                'base_url' => $this->deriveBaseUrl($fetched['url']),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getEnabledStreamAddons(string $type): array
    {
        $targets = [];
        $errors = [];

        foreach ($this->getAddons() as $addon) {
            if (empty($addon['enabled'])) {
                continue;
            }

            try {
                $fetched = $this->fetchJson($addon['manifest_url']);
                $manifest = $fetched['data'];

                if (!$this->supportsStream($manifest, $type)) {
                    continue;
                }

                $targets[] = [
                    ...$addon,
                    'base_url' => $this->deriveBaseUrl($fetched['url']),
                    'query' => (string) (parse_url($fetched['url'], PHP_URL_QUERY) ?? ''),
                    'manifest' => $manifest,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'addon' => $addon['name'] ?: $addon['manifest_url'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        usort($targets, static fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        return ['addons' => $targets, 'errors' => $errors];
    }

    public function getEnabledMetaAddons(string $type): array
    {
        $cached = $this->metaAddonCache[$type] ?? null;
        if (is_array($cached) && (time() - (int) ($cached['at'] ?? 0)) < 300) {
            return $cached['result'];
        }

        $targets = [];
        $errors = [];

        foreach ($this->getAddons() as $addon) {
            if (empty($addon['enabled'])) {
                continue;
            }

            try {
                $fetched = $this->fetchJson($addon['manifest_url']);
                $manifest = $fetched['data'];
                if (!$this->supportsMeta($manifest, $type)) {
                    continue;
                }

                $targets[] = [
                    ...$addon,
                    'base_url' => $this->deriveBaseUrl($fetched['url']),
                    'query' => (string) (parse_url($fetched['url'], PHP_URL_QUERY) ?? ''),
                    'manifest' => $manifest,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'addon' => $addon['name'] ?: $addon['manifest_url'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        usort($targets, static fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));
        $result = ['addons' => $targets, 'errors' => $errors];
        $this->metaAddonCache[$type] = ['at' => time(), 'result' => $result];

        return $result;
    }

    public function getMeta(string $type, string $id, array $preferredAddonIds = []): array
    {
        $id = trim($id);
        if (!in_array($type, ['movie', 'series', 'anime'], true) || $id === '' || !preg_match('/^[a-z0-9_.:-]+$/i', $id)) {
            return ['success' => false, 'message' => 'Invalid metadata request.', 'meta' => null, 'errors' => []];
        }

        $resolved = $this->getEnabledMetaAddons($type);
        $targets = $resolved['addons'];
        $errors = $resolved['errors'];
        if ($preferredAddonIds) {
            usort($targets, static function (array $a, array $b) use ($preferredAddonIds): int {
                $aPreferred = in_array((string) ($a['manifest']['id'] ?? $a['id'] ?? ''), $preferredAddonIds, true);
                $bPreferred = in_array((string) ($b['manifest']['id'] ?? $b['id'] ?? ''), $preferredAddonIds, true);
                if ($aPreferred !== $bPreferred) {
                    return $aPreferred ? -1 : 1;
                }

                return ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100));
            });
        }

        foreach ($targets as $target) {
            $url = rtrim($target['base_url'], '/') . '/meta/' . rawurlencode($type) . '/' . $this->encodePathId($id) . '.json';
            if (!empty($target['query'])) {
                $url .= '?' . $target['query'];
            }

            try {
                $this->assertPublicHttpsUrl($url);
                $response = $this->client->get($url, [
                    'timeout' => max(3, min(20, (int) ($target['timeout'] ?? 10))),
                    'connect_timeout' => 5,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['Accept' => 'application/json'],
                ]);
                if ($response->getStatusCode() !== 200) {
                    $errors[] = ['addon' => $target['name'], 'error' => 'HTTP ' . $response->getStatusCode()];
                    continue;
                }
                $body = (string) $response->getBody();
                if (strlen($body) > 8 * 1024 * 1024) {
                    $errors[] = ['addon' => $target['name'], 'error' => 'Metadata response is too large.'];
                    continue;
                }
                $data = json_decode($body, true);
                $meta = is_array($data) ? ($data['meta'] ?? null) : null;
                if (!is_array($meta)) {
                    $errors[] = ['addon' => $target['name'], 'error' => 'Invalid metadata response.'];
                    continue;
                }

                return [
                    'success' => true,
                    'message' => null,
                    'meta' => $meta,
                    'addon_id' => (string) ($target['manifest']['id'] ?? $target['id'] ?? ''),
                    'addon_name' => (string) ($target['name'] ?? $target['manifest']['name'] ?? ''),
                    'errors' => $errors,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['addon' => $target['name'], 'error' => $e->getMessage()];
            }
        }

        return [
            'success' => false,
            'message' => $errors ? 'No metadata addon returned this title.' : 'No metadata addon is configured.',
            'meta' => null,
            'errors' => $errors,
        ];
    }

    public function getEnabledAddon(string $addonId): ?array
    {
        foreach ($this->getAddons() as $addon) {
            if (empty($addon['enabled'])) {
                continue;
            }

            try {
                $fetched = $this->fetchJson($addon['manifest_url']);
                $manifest = $fetched['data'];
                if (($manifest['id'] ?? '') !== $addonId && $addon['id'] !== $addonId) {
                    continue;
                }

                return [
                    ...$addon,
                    'base_url' => $this->deriveBaseUrl($fetched['url']),
                    'query' => (string) (parse_url($fetched['url'], PHP_URL_QUERY) ?? ''),
                    'manifest' => $manifest,
                ];
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function getCatalogs(): array
    {
        $catalogs = [];
        $errors = [];

        foreach ($this->getAddons() as $addon) {
            if (empty($addon['enabled'])) {
                continue;
            }

            try {
                $fetched = $this->fetchJson($addon['manifest_url']);
                $manifest = $fetched['data'];
                if (!$this->supportsCatalog($manifest)) {
                    continue;
                }

                foreach ($manifest['catalogs'] ?? [] as $catalog) {
                    if (!is_array($catalog) || empty($catalog['id']) || empty($catalog['type'])) {
                        continue;
                    }

                    $catalogs[] = [
                        'addon_id' => $manifest['id'],
                        'addon_name' => $addon['name'] ?: ($manifest['name'] ?? 'Addon'),
                        'type' => (string) $catalog['type'],
                        'id' => (string) $catalog['id'],
                        'name' => (string) ($catalog['name'] ?? $catalog['id']),
                        'genres' => $this->catalogGenres($catalog),
                        'extra' => $catalog['extra'] ?? [],
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = ['addon' => $addon['name'], 'error' => $e->getMessage()];
            }
        }

        return ['catalogs' => $catalogs, 'errors' => $errors];
    }

    public function getCatalogItems(string $addonId, string $type, string $catalogId, ?string $genre = null, int $skip = 0): array
    {
        $target = $this->getEnabledAddon($addonId);
        if (!$target) {
            return ['success' => false, 'message' => 'Catalog addon not found or disabled.', 'items' => [], 'meta' => []];
        }

        if (!preg_match('/^[a-z0-9_.:-]+$/i', $type) || !preg_match('/^[a-z0-9_.:-]+$/i', $catalogId)) {
            return ['success' => false, 'message' => 'Invalid catalog identifier.', 'items' => [], 'meta' => []];
        }

        $genre = $genre !== null ? trim($genre) : null;
        $skip = max(0, $skip);
        $cacheKey = 'media_catalog_' . md5($target['id'] . '|' . $type . '|' . $catalogId . '|' . ($genre ?? '') . '|' . $skip . '|' . $target['query']);

        $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($target, $type, $catalogId, $genre, $skip) {
            $item->expiresAfter(300);

            try {
                return $this->fetchCatalogPayload($target, $type, $catalogId, $genre, $skip);
            } catch (\Throwable $e) {
                $item->expiresAfter(60);
                return ['success' => false, 'error' => $e->getMessage(), 'metas' => []];
            }
        });

        if (empty($payload['success'])) {
            return ['success' => false, 'message' => $payload['error'] ?? 'Could not load catalog.', 'items' => [], 'meta' => []];
        }

        $items = [];
        foreach ($payload['metas'] ?? [] as $meta) {
            $normalized = $this->normalizeCatalogItem($meta, $type);
            if ($normalized) {
                $items[] = $normalized;
            }
        }

        return ['success' => true, 'items' => $items, 'meta' => $payload['meta'] ?? []];
    }

    public function getStreams(string $type, string $videoId): array
    {
        if (!in_array($type, ['movie', 'series'], true)) {
            return ['success' => false, 'message' => 'Unsupported media type.', 'streams' => [], 'errors' => []];
        }

        if (!str_starts_with($videoId, 'tt')) {
            return ['success' => false, 'message' => 'No IMDb ID available for this title.', 'streams' => [], 'errors' => []];
        }

        $resolved = $this->getEnabledStreamAddons($type);
        $targets = $resolved['addons'];
        $errors = $resolved['errors'];

        if (empty($targets)) {
            return ['success' => true, 'streams' => [], 'errors' => $errors];
        }

        $promises = [];
        foreach ($targets as $index => $target) {
            $url = rtrim($target['base_url'], '/') . '/stream/' . $type . '/' . $this->encodePathId($videoId) . '.json';
            if (!empty($target['query'])) {
                $url .= '?' . $target['query'];
            }
            $promises[$index] = $this->client->getAsync($url, [
                'timeout' => max(3, min(20, (int) ($target['timeout'] ?? 10))),
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        }

        $settled = PromiseUtils::settle($promises)->wait();
        $streams = [];
        $seen = [];

        foreach ($settled as $index => $outcome) {
            $target = $targets[$index];
            if (($outcome['state'] ?? '') !== 'fulfilled') {
                $errors[] = ['addon' => $target['name'], 'error' => 'Request failed.'];
                continue;
            }

            $response = $outcome['value'];
            if ($response->getStatusCode() !== 200) {
                $errors[] = ['addon' => $target['name'], 'error' => 'HTTP ' . $response->getStatusCode()];
                continue;
            }

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                $errors[] = ['addon' => $target['name'], 'error' => 'Invalid JSON response.'];
                continue;
            }

            foreach ($data['streams'] ?? [] as $stream) {
                if (!is_array($stream)) {
                    continue;
                }

                $infoHash = strtolower((string) ($stream['infoHash'] ?? $stream['info_hash'] ?? ''));
                $hasTorrent = preg_match('/^[a-f0-9]{40}$/', $infoHash) === 1;
                $streamUrl = isset($stream['url']) && is_string($stream['url']) ? trim($stream['url']) : '';
                $hasUrl = false;
                if ($streamUrl !== '') {
                    $urlCheck = $this->urlGuard->validate($streamUrl);
                    $hasUrl = (bool) ($urlCheck['allowed'] ?? false);
                    if (!$hasUrl && !$hasTorrent) {
                        $errors[] = [
                            'addon' => $target['name'],
                            'error' => 'Direct stream rejected: ' . (string) ($urlCheck['reason'] ?? 'invalid URL'),
                        ];
                        continue;
                    }
                }

                if (!$hasTorrent && !$hasUrl) {
                    continue;
                }

                $fileIdx = $hasTorrent && isset($stream['fileIdx']) && is_numeric($stream['fileIdx'])
                    ? (int) $stream['fileIdx']
                    : null;
                $dedupeKey = $hasTorrent ? ($infoHash . '|' . ($fileIdx ?? 'none')) : ('url|' . md5($streamUrl));
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $title = (string) ($stream['title'] ?? $stream['description'] ?? '');
                $name = (string) ($stream['name'] ?? '');
                $filename = $stream['behaviorHints']['filename'] ?? null;
                $videoSize = isset($stream['behaviorHints']['videoSize']) ? (int) $stream['behaviorHints']['videoSize'] : 0;
                $parsedSize = $videoSize > 0
                    ? $this->formatBytes($videoSize)
                    : $this->parseSize($name . ' ' . $title);

                $streams[] = [
                    'addon' => $target['name'],
                    'addon_id' => $target['id'],
                    'priority' => $target['priority'] ?? 100,
                    'name' => $name,
                    'title' => $title,
                    'info_hash' => $hasTorrent ? $infoHash : null,
                    'file_idx' => $fileIdx,
                    'url' => $hasUrl ? $streamUrl : null,
                    'kind' => $hasTorrent ? 'torrent' : 'direct',
                    'filename' => $filename,
                    'sources' => is_array($stream['sources'] ?? null) ? array_values($stream['sources']) : [],
                    'quality' => $this->parseQuality($name . ' ' . $title),
                    'size' => $parsedSize,
                    'seeders' => $this->parseSeeders($name . ' ' . $title),
                ];
            }
        }

        $byAddon = [];
        foreach ($streams as $stream) {
            $byAddon[$stream['addon_id']][] = $stream;
        }

        $balanced = [];
        foreach ($byAddon as $group) {
            usort($group, static fn ($a, $b) => ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0));
            $balanced = array_merge($balanced, array_slice($group, 0, 40));
        }

        usort($balanced, static function ($a, $b) {
            $priority = ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100);
            if ($priority !== 0) {
                return $priority;
            }
            return ($b['seeders'] ?? 0) <=> ($a['seeders'] ?? 0);
        });

        $streams = array_slice($balanced, 0, 100);

        return ['success' => true, 'streams' => $streams, 'errors' => $errors];
    }

    public function normalizeAddon(array $addon): array
    {
        $url = trim((string) ($addon['manifest_url'] ?? $addon['url'] ?? ''));
        return [
            'id' => (string) ($addon['id'] ?? hash('sha256', $url)),
            'name' => trim((string) ($addon['name'] ?? 'Unnamed addon')),
            'manifest_url' => $url,
            'enabled' => (bool) ($addon['enabled'] ?? true),
            'priority' => (int) ($addon['priority'] ?? 100),
            'timeout' => (int) ($addon['timeout'] ?? 10),
        ];
    }

    private function supportsCatalog(array $manifest): bool
    {
        foreach ($manifest['resources'] ?? [] as $resource) {
            if (is_string($resource) && $resource === 'catalog') {
                return true;
            }
            if (is_array($resource) && ($resource['name'] ?? '') === 'catalog') {
                return true;
            }
        }

        return false;
    }

    private function catalogGenres(array $catalog): array
    {
        $genres = [];
        foreach ($catalog['extra'] ?? [] as $extra) {
            if (($extra['name'] ?? '') !== 'genre') {
                continue;
            }
            foreach ($extra['options'] ?? [] as $option) {
                if (is_string($option) && $option !== 'None') {
                    $genres[] = $option;
                }
            }
        }

        return array_values(array_unique($genres));
    }

    private function fetchCatalogPayload(array $target, string $type, string $catalogId, ?string $genre, int $skip): array
    {
        $base = rtrim($target['base_url'], '/');
        $path = '/catalog/' . rawurlencode($type) . '/' . $this->encodeCatalogId($catalogId);
        $extra = [];
        if ($genre !== null && $genre !== '') {
            $extra[] = 'genre=' . rawurlencode($genre);
        }
        if ($skip > 0) {
            $extra[] = 'skip=' . $skip;
        }
        if (!empty($extra)) {
            $path .= '/' . implode('&', $extra);
        }
        $path .= '.json';

        $url = $base . $path;
        if (!empty($target['query'])) {
            $url .= '?' . $target['query'];
        }

        try {
            return $this->requestCatalog($url);
        } catch (\Throwable $e) {
            if (empty($extra)) {
                throw $e;
            }

            parse_str($target['query'] ?? '', $query);
            if ($genre !== null && $genre !== '') {
                $query['genre'] = $genre;
            }
            if ($skip > 0) {
                $query['skip'] = $skip;
            }
            $fallback = $base . '/catalog/' . rawurlencode($type) . '/' . $this->encodeCatalogId($catalogId) . '.json';
            if (!empty($query)) {
                $fallback .= '?' . http_build_query($query);
            }

            return $this->requestCatalog($fallback);
        }
    }

    private function requestCatalog(string $url): array
    {
        $this->assertPublicHttpsUrl($url);
        $response = $this->client->get($url, [
            'timeout' => 20,
            'connect_timeout' => 8,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException('Catalog returned HTTP ' . $status . '.');
        }

        $body = (string) $response->getBody();
        if (strlen($body) > 6 * 1024 * 1024) {
            throw new \RuntimeException('Catalog response is too large.');
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['metas']) || !is_array($data['metas'])) {
            throw new \RuntimeException('Invalid catalog response.');
        }

        return ['success' => true, 'metas' => $data['metas'], 'meta' => $data['meta'] ?? []];
    }

    private function normalizeCatalogItem(array $item, string $fallbackType): ?array
    {
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $type = (string) ($item['type'] ?? $fallbackType);
        $mediaType = $type === 'movie' ? 'movie' : (in_array($type, ['series', 'tv', 'anime'], true) ? 'series' : null);
        $imdbId = $item['imdb_id'] ?? $item['_imdbId'] ?? (str_starts_with($id, 'tt') ? $id : null);
        $tmdbId = $item['_tmdbId'] ?? $item['tmdb_id'] ?? null;
        if (!is_numeric($tmdbId)) {
            $tmdbId = null;
        }

        $release = (string) ($item['releaseInfo'] ?? $item['year'] ?? '');

        return [
            'id' => $id,
            'type' => $type,
            'media_type' => $mediaType,
            'title' => (string) ($item['name'] ?? 'Unknown'),
            'year' => substr($release, 0, 4),
            'poster' => $item['poster'] ?? null,
            'backdrop' => $item['background'] ?? $item['backdrop'] ?? null,
            'overview' => (string) ($item['description'] ?? ''),
            'genres' => is_array($item['genres'] ?? null) ? $item['genres'] : [],
            'rating' => $item['imdbRating'] ?? $item['rating'] ?? null,
            'imdb_id' => $imdbId ? (string) $imdbId : null,
            'tmdb_id' => $tmdbId ? (int) $tmdbId : null,
            'runtime' => $item['runtime'] ?? null,
            'behavior_hints' => $item['behaviorHints'] ?? [],
        ];
    }

    private function supportsMeta(array $manifest, string $type): bool
    {
        $manifestTypes = is_array($manifest['types'] ?? null) ? $manifest['types'] : [];

        foreach ($manifest['resources'] ?? [] as $resource) {
            if (is_string($resource) && $resource === 'meta') {
                if (in_array($type, $manifestTypes, true) || in_array('all', $manifestTypes, true)) {
                    return true;
                }
                continue;
            }

            if (!is_array($resource) || ($resource['name'] ?? '') !== 'meta') {
                continue;
            }

            $types = $resource['types'] ?? $manifestTypes;
            if (!in_array($type, $types, true) && !in_array('all', $types, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function supportsStream(array $manifest, string $type): bool
    {
        $manifestTypes = is_array($manifest['types'] ?? null) ? $manifest['types'] : [];

        foreach ($manifest['resources'] ?? [] as $resource) {
            if (is_string($resource) && $resource === 'stream') {
                if (in_array($type, $manifestTypes, true) || in_array('all', $manifestTypes, true)) {
                    return true;
                }
                continue;
            }

            if (!is_array($resource) || ($resource['name'] ?? '') !== 'stream') {
                continue;
            }

            $types = $resource['types'] ?? $manifestTypes;
            if (!in_array($type, $types, true) && !in_array('all', $types, true)) {
                continue;
            }

            $prefixes = $resource['idPrefixes'] ?? $manifest['idPrefixes'] ?? [];
            if (!empty($prefixes) && !in_array('tt', $prefixes, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function fetchJson(string $url, int $maxRedirects = 3): array
    {
        $initialUrl = trim($url);
        $cacheKey = 'media_addon_manifest_' . md5($initialUrl);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($initialUrl, $maxRedirects) {
            $item->expiresAfter(900);
            $currentUrl = $initialUrl;

            for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
                $this->assertPublicHttpsUrl($currentUrl);

                $response = $this->client->get($currentUrl, [
                    'timeout' => 10,
                    'connect_timeout' => 6,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['Accept' => 'application/json'],
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaderLine('Location');
                    if ($location === '') {
                        throw new \RuntimeException('Redirect without Location header.');
                    }
                    $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
                    continue;
                }

                if ($status !== 200) {
                    throw new \RuntimeException('Addon returned HTTP ' . $status . '.');
                }

                $body = (string) $response->getBody();
                if (strlen($body) > 2 * 1024 * 1024) {
                    throw new \RuntimeException('Manifest is too large.');
                }

                $data = json_decode($body, true);
                if (!is_array($data)) {
                    throw new \RuntimeException('Invalid JSON manifest.');
                }

                return ['data' => $data, 'url' => $currentUrl];
            }

            throw new \RuntimeException('Too many redirects.');
        });
    }

    private function assertPublicHttpsUrl(string $url): void
    {
        $result = $this->urlGuard->validateHttps($url);
        if (empty($result['allowed'])) {
            throw new \RuntimeException((string) ($result['reason'] ?? 'Addon URL is not allowed.'));
        }
    }

    private function deriveBaseUrl(string $manifestUrl): string
    {
        $parts = parse_url($manifestUrl);
        $path = $parts['path'] ?? '';
        $path = preg_replace('#/manifest\.json$#', '', $path) ?: '';
        if ($path === '') {
            $path = rtrim(dirname($parts['path'] ?? '/'), '/');
        }

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') .
            (!empty($parts['port']) ? ':' . $parts['port'] : '') . rtrim($path, '/');
    }

    private function encodeCatalogId(string $id): string
    {
        return str_replace('%3A', ':', rawurlencode($id));
    }

    private function encodePathId(string $id): string
    {
        return str_replace('%3A', ':', rawurlencode($id));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function parseQuality(string $text): ?string
    {
        if (preg_match('/\b(2160p|1080p|720p|480p|4K|UHD)\b/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }

    private function parseSize(string $text): ?string
    {
        if (preg_match('/(?:💾|📦)\s*(\d+(?:[.,]\d+)?)\s*(TB|GB|MB|TO|GO|MO)\b/iu', $text, $matches)) {
            return $this->normalizeSize($matches[1], $matches[2]);
        }
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(TB|GB|MB|TO|GO|MO)\b(?!\/)/', $text, $matches)) {
            return $this->normalizeSize($matches[1], $matches[2]);
        }
        return null;
    }

    private function normalizeSize(string $value, string $unit): string
    {
        $unit = strtoupper($unit);
        $unit = match ($unit) {
            'TO' => 'TB',
            'GO' => 'GB',
            'MO' => 'MB',
            default => $unit,
        };
        return str_replace(',', '.', $value) . ' ' . $unit;
    }

    private function parseSeeders(string $text): ?int
    {
        if (preg_match('/(?:👤|👥|seeders?)\s*[:]?\s*(\d+)/iu', $text, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
}
