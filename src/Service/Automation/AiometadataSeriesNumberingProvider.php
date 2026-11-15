<?php

namespace App\Service\Automation;

use App\Service\MediaAddonService;

class AiometadataSeriesNumberingProvider implements SeriesNumberingProviderInterface
{
    private MediaAddonService $addons;
    private AutomationStore $store;

    public function __construct(MediaAddonService $addons, AutomationStore $store)
    {
        $this->addons = $addons;
        $this->store = $store;
    }

    public function fetch(array $identity, bool $refresh = false): array
    {
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return ['success' => false, 'provider' => 'aiometadata', 'reason' => 'missing_imdb_id', 'videos' => [], 'source_ref' => null];
        }

        $cacheKey = 'series_numbering|aiometadata|' . $imdbId;
        $cached = $this->store->getMetadataCache($cacheKey);
        if (!$refresh && is_array($cached) && array_key_exists('result', $cached)) {
            return is_array($cached['result']) ? $cached['result'] : [];
        }

        $result = $this->addons->getMeta('series', $imdbId, ['aio-metadata']);
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : null;
        if (empty($result['success']) || $meta === null) {
            return $this->cachedOrFailure($cached, 'metadata_unavailable');
        }

        $videos = [];
        foreach (($meta['videos'] ?? []) as $video) {
            if (!is_array($video) || !isset($video['season'], $video['episode']) || !is_numeric($video['season']) || !is_numeric($video['episode'])) {
                continue;
            }
            $videos[] = [
                'season' => (int) $video['season'],
                'episode' => (int) $video['episode'],
                'title' => (string) ($video['title'] ?? $video['name'] ?? ''),
                'released' => isset($video['released']) ? (string) $video['released'] : (isset($video['air_date']) ? (string) $video['air_date'] : null),
                'id' => isset($video['id']) ? (string) $video['id'] : null,
            ];
        }

        if (empty($videos)) {
            return $this->cachedOrFailure($cached, 'no_videos');
        }

        usort($videos, static fn ($a, $b) => [$a['season'], $a['episode']] <=> [$b['season'], $b['episode']]);
        $result = [
            'success' => true,
            'provider' => (string) ($meta['_metaProvider'] ?? 'aiometadata'),
            'provider_label' => (string) ($result['addon_name'] ?? 'AIOMetadata'),
            'reason' => null,
            'source_ref' => isset($meta['_tvdbId']) ? 'tvdb:' . $meta['_tvdbId'] : (string) ($result['addon_id'] ?? ''),
            'videos' => $videos,
            'meta' => [
                'name' => $meta['name'] ?? null,
                'imdb_id' => $meta['imdb_id'] ?? $imdbId,
                'tmdb_id' => $meta['_tmdbId'] ?? null,
                'tvdb_id' => $meta['_tvdbId'] ?? null,
                'meta_provider' => $meta['_metaProvider'] ?? null,
            ],
        ];
        $this->store->setMetadataCache($cacheKey, ['result' => $result], 6 * 3600);

        return $result;
    }

    private function cachedOrFailure(mixed $cached, string $reason): array
    {
        if (is_array($cached) && is_array($cached['result'] ?? null) && !empty($cached['result']['success']) && !empty($cached['result']['videos'])) {
            $fallback = $cached['result'];
            $fallback['stale'] = true;

            return $fallback;
        }

        return ['success' => false, 'provider' => 'aiometadata', 'reason' => $reason, 'videos' => [], 'source_ref' => null];
    }
}
