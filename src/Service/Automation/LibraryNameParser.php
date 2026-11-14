<?php

namespace App\Service\Automation;

class LibraryNameParser
{
    public const VERSION = 3;

    private const RELEASE_TOKENS = [
        '2160p', '1080p', '720p', '480p', '4k', 'uhd', 'bluray', 'blu-ray',
        'webrip', 'web-dl', 'webdl', 'hdtv', 'x264', 'x265', 'h264', 'h265',
        'hevc', 'av1', 'aac', 'dts', 'ddp', 'atmos', 'proper', 'repack',
        'extended', 'remastered', 'multi', 'vf', 'vostfr', 'truefrench',
        'french', 'divx', 'xvid',
    ];

    private array $nfoCache = [];

    public function parse(string $path, string $rootPath, string $rootKey, string $rootType, array $scanner = []): array
    {
        $relativePath = $this->relativePath($path, $rootPath);
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), static fn ($segment) => $segment !== ''));
        $filename = $segments ? (string) array_pop($segments) : basename($path);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);
        $quality = $this->parseQuality($filename . ' ' . implode(' ', $segments));

        $type = match ($rootType) {
            'movie' => 'movie',
            'anime' => 'anime',
            default => 'series',
        };

        $readNfo = (bool) ($scanner['read_nfo'] ?? true);
        $nfo = $readNfo ? $this->readNfoForEntry($path, $type, $stem, $segments, $rootPath) : [];

        if ($type === 'movie') {
            return $this->parseMovie($path, $rootKey, $relativePath, $filename, $extension, $stem, $segments, $quality, $nfo);
        }

        return $this->parseSeries($path, $rootKey, $relativePath, $type, $filename, $extension, $stem, $segments, $quality, $nfo);
    }

    private function parseMovie(
        string $path,
        string $rootKey,
        string $relativePath,
        string $filename,
        string $extension,
        string $stem,
        array $segments,
        ?string $quality,
        array $nfo
    ): array {
        $folderPath = dirname($relativePath);
        $folderPath = $folderPath === '.' ? '' : $folderPath;
        $folder = $segments ? (string) end($segments) : '';
        $folderParsed = $this->extractTitleYear($folder);
        $stemParsed = $this->extractTitleYear($stem);

        $title = !empty($nfo['title']) ? $this->cleanTitle((string) $nfo['title']) : '';
        $year = !empty($nfo['year']) ? (int) $nfo['year'] : null;
        $source = ($title !== '' || $year !== null) ? 'nfo' : 'filename';

        if ($title === '') {
            if ($folderParsed['title'] !== '' && ($folderParsed['year'] !== null || $stemParsed['year'] === null) && !$this->isGenericMovieFolder($folder)) {
                $title = $folderParsed['title'];
                $year = $year ?? $folderParsed['year'] ?? $stemParsed['year'];
            } else {
                $title = $stemParsed['title'];
                $year = $year ?? $stemParsed['year'] ?? $folderParsed['year'];
            }
        }

        if ($year === null) {
            $year = $stemParsed['year'] ?? $folderParsed['year'];
        }

        $title = $title !== '' ? $title : $this->cleanTitle($stem);
        $normalized = $this->normalizeTitle($title);
        $tmdbId = isset($nfo['tmdb_id']) ? (int) $nfo['tmdb_id'] : null;
        $imdbId = isset($nfo['imdb_id']) ? (string) $nfo['imdb_id'] : null;
        $confidence = $this->movieConfidence($title, $year, $tmdbId, $imdbId);

        return [
            'root_key' => $rootKey,
            'path' => $path,
            'relative_path' => $relativePath,
            'folder_path' => $folderPath,
            'filename' => $filename,
            'extension' => $extension,
            'media_type' => 'movie',
            'title' => $title,
            'title_normalized' => $normalized,
            'year' => $year,
            'season' => null,
            'episode' => null,
            'quality' => $quality,
            'media_key' => $this->movieKey($normalized, $year, $tmdbId, $imdbId),
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'confidence' => $confidence,
            'parse_source' => $source,
            'parser_version' => self::VERSION,
            'show_folder' => null,
        ];
    }

    private function parseSeries(
        string $path,
        string $rootKey,
        string $relativePath,
        string $mediaType,
        string $filename,
        string $extension,
        string $stem,
        array $segments,
        ?string $quality,
        array $nfo
    ): array {
        $folderPath = dirname($relativePath);
        $folderPath = $folderPath === '.' ? '' : $folderPath;
        $seasonIndex = null;
        foreach ($segments as $index => $segment) {
            if ($this->extractSeasonFromFolder($segment) !== null) {
                $seasonIndex = $index;
                break;
            }
        }

        $showIndex = $seasonIndex !== null && $seasonIndex > 0 ? $seasonIndex - 1 : 0;
        $showSegment = $segments[$showIndex] ?? '';
        $showParsed = $this->extractTitleYear($showSegment);
        $showTitle = !empty($nfo['showtitle']) ? $this->cleanTitle((string) $nfo['showtitle']) : $showParsed['title'];
        $showYear = !empty($nfo['year']) ? (int) $nfo['year'] : $showParsed['year'];

        if ($showTitle === '') {
            $showParsed = $this->extractTitleYear($stem);
            $showTitle = $showParsed['title'] !== '' ? $showParsed['title'] : $this->cleanTitle($stem);
            $showYear = $showYear ?? $showParsed['year'];
        }

        $season = null;
        if ($seasonIndex !== null) {
            $season = $this->extractSeasonFromFolder((string) $segments[$seasonIndex]);
        }

        $episode = null;
        $explicitEpisode = false;
        $multiEpisode = preg_match('/\bS\d{1,2}[\s._-]*E\d{1,3}(?:[\s._-]*(?:E|-E|\.E)\d{1,3})+\b/i', $stem) === 1;

        if (preg_match('/\bS(\d{1,2})[\s._-]*E(\d{1,3})(?!\d)/i', $stem, $matches)) {
            $season = (int) $matches[1];
            $episode = (int) $matches[2];
            $explicitEpisode = true;
        } elseif (preg_match('/\b(\d{1,2})x(\d{1,3})(?!\d)/i', $stem, $matches)) {
            $season = (int) $matches[1];
            $episode = (int) $matches[2];
            $explicitEpisode = true;
        } elseif (preg_match('/\b(?:EP?|Episode|Épisode)[\s._-]*(\d{1,3})\b/i', $stem, $matches)) {
            $episode = (int) $matches[1];
            $explicitEpisode = true;
        } else {
            if (preg_match('/[\s._\[-](\d{1,3})(?:[\s._\]]|$)/', $stem, $matches)) {
                $candidate = (int) $matches[1];
                if ($candidate > 0 && !$this->looksLikeYear($candidate)) {
                    $episode = $candidate;
                }
            }
        }

        if (!empty($nfo['season'])) {
            $season = (int) $nfo['season'];
        }
        if (!empty($nfo['episode'])) {
            $episode = (int) $nfo['episode'];
            $explicitEpisode = true;
        }

        $normalized = $this->normalizeTitle($showTitle);
        $tmdbId = isset($nfo['tmdb_id']) ? (int) $nfo['tmdb_id'] : null;
        $imdbId = isset($nfo['imdb_id']) ? (string) $nfo['imdb_id'] : null;
        $showFolder = implode('/', array_slice($segments, 0, $showIndex + 1));
        $confidence = $this->seriesConfidence($showTitle, $showYear, $season, $episode, $explicitEpisode, $tmdbId, $imdbId);
        if ($multiEpisode) {
            $confidence = min($confidence, 0.6);
        }

        return [
            'root_key' => $rootKey,
            'path' => $path,
            'relative_path' => $relativePath,
            'folder_path' => $folderPath,
            'filename' => $filename,
            'extension' => $extension,
            'media_type' => $mediaType,
            'title' => $showTitle,
            'title_normalized' => $normalized,
            'year' => $showYear,
            'season' => $season,
            'episode' => $episode,
            'quality' => $quality,
            'media_key' => $this->seriesKey($normalized, $showYear, $season, $episode, $tmdbId, $imdbId),
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'confidence' => $confidence,
            'parse_source' => $multiEpisode ? 'multi_episode' : (($tmdbId || $imdbId) ? 'nfo' : 'filename'),
            'parser_version' => self::VERSION,
            'show_folder' => $showFolder,
        ];
    }

    private function readNfoForEntry(string $path, string $type, string $stem, array $segments, string $rootPath): array
    {
        $dir = dirname($path);
        $files = [];

        if ($type === 'movie') {
            $files[] = $dir . DIRECTORY_SEPARATOR . 'movie.nfo';
            $files[] = $dir . DIRECTORY_SEPARATOR . $stem . '.nfo';
        } else {
            $seasonIndex = null;
            foreach ($segments as $index => $segment) {
                if ($this->extractSeasonFromFolder($segment) !== null) {
                    $seasonIndex = $index;
                    break;
                }
            }

            $showIndex = $seasonIndex !== null && $seasonIndex > 0 ? $seasonIndex - 1 : 0;
            $showSegments = array_slice($segments, 0, $showIndex + 1);
            $showDir = rtrim($rootPath, '/\\');
            if ($showSegments) {
                $showDir .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $showSegments);
            }

            $files[] = $showDir . DIRECTORY_SEPARATOR . 'tvshow.nfo';
            $files[] = $dir . DIRECTORY_SEPARATOR . 'season.nfo';
            $files[] = $dir . DIRECTORY_SEPARATOR . $stem . '.nfo';
        }

        $result = [];
        foreach (array_unique($files) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $parsed = $this->readNfoFile($file);
            if (basename($file) === 'tvshow.nfo' && empty($parsed['showtitle']) && !empty($parsed['title'])) {
                $parsed['showtitle'] = $parsed['title'];
            }

            foreach ($parsed as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                if (in_array($key, ['tmdb_id', 'imdb_id'], true) && isset($result[$key])) {
                    continue;
                }

                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function readNfoFile(string $file): array
    {
        if (!function_exists('simplexml_load_file')) {
            return [];
        }

        $mtime = @filemtime($file) ?: 0;
        if (isset($this->nfoCache[$file]) && $this->nfoCache[$file]['mtime'] === $mtime) {
            return $this->nfoCache[$file]['data'];
        }

        $xml = @simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$xml) {
            return [];
        }

        $result = [];
        $title = trim((string) ($xml->title ?? ''));
        $showTitle = trim((string) ($xml->showtitle ?? ''));
        $year = trim((string) ($xml->year ?? ''));
        $tmdb = trim((string) ($xml->tmdbid ?? ''));
        $imdb = trim((string) ($xml->imdbid ?? ''));
        $season = trim((string) ($xml->season ?? ''));
        $episode = trim((string) ($xml->episode ?? ''));

        if ($title !== '') {
            $result['title'] = $title;
        }
        if ($showTitle !== '') {
            $result['showtitle'] = $showTitle;
        }
        if ($year !== '' && ctype_digit($year)) {
            $result['year'] = (int) $year;
        }
        if ($tmdb !== '' && ctype_digit($tmdb)) {
            $result['tmdb_id'] = (int) $tmdb;
        }
        if (preg_match('/^tt\d+$/i', $imdb)) {
            $result['imdb_id'] = $imdb;
        }
        if ($season !== '' && ctype_digit($season)) {
            $result['season'] = (int) $season;
        }
        if ($episode !== '' && ctype_digit($episode)) {
            $result['episode'] = (int) $episode;
        }

        if (!empty($xml->uniqueid)) {
            foreach ($xml->uniqueid as $uniqueId) {
                $value = trim((string) $uniqueId);
                $type = strtolower(trim((string) ($uniqueId['type'] ?? '')));
                if ($value === '') {
                    continue;
                }
                if ($type === 'tmdb' && ctype_digit($value) && !isset($result['tmdb_id'])) {
                    $result['tmdb_id'] = (int) $value;
                } elseif ($type === 'imdb' && preg_match('/^tt\d+$/i', $value) && !isset($result['imdb_id'])) {
                    $result['imdb_id'] = $value;
                }
            }
        }

        $this->nfoCache[$file] = ['mtime' => $mtime, 'data' => $result];

        return $result;
    }

    private function extractTitleYear(string $text): array
    {
        $text = trim(preg_replace('/\s+/', ' ', str_replace(['_', '.'], ' ', $text)));
        $maxYear = (int) date('Y') + 1;

        if (preg_match_all('/\((\d{4})\)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach (array_reverse($matches[1]) as $match) {
                $year = (int) $match[0];
                if ($year < 1900 || $year > $maxYear) {
                    continue;
                }

                $title = $this->cleanTitle(trim(substr($text, 0, $match[1])));
                if ($title !== '') {
                    return ['title' => $title, 'year' => $year];
                }
            }
        }

        $stripped = $this->stripTrailingReleaseTokens($text);
        if (preg_match('/\b(\d{4})$/u', $stripped, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= $maxYear) {
                $title = $this->cleanTitle(trim(substr($stripped, 0, -4)));
                if ($title !== '') {
                    return ['title' => $title, 'year' => $year];
                }
            }
        }

        return ['title' => $this->cleanTitle($text), 'year' => null];
    }

    private function stripTrailingReleaseTokens(string $text): string
    {
        $text = preg_replace('/\s+-\s+[A-Za-z0-9][A-Za-z0-9._-]{1,20}$/u', '', $text) ?? $text;

        $pattern = '/\s+(?:' . implode('|', array_map(static fn ($token) => preg_quote($token, '/'), self::RELEASE_TOKENS)) . ')$/iu';
        do {
            $previous = $text;
            $text = trim(preg_replace($pattern, '', $text) ?? $text);
        } while ($text !== $previous);

        return $text;
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/[\[\{\(].*?[\]\}\)]/u', ' ', $title) ?? $title;
        $title = str_replace(['_', '.'], ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}\'’]+/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title, " \t\n\r\0\x0B-'");
    }

    private function normalizeTitle(string $title): string
    {
        $original = $this->cleanTitle($title);
        $normalized = $original;

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($normalized, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $normalized = $decomposed;
            }
        }

        $normalized = preg_replace('/\p{M}+/u', '', $normalized) ?? $normalized;
        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            $normalized = 'x' . substr(sha1($original), 0, 12);
        }

        return $normalized;
    }

    private function parseQuality(string $text): ?string
    {
        if (preg_match('/\b(2160p|1080p|720p|480p|4k|uhd)\b/i', $text, $matches)) {
            $quality = strtoupper($matches[1]);
            return in_array($quality, ['4K', 'UHD'], true) ? '2160p' : $quality;
        }

        return null;
    }

    private function extractSeasonFromFolder(string $segment): ?int
    {
        if (preg_match('/\b(?:specials?|spéciaux?)\b/i', $segment)) {
            return 0;
        }

        if (preg_match('/\b(?:season|saison|series|s)[\s._-]*(\d{1,3})\b/i', $segment, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function looksLikeYear(int $value): bool
    {
        return $value >= 1900 && $value <= 2100;
    }

    private function isGenericMovieFolder(string $folder): bool
    {
        return in_array(strtolower(trim($folder)), ['movies', 'movie', 'films', 'film', 'video', 'videos', 'downloads'], true);
    }

    private function movieKey(string $normalizedTitle, ?int $year, ?int $tmdbId, ?string $imdbId): string
    {
        if ($tmdbId) {
            return 'movie:tmdb:' . $tmdbId;
        }
        if ($imdbId) {
            return 'movie:imdb:' . $imdbId;
        }

        return 'movie:' . $normalizedTitle . ':' . ($year ?: 'unknown');
    }

    private function seriesKey(string $normalizedTitle, ?int $year, ?int $season, ?int $episode, ?int $tmdbId, ?string $imdbId): string
    {
        $base = $tmdbId
            ? 'series:tmdb:' . $tmdbId
            : ($imdbId ? 'series:imdb:' . $imdbId : 'series:' . $normalizedTitle . ':' . ($year ?: 'unknown'));

        if ($season !== null && $episode !== null) {
            return $base . ':s' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        }
        if ($episode !== null) {
            return $base . ':e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        }

        return $base;
    }

    private function movieConfidence(string $title, ?int $year, ?int $tmdbId, ?string $imdbId): float
    {
        if ($tmdbId || $imdbId) {
            return 0.99;
        }
        if ($title === '') {
            return 0.3;
        }

        return $year ? 0.92 : 0.68;
    }

    private function seriesConfidence(string $title, ?int $year, ?int $season, ?int $episode, bool $explicitEpisode, ?int $tmdbId, ?string $imdbId): float
    {
        if ($tmdbId || $imdbId) {
            return 0.99;
        }
        if ($title === '') {
            return 0.35;
        }
        if ($explicitEpisode && $season !== null && $episode !== null) {
            return $year ? 0.96 : 0.78;
        }
        if ($episode !== null) {
            return $year ? 0.75 : 0.7;
        }

        return 0.5;
    }

    private function relativePath(string $path, string $rootPath): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';

        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return basename($normalizedPath);
    }
}
