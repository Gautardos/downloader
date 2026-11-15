<?php

namespace App\Service\Automation;

use GuzzleHttp\Client;

class TvmazeSeriesNumberingProvider implements SeriesNumberingProviderInterface
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
        $title = trim((string) ($identity['title'] ?? ''));
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'provider' => 'tvmaze', 'reason' => 'missing_title', 'videos' => [], 'source_ref' => null];
        }

        $cacheKey = 'series_numbering|tvmaze|' . md5($title . '|' . $imdbId);
        $cached = $this->store->getMetadataCache($cacheKey);
        if (!$refresh && is_array($cached) && array_key_exists('result', $cached)) {
            return is_array($cached['result']) ? $cached['result'] : [];
        }

        try {
            $search = json_decode((string) $this->client->get('https://api.tvmaze.com/search/shows', [
                'timeout' => 12,
                'query' => ['q' => $title],
                'http_errors' => false,
                'headers' => ['Accept: application/json'],
            ])->getBody(), true);
            $show = null;
            foreach (is_array($search) ? $search : [] as $entry) {
                $candidate = is_array($entry['show'] ?? null) ? $entry['show'] : null;
                if ($candidate === null) {
                    continue;
                }
                $candidateImdb = trim((string) ($candidate['externals']['imdb'] ?? ''));
                if ($imdbId !== '' && $candidateImdb !== $imdbId) {
                    continue;
                }
                $show = $candidate;
                break;
            }
            if ($show === null || empty($show['id'])) {
                return ['success' => false, 'provider' => 'tvmaze', 'reason' => 'show_not_found', 'videos' => [], 'source_ref' => null];
            }

            $episodes = json_decode((string) $this->client->get('https://api.tvmaze.com/shows/' . (int) $show['id'] . '/episodes', [
                'timeout' => 15,
                'query' => ['specials' => 1],
                'http_errors' => false,
                'headers' => ['Accept: application/json'],
            ])->getBody(), true);
            $videos = [];
            foreach (is_array($episodes) ? $episodes : [] as $episode) {
                if (!isset($episode['season'], $episode['number']) || !is_numeric($episode['season']) || !is_numeric($episode['number'])) {
                    continue;
                }
                $videos[] = [
                    'season' => (int) $episode['season'],
                    'episode' => (int) $episode['number'],
                    'title' => (string) ($episode['name'] ?? ''),
                    'released' => isset($episode['airdate']) && $episode['airdate'] !== '' ? (string) $episode['airdate'] : null,
                    'id' => null,
                ];
            }
            if (empty($videos)) {
                return ['success' => false, 'provider' => 'tvmaze', 'reason' => 'no_episodes', 'videos' => [], 'source_ref' => null];
            }
            usort($videos, static fn ($a, $b) => [$a['season'], $a['episode']] <=> [$b['season'], $b['episode']]);
            $result = [
                'success' => true,
                'provider' => 'tvmaze',
                'provider_label' => 'TVmaze',
                'reason' => null,
                'source_ref' => 'tvmaze:' . (int) $show['id'],
                'videos' => $videos,
                'meta' => ['name' => $show['name'] ?? null, 'imdb_id' => $imdbId],
            ];
            $this->store->setMetadataCache($cacheKey, ['result' => $result], 12 * 3600);

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'provider' => 'tvmaze', 'reason' => $e->getMessage(), 'videos' => [], 'source_ref' => null];
        }
    }
}
