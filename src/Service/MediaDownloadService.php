<?php

namespace App\Service;

use GuzzleHttp\Client;

class MediaDownloadService
{
    private AlldebridService $debrid;
    private QueueManager $queueManager;
    private JsonStorage $storage;
    private Client $client;
    private SafeUrlGuard $urlGuard;

    public function __construct(AlldebridService $debrid, QueueManager $queueManager, JsonStorage $storage, ?SafeUrlGuard $urlGuard = null)
    {
        $this->debrid = $debrid;
        $this->queueManager = $queueManager;
        $this->storage = $storage;
        $this->client = new Client();
        $this->urlGuard = $urlGuard ?? new SafeUrlGuard();
    }

    public function addStream(array $payload, ?callable $heartbeat = null): array
    {
        $infoHash = strtolower(trim((string) ($payload['info_hash'] ?? '')));
        $streamUrl = trim((string) ($payload['url'] ?? ''));
        $season = isset($payload['season']) && is_numeric($payload['season']) ? (int) $payload['season'] : null;
        $episode = isset($payload['episode']) && is_numeric($payload['episode']) ? (int) $payload['episode'] : null;
        $releaseSeason = isset($payload['release_season']) && is_numeric($payload['release_season']) ? (int) $payload['release_season'] : $season;
        $releaseEpisode = isset($payload['release_episode']) && is_numeric($payload['release_episode']) ? (int) $payload['release_episode'] : $episode;
        $expectedFilename = trim((string) ($payload['expected_filename'] ?? ''));
        $episodeHint = trim(implode(' ', array_filter([
            $expectedFilename,
            (string) ($payload['filename'] ?? ''),
            (string) ($payload['title'] ?? ''),
        ])));

        $path = trim((string) ($payload['path'] ?? ''));
        if ($path === '') {
            $config = $this->storage->get('config', []);
            $path = (string) ($config['default_path'] ?? '');
        }
        if ($path === '') {
            return ['success' => false, 'message' => 'No destination path configured.'];
        }
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;

        if (!preg_match('/^[a-f0-9]{40}$/', $infoHash)) {
            if (!preg_match('#^https?://#i', $streamUrl)) {
                return ['success' => false, 'message' => 'Invalid stream: missing torrent hash or direct URL.'];
            }
            return $this->queueDirectStream($payload, $streamUrl, $path);
        }

        $fileIdx = isset($payload['file_idx']) && is_numeric($payload['file_idx']) ? (int) $payload['file_idx'] : null;
        $cached = !empty($payload['cached']);
        $preferredFilename = trim((string) ($payload['filename'] ?? ''));
        if ($preferredFilename === '') {
            $preferredFilename = trim((string) ($payload['title'] ?? ''));
        }

        $magnet = $this->buildMagnet($infoHash, $preferredFilename, $payload['sources'] ?? []);
        $upload = $this->debrid->uploadMagnet([$magnet]);
        if (empty($upload['success'])) {
            return ['success' => false, 'message' => $upload['message'] ?? 'Could not add magnet to Alldebrid.'];
        }

        $magnetId = $upload['data']['magnets'][0]['id'] ?? $upload['data']['files'][0]['id'] ?? null;
        if (!$magnetId) {
            return ['success' => false, 'message' => 'Alldebrid did not return a magnet ID.'];
        }

        $magnetData = $this->waitForMagnet((int) $magnetId, $heartbeat, $cached ? 15 : 60);
        if (!$magnetData) {
            return [
                'success' => false,
                'message' => $cached
                    ? 'Alldebrid did not return torrent files in time.'
                    : 'Alldebrid is still caching this uncached torrent; retry later.',
            ];
        }

        $statusCode = (int) ($magnetData['statusCode'] ?? 0);
        if ($statusCode > 4) {
            return ['success' => false, 'message' => 'Alldebrid could not prepare this torrent: ' . ($magnetData['status'] ?? 'unknown status')];
        }

        $files = $this->flattenFiles($magnetData['links'] ?? $magnetData['files'] ?? []);
        if (empty($files)) {
            return ['success' => false, 'message' => 'No downloadable file found in this torrent.'];
        }

        $selected = $this->selectFile($files, $fileIdx, $preferredFilename, $releaseSeason, $releaseEpisode, $episodeHint);
        if (!$selected) {
            return [
                'success' => false,
                'message' => $releaseSeason !== null && $releaseEpisode !== null
                    ? 'Episode file not found in this torrent; the pack was not downloaded.'
                    : 'Could not select a file in this torrent.',
            ];
        }

        $unlocked = $this->debrid->unlockLinkFull($selected['link']);
        if (!$unlocked || empty($unlocked['link'])) {
            return ['success' => false, 'message' => 'Alldebrid could not unlock the selected file.'];
        }

        $overrideFilename = trim((string) ($payload['override_filename'] ?? ''));
        $filename = $overrideFilename !== '' ? $overrideFilename : ($unlocked['filename'] ?? $selected['name']);
        $filename = str_replace(['/', '\\', "\0"], '_', basename($filename));
        if ($filename === '') {
            $filename = 'download';
        }

        $downloadId = bin2hex(random_bytes(8));
        $this->queueManager->enqueue([
            'url' => $unlocked['link'],
            'original_url' => $selected['link'],
            'filename' => $filename,
            'path' => $path,
            'download_id' => $downloadId,
            'overwrite' => false,
            'date_added' => date('Y-m-d H:i:s'),
            'size' => $this->normalizeSize($unlocked['filesize'] ?? ($selected['size'] ?? null)),
            'media_title' => $payload['media_title'] ?? null,
            'media_addon' => $payload['addon'] ?? null,
            'automation_intent_id' => $payload['automation_intent_id'] ?? null,
            'automation_media_key' => $payload['automation_media_key'] ?? null,
            'automation' => $payload['automation'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
        ], 'video');

        return [
            'success' => true,
            'message' => 'Added to the download queue.',
            'download_id' => $downloadId,
            'filename' => $filename,
            'path' => $path,
            'size' => $this->normalizeSize($unlocked['filesize'] ?? ($selected['size'] ?? null)),
            'file_index' => $selected['torrent_index'] ?? null,
            'addon' => $payload['addon'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
        ];
    }

    private function queueDirectStream(array $payload, string $url, string $path): array
    {
        $urlCheck = $this->urlGuard->validate($url);
        if (empty($urlCheck['allowed'])) {
            return ['success' => false, 'message' => 'Direct stream URL rejected: ' . (string) ($urlCheck['reason'] ?? 'invalid URL')];
        }

        if (preg_match('/\.(m3u8|mpd)(\?|$)/i', $url)) {
            return ['success' => false, 'message' => 'HLS/DASH streams are not supported yet. Use a direct file source.'];
        }

        $season = isset($payload['season']) && is_numeric($payload['season']) ? (int) $payload['season'] : null;
        $episode = isset($payload['episode']) && is_numeric($payload['episode']) ? (int) $payload['episode'] : null;
        $releaseSeason = isset($payload['release_season']) && is_numeric($payload['release_season']) ? (int) $payload['release_season'] : $season;
        $releaseEpisode = isset($payload['release_episode']) && is_numeric($payload['release_episode']) ? (int) $payload['release_episode'] : $episode;
        $directHint = trim(implode(' ', array_filter([
            (string) ($payload['expected_filename'] ?? ''),
            (string) ($payload['filename'] ?? ''),
            (string) ($payload['title'] ?? ''),
            $url,
        ])));
        if ($releaseSeason !== null && $releaseEpisode !== null) {
            if ($this->isMultiEpisodeText($directHint)) {
                return ['success' => false, 'message' => 'Multi-episode streams cannot be queued for a single episode.'];
            }
            if ($this->hasEpisodeMarker($directHint) && !$this->episodeMatchesText($directHint, $releaseSeason, $releaseEpisode)) {
                return ['success' => false, 'message' => 'Direct stream does not match the requested episode.'];
            }
            if ($this->looksLikeSeasonPack($directHint, $releaseSeason)) {
                return ['success' => false, 'message' => 'Season packs cannot be queued for a single episode.'];
            }
        }

        $overrideFilename = trim((string) ($payload['override_filename'] ?? ''));
        if ($overrideFilename !== '') {
            $filename = $this->ensureVideoExtension($this->sanitizeFilename($overrideFilename));
        } else {
            $filename = trim((string) ($payload['filename'] ?? ''));
            if ($filename === '') {
                $filename = trim((string) ($payload['title'] ?? ''));
            }
            $filename = $this->resolveDirectFilename($url, $filename);
        }

        $downloadId = bin2hex(random_bytes(8));
        $this->queueManager->enqueue([
            'url' => $url,
            'original_url' => $url,
            'filename' => $filename,
            'path' => $path,
            'download_id' => $downloadId,
            'overwrite' => false,
            'date_added' => date('Y-m-d H:i:s'),
            'size' => $this->normalizeSize($payload['size'] ?? null),
            'media_title' => $payload['media_title'] ?? null,
            'media_addon' => $payload['addon'] ?? null,
            'automation_intent_id' => $payload['automation_intent_id'] ?? null,
            'automation_media_key' => $payload['automation_media_key'] ?? null,
            'automation' => $payload['automation'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
        ], 'video');

        return [
            'success' => true,
            'message' => 'Added to the download queue.',
            'download_id' => $downloadId,
            'filename' => $filename,
            'path' => $path,
            'size' => $this->normalizeSize($payload['size'] ?? null),
            'file_index' => null,
            'kind' => 'direct',
            'addon' => $payload['addon'] ?? null,
            'season' => $season,
            'episode' => $episode,
            'release_season' => $releaseSeason,
            'release_episode' => $releaseEpisode,
        ];
    }

    private function resolveDirectFilename(string $url, string $candidate): string
    {
        $candidate = trim($candidate);

        try {
            $response = $this->client->request('HEAD', $url, [
                'timeout' => 8,
                'connect_timeout' => 5,
                'allow_redirects' => ['max' => 5, 'strict' => true, 'track_redirects' => true],
                'http_errors' => false,
                'headers' => ['User-Agent' => 'AlldebridDL/1.0'],
            ]);

            $history = $response->getHeader('X-Guzzle-Redirect-History');
            $finalUrl = !empty($history) ? end($history) : $url;
            $finalPath = (string) (parse_url($finalUrl, PHP_URL_PATH) ?? '');
            $fromUrl = $finalPath !== '' ? basename($finalPath) : '';
            if ($fromUrl !== '' && $this->hasVideoExtension($fromUrl)) {
                return $this->sanitizeFilename($fromUrl);
            }

            $disposition = $response->getHeaderLine('Content-Disposition');
            if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
                $fromHeader = rawurldecode(trim($matches[1]));
                if ($fromHeader !== '') {
                    return $this->ensureVideoExtension($this->sanitizeFilename($fromHeader));
                }
            }

            $extension = $this->extensionFromContentType($response->getHeaderLine('Content-Type'));
            if ($extension !== '') {
                $base = $candidate !== '' ? $this->sanitizeFilename($candidate) : 'download';
                $base = preg_replace('/\.(mkv|mp4|avi|mov|m4v|webm|wmv|ts|m2ts|mpg|mpeg)$/i', '', $base);
                return $this->ensureVideoExtension($base . $extension);
            }
        } catch (\Throwable) {
        }

        if ($candidate === '') {
            $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $candidate = $urlPath !== '' ? basename($urlPath) : 'download';
        }

        return $this->ensureVideoExtension($this->sanitizeFilename($candidate));
    }

    private function hasVideoExtension(string $filename): bool
    {
        return preg_match('/\.(mkv|mp4|avi|mov|m4v|webm|wmv|ts|m2ts|mpg|mpeg)$/i', $filename) === 1;
    }

    private function ensureVideoExtension(string $filename): string
    {
        return $this->hasVideoExtension($filename) ? $filename : $filename . '.mkv';
    }

    private function extensionFromContentType(string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return match ($contentType) {
            'video/x-matroska' => '.mkv',
            'video/mp4' => '.mp4',
            'video/webm' => '.webm',
            'video/x-msvideo' => '.avi',
            'video/quicktime' => '.mov',
            'video/mp2t' => '.ts',
            'video/mpeg' => '.mpg',
            default => '',
        };
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\', "\0", "\r", "\n"], '_', basename($filename));
        $filename = trim(preg_replace('/\s+/', ' ', $filename));

        return $filename !== '' ? $filename : 'download';
    }

    private function normalizeSize(mixed $size): int
    {
        if (is_int($size) || is_float($size)) {
            return max(0, (int) $size);
        }
        if (!is_string($size) || trim($size) === '') {
            return 0;
        }
        if (is_numeric($size)) {
            return max(0, (int) $size);
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(TB|TO|GB|GO|MB|MO)\b/iu', $size, $matches)) {
            $value = (float) str_replace(',', '.', $matches[1]);
            $factor = match (strtoupper($matches[2])) {
                'TB', 'TO' => 1024 ** 4,
                'GB', 'GO' => 1024 ** 3,
                'MB', 'MO' => 1024 ** 2,
                default => 0,
            };

            return (int) round($value * $factor);
        }

        return 0;
    }

    private function buildMagnet(string $infoHash, string $displayName, array $sources): string
    {
        $magnet = 'magnet:?xt=urn:btih:' . $infoHash;
        if ($displayName !== '') {
            $magnet .= '&dn=' . rawurlencode($displayName);
        }

        foreach ($sources as $source) {
            if (!is_string($source) || !str_starts_with($source, 'tracker:')) {
                continue;
            }
            $tracker = substr($source, 8);
            if ($tracker !== '') {
                $magnet .= '&tr=' . rawurlencode($tracker);
            }
        }

        return $magnet;
    }

    private function waitForMagnet(int $magnetId, ?callable $heartbeat = null, int $maxAttempts = 15): ?array
    {
        for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
            if ($heartbeat) {
                $heartbeat();
            }
            $result = $this->debrid->saveMagnet($magnetId);
            if (!empty($result['success'])) {
                $magnets = $result['data']['magnets'] ?? null;
                $magnetData = null;

                if (is_array($magnets)) {
                    if (isset($magnets['statusCode']) || isset($magnets['files']) || isset($magnets['links'])) {
                        $magnetData = $magnets;
                    } else {
                        foreach ($magnets as $candidate) {
                            if (is_array($candidate)) {
                                $magnetData = $candidate;
                                break;
                            }
                        }
                    }
                }

                if ($magnetData) {
                    $statusCode = (int) ($magnetData['statusCode'] ?? 0);
                    $links = $magnetData['links'] ?? $magnetData['files'] ?? [];
                    if (($statusCode === 4 && !empty($links)) || $statusCode > 4) {
                        return $magnetData;
                    }
                }
            }

            sleep(1);
        }

        return null;
    }

    private function flattenFiles(array $items, string $parentPath = ''): array
    {
        $files = [];
        $index = 0;

        $walk = function (array $entries, string $parent = '') use (&$walk, &$files, &$index): void {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = $entry['n'] ?? $entry['filename'] ?? null;
                if (!$name) {
                    continue;
                }

                $path = $parent !== '' ? $parent . '/' . $name : $name;
                $link = $entry['l'] ?? $entry['link'] ?? null;

                if ($link) {
                    $torrentIndex = null;
                    foreach (['i', 'index', 'fileIdx', 'file_index'] as $indexKey) {
                        if (isset($entry[$indexKey]) && is_numeric($entry[$indexKey])) {
                            $torrentIndex = (int) $entry[$indexKey];
                            break;
                        }
                    }
                    $files[] = [
                        'index' => $index++,
                        'torrent_index' => $torrentIndex,
                        'name' => $name,
                        'path' => $path,
                        'link' => $link,
                        'size' => (int) ($entry['s'] ?? $entry['size'] ?? 0),
                    ];
                }

                if (!empty($entry['e']) && is_array($entry['e'])) {
                    $walk($entry['e'], $path);
                }
            }
        };

        $walk($items, $parentPath);

        return $files;
    }

