<?php

namespace App\Service\Search;

class GlobalSearchService
{
    public const GROUP_ORDER = ['actions', 'torrents', 'media', 'music', 'history'];

    private const LOCAL_GROUPS = ['actions', 'torrents', 'history'];
    private const REMOTE_GROUPS = ['media', 'music'];

    private const SCOPES = [
        'all' => self::GROUP_ORDER,
        'local' => self::LOCAL_GROUPS,
        'remote' => self::REMOTE_GROUPS,
        'actions' => ['actions'],
        'torrents' => ['torrents'],
        'media' => ['media'],
        'music' => ['music'],
        'history' => ['history'],
    ];

    /** @var array<string, SearchProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<SearchProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private int $defaultLimit = 8,
        private int $maxLimit = 20,
        private int $maxQueryLength = 100
    ) {
        foreach ($providers as $provider) {
            if ($provider instanceof SearchProviderInterface) {
                $this->providers[$provider->key()] = $provider;
            }
        }
    }

    public function search(string $query, ?string $scope = null, ?int $limit = null): array
    {
        $query = $this->normalizeQuery($query);
        $scope = strtolower(trim((string) $scope));
        if ($scope === 'discover') {
            $scope = 'media';
        }
        $activeGroups = self::SCOPES[$scope] ?? self::GROUP_ORDER;
        $limit = $this->normalizeLimit($limit);

        $groups = array_fill_keys(self::GROUP_ORDER, []);
        $errors = [];
        $partial = false;

        if ($query !== '') {
            foreach (self::GROUP_ORDER as $key) {
                if (!in_array($key, $activeGroups, true) || !isset($this->providers[$key])) {
                    continue;
                }

                $provider = $this->providers[$key];

                try {
                    $rawResults = $provider->search($query, $limit);
                    if (!is_array($rawResults)) {
                        $rawResults = [];
                    }

                    $seen = [];
                    $results = [];
                    foreach ($rawResults as $rawResult) {
                        if (!is_array($rawResult)) {
                            continue;
                        }

                        $result = $this->normalizeResult($rawResult, $provider);
                        if ($result === null) {
                            continue;
                        }

                        $dedupeKey = $result['source'] . '|' . (
                            $result['id'] !== ''
                                ? $result['id']
                                : mb_strtolower($result['title'], 'UTF-8') . '|' . (string) $result['url']
                        );
                        if (isset($seen[$dedupeKey])) {
                            continue;
                        }
                        $seen[$dedupeKey] = true;

                        $results[] = $result;
                        if (count($results) >= $limit) {
                            break;
                        }
                    }

                    $groups[$key] = $results;
                } catch (\Throwable $e) {
                    $partial = true;
                    $errors[] = [
                        'provider' => $key,
                        'label' => $provider->label(),
                        'message' => 'Search provider failed.',
                    ];
                }
            }
        }

        return [
            'success' => true,
            'query' => $query,
            'partial' => $partial,
            'errors' => $errors,
            'groups' => $groups,
        ];
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') > $this->maxQueryLength) {
            $query = mb_substr($query, 0, $this->maxQueryLength, 'UTF-8');
        }

        return $query;
    }

    private function normalizeLimit(?int $limit): int
    {
        $limit = $limit ?? $this->defaultLimit;

        return max(1, min($limit, $this->maxLimit));
    }

    private function normalizeResult(array $item, SearchProviderInterface $provider): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $subtitle = trim((string) ($item['subtitle'] ?? ''));
        $meta = trim((string) ($item['meta'] ?? ''));
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = $provider->key() . '-' . sha1($title . '|' . $subtitle . '|' . $meta);
        }

        $icon = trim((string) ($item['icon'] ?? ''));
        if ($icon === '') {
            $icon = $provider->icon();
        }

        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'icon' => $icon,
            'url' => $this->sanitizeUrl($item['url'] ?? null),
            'source' => $provider->key(),
        ];
    }

    private function sanitizeUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x1F]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        if (preg_match('#^https://open\.spotify\.com/[A-Za-z0-9/_-]+(?:\?[A-Za-z0-9=&%._-]*)?$#', $url)) {
            return $url;
        }

        return null;
    }
}
