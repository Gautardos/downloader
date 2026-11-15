<?php

namespace App\Service\Automation;

use App\Service\TmdbService;

class TmdbMovieIdentityResolver implements MovieIdentityResolverInterface
{
    private TmdbService $tmdb;
    private AutomationStore $store;
    private AutomationSettings $settings;

    public function __construct(TmdbService $tmdb, AutomationStore $store, AutomationSettings $settings)
    {
        $this->tmdb = $tmdb;
        $this->store = $store;
        $this->settings = $settings;
    }

    public function resolve(array $intent): array
    {
        $imdbId = trim((string) ($intent['imdb_id'] ?? ''));
        $tmdbId = !empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null;
        $title = trim((string) ($intent['title'] ?? ''));
        $year = !empty($intent['year']) ? (int) $intent['year'] : null;

        if (preg_match('/^tt\d+$/', $imdbId)) {
            $identity = $this->fromImdb($imdbId, $title, $year);
            if ($identity !== null) {
                return $identity;
            }

            return [
                'success' => true,
                'tmdb_id' => $tmdbId,
                'imdb_id' => $imdbId,
                'title' => $title,
                'year' => $year,
                'reason' => null,
            ];
        }

        if ($tmdbId !== null) {
            $identity = $this->fromTmdb($tmdbId, $title, $year);
            if ($identity !== null) {
                return $identity;
            }

            return [
                'success' => false,
                'tmdb_id' => $tmdbId,
                'imdb_id' => null,
                'title' => $title,
                'year' => $year,
                'reason' => 'tmdb_details_unavailable',
            ];
        }

        if ($title === '') {
            return ['success' => false, 'tmdb_id' => null, 'imdb_id' => null, 'title' => $title, 'year' => $year, 'reason' => 'missing_identity'];
        }

        $cacheKey = 'release_identity|movie|' . $this->titleLanguage() . '|' . $this->normalizeTitle($title) . '|' . ($year ?? 'unknown');
        $cached = $this->store->getMetadataCache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $results = $this->tmdb->search($title, 'movie');
        $candidates = [];
        foreach ($results as $result) {
            if (($result['type'] ?? 'movie') !== 'movie') {
                continue;
            }
            $sameTitle = $this->normalizeTitle((string) ($result['title'] ?? '')) === $this->normalizeTitle($title)
                || $this->normalizeTitle((string) ($result['original_title'] ?? '')) === $this->normalizeTitle($title);
            $sameYear = $year === null || (int) ($result['year'] ?? 0) === $year;
            if ($sameTitle && $sameYear) {
                $candidates[] = $result;
            }
        }

        if (count($candidates) === 1) {
            $identity = $this->fromTmdb((int) $candidates[0]['id'], $title, $year);
            if ($identity !== null) {
                $this->store->setMetadataCache($cacheKey, $identity, 30 * 86400);
                return $identity;
            }
        }

        $reason = count($candidates) > 1 ? 'ambiguous_identity' : 'identity_not_found';
        $negative = ['success' => false, 'tmdb_id' => null, 'imdb_id' => null, 'title' => $title, 'year' => $year, 'reason' => $reason];
        $this->store->setMetadataCache($cacheKey, $negative, 2 * 86400);

        return $negative;
    }

    private function fromImdb(string $imdbId, string $fallbackTitle, ?int $fallbackYear): ?array
    {
        $details = $this->tmdb->findByImdb($imdbId, $this->titleLanguage());
        if ($details === null) {
            return null;
        }

        return [
            'success' => true,
            'tmdb_id' => !empty($details['id']) ? (int) $details['id'] : null,
            'imdb_id' => (string) ($details['imdb_id'] ?? $imdbId),
            'title' => (string) ($details['title'] ?? $fallbackTitle),
            'year' => !empty($details['year']) ? (int) $details['year'] : $fallbackYear,
            'reason' => null,
        ];
    }

    private function fromTmdb(int $tmdbId, string $fallbackTitle, ?int $fallbackYear): ?array
    {
        $details = $this->tmdb->details('movie', $tmdbId, $this->titleLanguage());
        if ($details === null) {
            return null;
        }
        $imdbId = trim((string) ($details['imdb_id'] ?? ''));
        if (!preg_match('/^tt\d+$/', $imdbId)) {
            return null;
        }

        return [
            'success' => true,
            'tmdb_id' => (int) ($details['id'] ?? $tmdbId),
            'imdb_id' => $imdbId,
            'title' => (string) ($details['title'] ?? $fallbackTitle),
            'year' => !empty($details['year']) ? (int) $details['year'] : $fallbackYear,
            'reason' => null,
        ];
    }

    private function titleLanguage(): string
    {
        $metadata = $this->settings->get('metadata', []);
        $language = trim((string) ($metadata['title_language'] ?? 'en-US'));

        return $language !== '' ? $language : 'en-US';
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($title, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $title = $decomposed;
            }
        }
        $title = preg_replace('/\p{M}+/u', '', $title) ?? $title;
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }
}
