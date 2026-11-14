<?php

namespace App\Service;

use GuzzleHttp\Client;

class MediaDownloadService
{
    private AlldebridService $debrid;
    private QueueManager $queueManager;
    private JsonStorage $storage;
    private Client $client;

    public function __construct(AlldebridService $debrid, QueueManager $queueManager, JsonStorage $storage)
    {
        $this->debrid = $debrid;
        $this->queueManager = $queueManager;
        $this->storage = $storage;
        $this->client = new Client();
    }

    public function addStream(array $payload): array
    {
        $infoHash = strtolower(trim((string) ($payload['info_hash'] ?? '')));
        $streamUrl = trim((string) ($payload['url'] ?? ''));

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

        $magnetData = $this->waitForMagnet((int) $magnetId);
        if (!$magnetData) {
            return ['success' => false, 'message' => 'Alldebrid did not return torrent files in time.'];
        }

        $statusCode = (int) ($magnetData['statusCode'] ?? 0);
        if ($statusCode > 4) {
            return ['success' => false, 'message' => 'Alldebrid could not prepare this torrent: ' . ($magnetData['status'] ?? 'unknown status')];
        }

        $files = $this->flattenFiles($magnetData['links'] ?? $magnetData['files'] ?? []);
        if (empty($files)) {
            return ['success' => false, 'message' => 'No downloadable file found in this torrent.'];
        }

        $selected = $this->selectFile($files, $fileIdx, $preferredFilename);
        if (!$selected) {
            return ['success' => false, 'message' => 'Could not select a file in this torrent.'];
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
            'media_title' => $payload['media_title'] ?? null,
            'media_addon' => $payload['addon'] ?? null,
        ], 'video');

        return [
            'success' => true,
            'message' => 'Added to the download queue.',
            'download_id' => $downloadId,
            'filename' => $filename,
            'path' => $path,
            'size' => $unlocked['filesize'] ?? ($selected['size'] ?? null),
            'file_index' => $selected['index'],
            'addon' => $payload['addon'] ?? null,
        ];
    }

    private function queueDirectStream(array $payload, string $url, string $path): array
    {
        if (preg_match('/\.(m3u8|mpd)(\?|$)/i', $url)) {
            return ['success' => false, 'message' => 'HLS/DASH streams are not supported yet. Use a direct file source.'];
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
            'media_title' => $payload['media_title'] ?? null,
            'media_addon' => $payload['addon'] ?? null,
        ], 'video');

        return [
            'success' => true,
            'message' => 'Added to the download queue.',
            'download_id' => $downloadId,
            'filename' => $filename,
            'path' => $path,
            'size' => $payload['size'] ?? null,
            'file_index' => null,
            'kind' => 'direct',
            'addon' => $payload['addon'] ?? null,
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

    private function waitForMagnet(int $magnetId): ?array
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
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
                    $files[] = [
                        'index' => $index++,
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

    private function selectFile(array $files, ?int $fileIdx, string $preferredFilename): ?array
    {
        if ($fileIdx !== null && isset($files[$fileIdx])) {
            return $files[$fileIdx];
        }

        if ($preferredFilename !== '') {
            $target = strtolower(basename($preferredFilename));
            foreach ($files as $file) {
                if (strtolower(basename($file['name'])) === $target) {
                    return $file;
                }
            }
            foreach ($files as $file) {
                if ($target !== '' && str_contains(strtolower($file['path']), $target)) {
                    return $file;
                }
            }
        }

        $videoExtensions = ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'mov', 'wmv', 'ts'];
        $videos = array_values(array_filter($files, static function ($file) use ($videoExtensions) {
            return in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $videoExtensions, true);
        }));

        $candidates = !empty($videos) ? $videos : $files;
        usort($candidates, static fn ($a, $b) => ($b['size'] ?? 0) <=> ($a['size'] ?? 0));

        return $candidates[0] ?? null;
    }
}
