<?php

namespace App\Service\Automation;

use GuzzleHttp\Client;

class CinemetaSeriesNumberingProvider implements SeriesNumberingProviderInterface
{
    private AutomationStore $store;
    private Client $client;

    public function __construct(AutomationStore $store)
    {
        $this->store = $store;
        $this->client = new Client();
    }

    public function fetch(array $identity, bool $refresh = false): array
    {
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return ['success' => false, 'provider' => 'cinemeta', 'reason' => 'missing_imdb_id', 'videos' => [], 'source_ref' => null];
        }

        $cacheKey = 'series_numbering|cinemeta|' . $imdbId;
        $cached = $this->store->getMetadataCache($cacheKey);
        if (!$refresh && is_array($cached) && array_key_exists('result', $cached)) {
            return is_array($cached['result']) ? $cached['result'] : [];
        }

        try {
            $response = $this->client->get('https://v3-cinemeta.strem.io/meta/series/' . $imdbId . '.json', [
                'timeout' => 12,
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return ['success' => false, 'provider' => 'cinemeta', 'reason' => 'http_' . $response->getStatusCode(), 'videos' => [], 'source_ref' => null];
            }
            $data = json_decode((string) $response->getBody(), true);
            $meta = is_array($data) ? ($data['meta'] ?? null) : null;
            if (!is_array($meta)) {
                return ['success' => false, 'provider' => 'cinemeta', 'reason' => 'invalid_metadata', 'videos' => [], 'source_ref' => null];
            }
            $videos = [];
            foreach (($meta['videos'] ?? []) as $video) {
                if (!is_array($video) || !isset($video['season'], $video['episode']) || !is_numeric($video['season']) || !is_numeric($video['episode'])) {
                    continue;
                }
                $videos[] = [
                    'season' => (int) $video['season'],
                    'episode' => (int) $video['episode'],
                    'title' => (string) ($video['name'] ?? $video['title'] ?? ''),
                    'released' => isset($video['released']) ? (string) $video['released'] : null,
                    'id' => isset($video['id']) ? (string) $video['id'] : null,
                ];
            }
            if (empty($videos)) {
                return ['success' => false, 'provider' => 'cinemeta', 'reason' => 'no_videos', 'videos' => [], 'source_ref' => null];
            }
            usort($videos, static fn ($a, $b) => [$a['season'], $a['episode']] <=> [$b['season'], $b['episode']]);
            $result = [
                'success' => true,
                'provider' => 'cinemeta',
                'provider_label' => 'Cinemeta',
                'reason' => null,
                'source_ref' => 'imdb:' . $imdbId,
                'videos' => $videos,
                'meta' => ['name' => $meta['name'] ?? null, 'imdb_id' => $meta['imdb_id'] ?? $imdbId],
            ];
            $this->store->setMetadataCache($cacheKey, ['result' => $result], 12 * 3600);

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'provider' => 'cinemeta', 'reason' => $e->getMessage(), 'videos' => [], 'source_ref' => null];
        }
    }
}
