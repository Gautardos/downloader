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
            if (isset($magnetFilesMapping[$mappingKey])) {
                $packName = $magnetFilesMapping[$mappingKey];
            }
            // 2. Try match by filename only
            elseif (isset($magnetFilesMapping[$filename])) {
                $packName = $magnetFilesMapping[$filename];
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

    private function flattenMagnetFiles(array $files, string $packName, array &$result, string $parentPath = ''): void
    {
        foreach ($files as $file) {
            $name = $file['n'] ?? '';
            $size = $file['s'] ?? 0;

            if (isset($file['e'])) {
                $folderPath = $parentPath ? $parentPath . '/' . $name : $name;
                $this->flattenMagnetFiles($file['e'], $packName, $result, $folderPath);
            } else {
                $finalPackName = $parentPath ?: $packName;

                // Store with size matching key
                $result[$name . '|' . $size] = $finalPackName;

                // Store with name only as fallback (might be overwritten by other packs with same filename, but better than nothing)
                $result[$name] = $finalPackName;
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
