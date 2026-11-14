<?php

namespace App\Service;

class DebridHistoryService
{
    private const CACHE_KEY = 'alldebrid_history_cache';
    private const CACHE_TTL = 60;
    private const MAX_LINKS = 500;

    private JsonStorage $storage;
    private AlldebridService $alldebrid;

    public function __construct(JsonStorage $storage, AlldebridService $alldebrid)
    {
        $this->storage = $storage;
        $this->alldebrid = $alldebrid;
    }

    public function page(int $offset = 0, int $limit = 5, bool $refresh = false): array
    {
        $cache = $this->storage->get(self::CACHE_KEY, []);
        $fetchedAt = (int) ($cache['fetched_at'] ?? 0);
        $links = $cache['links'] ?? [];

        if ($refresh || !is_array($links) || $fetchedAt === 0 || (time() - $fetchedAt) > self::CACHE_TTL) {
            $links = $this->normalizeLinks($this->alldebrid->getRecentLinks());
            $fetchedAt = time();
            $this->storage->set(self::CACHE_KEY, [
                'fetched_at' => $fetchedAt,
                'links' => $links,
            ]);
        }

        $total = count($links);
        $offset = max(0, $offset);
        $limit = max(1, min(50, $limit));
        $slice = array_slice($links, $offset, $limit);

        return [
            'links' => $slice,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + count($slice)) < $total,
            'fetched_at' => $fetchedAt,
        ];
    }

    public function invalidate(): void
    {
        $this->storage->set(self::CACHE_KEY, [
            'fetched_at' => 0,
            'links' => [],
        ]);
    }

    private function normalizeLinks(array $links): array
    {
        $normalized = [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = $link['link'] ?? $link['url'] ?? null;
            if (!$url) {
                continue;
            }

            $filename = (string) ($link['filename'] ?? '');
            if ($filename === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $filename = $path ? basename($path) : 'Unknown file';
            }

            $normalized[] = [
                'link' => (string) $url,
                'filename' => $filename,
                'size' => (int) ($link['size'] ?? 0),
                'date' => (int) ($link['date'] ?? 0),
                'host' => (string) ($link['host'] ?? ''),
            ];
        }

        usort($normalized, function (array $a, array $b) {
            return ($b['date'] ?? 0) <=> ($a['date'] ?? 0);
        });

        return array_slice($normalized, 0, self::MAX_LINKS);
    }
}