    private function selectFile(
        array $files,
        ?int $fileIdx,
        string $preferredFilename,
        ?int $season = null,
        ?int $episode = null,
        string $episodeHint = ''
    ): ?array {
        $videoExtensions = ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'mov', 'wmv', 'ts'];
        $videos = array_values(array_filter($files, static function ($file) use ($videoExtensions) {
            return in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $videoExtensions, true);
        }));

        $findByTorrentIndex = function (array $candidates) use ($fileIdx): ?array {
            if ($fileIdx === null) {
                return null;
            }
            foreach ($candidates as $file) {
                if ($this->fileTorrentIndex($file) === $fileIdx) {
                    return $file;
                }
            }

            return null;
        };

        if ($season !== null && $episode !== null) {
            $exact = [];
            foreach ($videos as $file) {
                $text = (string) ($file['name'] ?? '') . ' ' . (string) ($file['path'] ?? '');
                if ($this->isMultiEpisodeText($text)) {
                    continue;
                }
                if ($this->episodeMatchesText($text, $season, $episode)) {
                    $exact[] = $file;
                }
            }

            if ($exact === []) {
                return null;
            }

            $indexed = $findByTorrentIndex($exact);
            if ($indexed !== null) {
                return $indexed;
            }

            usort($exact, static fn ($a, $b) => strcmp((string) ($a['path'] ?? $a['name'] ?? ''), (string) ($b['path'] ?? $b['name'] ?? '')));

            return $exact[0];
        }

        if ($fileIdx !== null) {
            $indexed = $findByTorrentIndex($videos);
            if ($indexed !== null) {
                return $indexed;
            }
            if ($findByTorrentIndex($files) !== null) {
                return null;
            }
        }

        $target = strtolower(basename($preferredFilename));
        if ($target !== '') {
            foreach ($videos as $file) {
                if (strtolower(basename((string) $file['name'])) === $target) {
                    return $file;
                }
            }
            foreach ($videos as $file) {
                if (str_contains(strtolower((string) ($file['path'] ?? '')), $target)) {
                    return $file;
                }
            }
            if ($videos === [] && count($files) === 1) {
                return $files[0];
            }
        }

        if (count($videos) === 1) {
            return $videos[0];
        }
        if ($videos === [] && count($files) === 1) {
            return $files[0];
        }

        return null;
    }

    private function fileTorrentIndex(array $file): ?int
    {
        return isset($file['torrent_index']) && is_numeric($file['torrent_index'])
            ? (int) $file['torrent_index']
            : null;
    }

    private function episodeMatchesText(string $text, int $season, int $episode): bool
    {
        $seasonPattern = '0*' . max(0, $season);
        $episodePattern = '0*' . max(1, $episode);
        if (preg_match('/S' . $seasonPattern . '[\s._-]*E' . $episodePattern . '(?!\d)/i', $text)) {
            return true;
        }
        if (preg_match('/(?<![0-9])' . $seasonPattern . 'x' . $episodePattern . '(?!\d)/i', $text)) {
            return true;
        }

        return preg_match('/\bSeason\s*' . $seasonPattern . '\s*Episode\s*' . $episodePattern . '(?!\d)/i', $text) === 1;
    }

    private function hasEpisodeMarker(string $text): bool
    {
        return preg_match('/\bS\d{1,3}[\s._-]*E\d{1,4}\b/i', $text) === 1
            || preg_match('/(?<![0-9])\d{1,3}x\d{1,4}(?!\d)/i', $text) === 1
            || preg_match('/\bSeason\s*\d{1,3}\s*Episode\s*\d{1,4}\b/i', $text) === 1;
    }

    private function isMultiEpisodeText(string $text): bool
    {
        return preg_match('/\bS\d{1,3}[\s._-]*E\d{1,4}(?:[\s._-]?E\d{1,4})+\b/i', $text) === 1
            || preg_match('/\bS\d{1,3}[\s._-]*E\d{1,4}\s*-\s*E?\d{1,4}\b/i', $text) === 1;
    }

    private function looksLikeSeasonPack(string $text, int $season): bool
    {
        $seasonPattern = '0*' . max(0, $season);

        return preg_match('/\bS' . $seasonPattern . '\b(?![\s._-]*E\d)/i', $text) === 1
            || preg_match('/\bSeason\s*' . $seasonPattern . '\b(?!\s*Episode)/i', $text) === 1
            || preg_match('/\bComplete\s+(?:Series|Show|Season)\b/i', $text) === 1
            || preg_match('/\b(?:Integrale|Intégrale|Season\s+Pack)\b/i', $text) === 1;
    }
}
