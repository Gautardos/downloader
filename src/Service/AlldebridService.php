<?php

namespace App\Service;

use GuzzleHttp\Client;

class AlldebridService
{
    private const BASE_URI = 'https://api.alldebrid.com/v4/';
    private Client $client;
    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->client = new Client(['base_uri' => self::BASE_URI]);
        $this->storage = $storage;
    }

    private function getApiKey(): ?string
    {
        $config = $this->storage->get('config');
        return $config['api_key'] ?? null;
    }

    public function validateApiKey(): bool|string
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return 'API Key missing in settings.';
        }

        try {
            $response = $this->client->get('user', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                return true;
            }

            return $data['error']['message'] ?? 'Invalid API Key or Alldebrid error.';
        } catch (\Exception $e) {
            return 'Connection error: ' . $e->getMessage();
        }
    }

    public function getRecentLinks(): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return [];
        }

        try {
            $response = $this->client->get('user/history', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey
                ]
            ]);

            $content = $response->getBody()->getContents();
            $data = json_decode($content, true);

            if (($data['status'] ?? '') !== 'success') {
                return [];
            }

            return $data['data']['links'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getMagnets(): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return [];
        }

        try {
            // Use absolute path with leading slash to bypass /v4/ base URI
            $response = $this->client->get('/v4.1/magnet/status', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey
                ]
            ]);

            $content = $response->getBody()->getContents();
            $data = json_decode($content, true);

            if (($data['status'] ?? '') !== 'success') {
                return [];
            }

            return $data['data']['magnets'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getSavedLinks(): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return [];
        }

        try {
            $response = $this->client->get('user/links', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey
                ]
            ]);

            $content = $response->getBody()->getContents();
            $data = json_decode($content, true);

            if (($data['status'] ?? '') !== 'success') {
                return [];
            }

            return $data['data']['links'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getMagnetFiles(array $magnetIds): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey || empty($magnetIds)) {
            return [];
        }

        try {
            // Build query with id[] array
            $query = ['agent' => 'downloader-app', 'apikey' => $apiKey];
            foreach ($magnetIds as $id) {
                $query['id'][] = $id;
            }

            $response = $this->client->post('magnet/files', [
                'form_params' => $query
            ]);

            $content = $response->getBody()->getContents();
            $data = json_decode($content, true);

            if (($data['status'] ?? '') !== 'success') {
                return [];
            }

            return $data['data']['magnets'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function uploadMagnet(array $magnets): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey || empty($magnets)) {
            return ['success' => false, 'message' => 'No API key or magnets provided'];
        }

        try {
            $query = ['agent' => 'downloader-app', 'apikey' => $apiKey];
            foreach ($magnets as $magnet) {
                $query['magnets'][] = $magnet;
            }

            $response = $this->client->post('magnet/upload', [
                'form_params' => $query
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                return ['success' => true, 'data' => $data['data']];
            }
            return ['success' => false, 'message' => $data['error']['message'] ?? 'Unknown error'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function uploadTorrent(string $filePath, ?string $filename = null): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey || !file_exists($filePath)) {
            return ['success' => false, 'message' => 'Invalid file or API key'];
        }

        try {
            $response = $this->client->post('magnet/upload/file', [
                'query' => ['agent' => 'downloader-app', 'apikey' => $apiKey],
                'multipart' => [
                    [
                        'name' => 'files[]',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => $filename ?? basename($filePath)
                    ]
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                // The 'files' array in response contains upload results
                $files = $data['data']['files'] ?? [];
                if (!empty($files) && isset($files[0]['error'])) {
                    return ['success' => false, 'message' => $files[0]['error']['message']];
                }
                return ['success' => true, 'data' => $data['data']];
            }
            return ['success' => false, 'message' => $data['error']['message'] ?? 'Unknown error'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function saveMagnet(int $magnetId): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return ['success' => false, 'message' => 'No API key'];
        }

        try {
            // Use v4.1 magnet/status endpoint to get links
            $response = $this->client->get('/v4.1/magnet/status', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey,
                    'id' => $magnetId
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                return ['success' => true, 'data' => $data['data']];
            }
            return ['success' => false, 'message' => $data['error']['message'] ?? 'Unknown error'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function testAllEndpoints(): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return ['error' => 'No API key'];
        }

        $results = [];

        // Test 1: /v4/user
        try {
            $response = $this->client->get('user', [
                'query' => ['agent' => 'downloader-app', 'apikey' => $apiKey]
            ]);
            $results['v4_user'] = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $results['v4_user_error'] = $e->getMessage();
        }

        // Test 2: /v4/user/links
        try {
            $response = $this->client->get('user/links', [
                'query' => ['agent' => 'downloader-app', 'apikey' => $apiKey]
            ]);
            $results['v4_user_links'] = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $results['v4_user_links_error'] = $e->getMessage();
        }

        // Test 3: /v4/user/history
        try {
            $response = $this->client->get('user/history', [
                'query' => ['agent' => 'downloader-app', 'apikey' => $apiKey]
            ]);
            $results['v4_user_history'] = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $results['v4_user_history_error'] = $e->getMessage();
        }

        // Test 4: /v4.1/magnet/status (note: base_uri is already /v4/, so we use ../v4.1/)
        try {
            $client = new Client(['base_uri' => 'https://api.alldebrid.com/']);
            $response = $client->get('v4.1/magnet/status', [
                'query' => ['agent' => 'downloader-app', 'apikey' => $apiKey]
            ]);
            $results['v4_1_magnet_status'] = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $results['v4_1_magnet_status_error'] = $e->getMessage();
        }

        return $results;
    }

    public function unlockLink(string $link): ?string
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return null;
        }

        try {
            $response = $this->client->get('link/unlock', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey,
                    'link' => $link
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                return $data['data']['link'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Unlock a link and return full metadata (link, filename, filesize).
     */
    public function unlockLinkFull(string $link): ?array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return null;
        }

        try {
            $response = $this->client->get('link/unlock', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey,
                    'link' => $link
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success' && isset($data['data']['link'])) {
                return [
                    'link' => $data['data']['link'],
                    'filename' => $data['data']['filename'] ?? basename(parse_url($link, PHP_URL_PATH) ?: 'unknown'),
                    'filesize' => $data['data']['filesize'] ?? 0,
                    'host' => $data['data']['host'] ?? '',
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getStreamingLink(string $link): ?string
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            return null;
        }

        try {
            $response = $this->client->get('link/streaming', [
                'query' => [
                    'agent' => 'downloader-app',
                    'apikey' => $apiKey,
                    'link' => $link
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (($data['status'] ?? '') === 'success') {
                return $data['data']['link'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getRecentLinksGrouped(): array
    {
        $links = $this->getRecentLinks();
        $magnets = $this->getMagnets(); // v4.1/magnet/status

        // Collect all magnet IDs that are ready
        $magnetIds = [];
        $magnetNames = [];
        foreach ($magnets as $magnet) {
            if (($magnet['statusCode'] ?? 0) === 4 && ($magnet['id'] ?? null)) {
                $mId = (string) $magnet['id'];
                $magnetIds[] = $mId;
                $magnetNames[$mId] = $magnet['filename'] ?? 'Unknown Pack';
            }
        }

        // Fetch file paths in chunks to avoid large request issues
        $magnetFilesMapping = []; // Keys: "filename|size" and "filename"
        $chunks = array_chunk($magnetIds, 50);
        foreach ($chunks as $chunk) {
            $magnetsWithFiles = $this->getMagnetFiles($chunk);
            foreach ($magnetsWithFiles as $magnetData) {
                $mId = (string) ($magnetData['id'] ?? '');
                if ($mId && isset($magnetData['files'])) {
                    $packName = $magnetNames[$mId] ?? 'Unknown Pack';
                    $this->flattenMagnetFiles($magnetData['files'], $packName, $magnetFilesMapping);
                }
            }
        }

        // Group links by pack
        $packs = [];
        foreach ($links as $link) {
            $filename = $link['filename'] ?? '';
            $size = $link['size'] ?? 0;
            $packName = 'Individual Files';

            // 1. Try match by filename AND size
            $mappingKey = $filename . '|' . $size;
            $subpath = '';
            if (isset($magnetFilesMapping[$mappingKey])) {
                $mapping = $magnetFilesMapping[$mappingKey];
                $packName = $mapping['pack'];
                $subpath = $mapping['subpath'];
            }
            // 2. Try match by filename only
            elseif (isset($magnetFilesMapping[$filename])) {
                $mapping = $magnetFilesMapping[$filename];
                $packName = $mapping['pack'];
                $subpath = $mapping['subpath'];
            }
            // 3. Fallback to extraction
            else {
                $extracted = $this->extractPackFromFilename($filename);
                if ($extracted) {
                    $packName = $extracted;
                }
            }

            if (!isset($packs[$packName])) {
                $packs[$packName] = [
                    'name' => $packName,
                    'files' => [],
                    'total_size' => 0,
                    'file_count' => 0
                ];
            }

            // Attach subpath to link metadata
            $link['subpath'] = $subpath;

            $packs[$packName]['files'][] = $link;
            $packs[$packName]['total_size'] += $size;
            $packs[$packName]['file_count']++;
        }

        // Sort files alphabetically within each pack
        foreach ($packs as &$pack) {
            usort($pack['files'], function ($a, $b) {
                return strnatcasecmp($a['filename'] ?? '', $b['filename'] ?? '');
            });
        }

        return array_values($packs);
    }

    private function flattenMagnetFiles(array $files, string $packName, array &$result, string $parentPath = '', bool $isFirstLevel = true): void
    {
        // 1. Root Stripping Logic
        $strictStripRoot = $isFirstLevel && count($files) === 1 && isset($files[0]['e']);

        foreach ($files as $file) {
            $name = $file['n'] ?? '';
            $size = $file['s'] ?? 0;

            if (isset($file['e'])) {
                // Heuristic: skip if it's the root folder (matches packName at first level or strict strip)
                $isPackRootFolder = $isFirstLevel && ($name === $packName || $strictStripRoot);

                $folderPath = $parentPath;
                if (!$isPackRootFolder) {
                    $folderPath = $parentPath ? $parentPath . '/' . $name : $name;
                }
                $this->flattenMagnetFiles($file['e'], $packName, $result, $folderPath, false);
            } else {
                // 2. Redundant Subpath Stripping (e.g. Movie/Movie.mkv -> subpath should be empty)
                $finalSubpath = $parentPath;
                if ($parentPath !== '') {
                    $filenameWithoutExt = pathinfo($name, PATHINFO_FILENAME);
                    $pathParts = explode('/', $parentPath);
                    $lastFolder = end($pathParts);

                    if ($lastFolder === $filenameWithoutExt) {
                        array_pop($pathParts);
                        $finalSubpath = implode('/', $pathParts);
                    }
                }

                $metadata = [
                    'pack' => $packName,
                    'subpath' => $finalSubpath
                ];

                $result[$name . '|' . $size] = $metadata;
                $result[$name] = $metadata;
            }
        }
    }

    private function extractPackFromFilename(string $filename): ?string
    {
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Try to extract series/pack name before episode number
        // Patterns: "Show.S01E02", "Show.2024.720p", etc.
        if (preg_match('/^(.+?)[\.\s]+(S\d+E\d+|20\d{2}|720p|1080p|MULTI)/i', $name, $matches)) {
            return $matches[1];
        }

        // Fallback: return null to use "Ungrouped"
        return null;
    }
}
