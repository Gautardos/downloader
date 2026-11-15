<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class DownloadManager
{
    private JsonStorage $storage;
    private Client $client;
    private SafeUrlGuard $urlGuard;

    public function __construct(JsonStorage $storage, ?SafeUrlGuard $urlGuard = null)
    {
        $this->storage = $storage;
        $this->client = new Client();
        $this->urlGuard = $urlGuard ?? new SafeUrlGuard();
    }

    public function downloadFile(string $url, string $filename, string $destinationPath, string $downloadId, bool $overwrite = false, ?callable $heartbeatCallback = null, int $expectedSize = 0): array
    {
        $urlCheck = $this->urlGuard->validate($url);
        if (empty($urlCheck['allowed'])) {
            return ['success' => false, 'message' => 'URL rejected: ' . (string) ($urlCheck['reason'] ?? 'invalid URL')];
        }

        // 1. Background survival
        ignore_user_abort(true);
        set_time_limit(0);

        if (!is_dir($destinationPath)) {
            if (!mkdir($destinationPath, 0777, true)) {
                return ['success' => false, 'message' => 'Failed to create destination directory: ' . $destinationPath];
            }
        }

        $filePath = rtrim($destinationPath, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $progressFile = $this->getStatusFile($downloadId);

        // 2. Strict Mutex check
        if (file_exists($progressFile)) {
            $existing = json_decode(file_get_contents($progressFile), true);
            if (isset($existing['status']) && $existing['status'] === 'downloading') {
                $lastHeartbeat = $existing['last_update'] ?? 0;
                if ((time() - $lastHeartbeat) < 15) {
                    // Already being handled by another active thread
                    return ['success' => true, 'message' => 'Already downloading.'];
                }
            }
        }

        if (file_exists($filePath)) {
            if ($overwrite) {
                @unlink($filePath);
            } else {
                return ['success' => false, 'message' => 'File already exists.'];
            }
        }

        try {
            $startTime = microtime(true);
            $lastTime = $startTime;
            $lastHeartbeatTime = $startTime;
            $lastDownloaded = 0;

            // Initial status
            $this->updateProgress($downloadId, [
                'status' => 'downloading',
                'filename' => $filename,
                'percentage' => 0,
                'speed' => '0 KB/s',
                'downloaded' => 0,
                'total' => max(0, $expectedSize)
            ]);

            $knownTotal = max(0, $expectedSize);
            $currentUrl = $url;
            $redirects = 0;
            $visited = [$url => true];
            $source = null;
            $headers = [];

            while (true) {
                $contextOptions = [
                    'http' => [
                        'method' => 'GET',
                        'header' => "User-Agent: AlldebridDL/1.0\r\n",
                        'follow_location' => 0,
                        'max_redirects' => 0,
                        'ignore_errors' => true,
                        'timeout' => 60,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ];

                $ctx = stream_context_create($contextOptions);
                stream_context_set_params($ctx, [
                    'notification' => function ($code, $severity, $msg, $msg_code, $transferred, $max) use ($downloadId, $filename, &$lastTime, &$lastDownloaded, &$lastHeartbeatTime, &$knownTotal, $heartbeatCallback) {
                        if ($code === STREAM_NOTIFY_PROGRESS) {
                            $now = microtime(true);

                            if ($heartbeatCallback && ($now - $lastHeartbeatTime >= 5.0)) {
                                $heartbeatCallback();
                                $lastHeartbeatTime = $now;
                            }

                            if ($transferred > 0 && ($now - $lastTime >= 0.5 || $transferred === $max)) {
                                $speed = ($now > $lastTime) ? ($transferred - $lastDownloaded) / ($now - $lastTime) : 0;
                                $speedStr = $this->formatSpeed($speed);
                                $total = $max > 0 ? $max : $knownTotal;
                                $pct = $total > 0 ? round(($transferred / $total) * 100) : 0;

                                $this->updateProgress($downloadId, [
                                    'status' => 'downloading',
                                    'filename' => $filename,
                                    'percentage' => $pct,
                                    'speed' => $speedStr,
                                    'downloaded' => $transferred,
                                    'total' => $total,
                                ]);

                                $lastTime = $now;
                                $lastDownloaded = $transferred;
                            }
                        }
                    },
                ]);

                $source = @fopen($currentUrl, 'r', false, $ctx);
                if (!$source) {
                    throw new \Exception('Could not open source URL (' . $this->urlHost($currentUrl) . ').');
                }

                $headers = $http_response_header ?? [];
                $status = $this->parseStatusCode($headers);

                if ($status >= 300 && $status < 400) {
                    $location = $this->parseLocation($headers);
                    fclose($source);
                    $source = null;
                    if ($location === '') {
                        throw new \Exception('Redirect response without a Location header.');
                    }

                    $nextUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
                    $redirects++;
                    $nextCheck = $this->urlGuard->validateRedirect($nextUrl, $redirects, array_keys($visited));
                    if (empty($nextCheck['allowed'])) {
                        throw new \Exception('Redirect rejected: ' . (string) ($nextCheck['reason'] ?? 'invalid URL'));
                    }
                    $visited[$nextUrl] = true;
                    $currentUrl = $nextUrl;
                    continue;
                }

                if ($status === 0 || $status >= 400) {
                    fclose($source);
                    $source = null;
                    throw new \Exception('Source returned HTTP ' . ($status > 0 ? $status : 'unknown') . '.');
                }

                break;
            }

            $headerTotal = $this->parseContentLength($headers);
            if ($headerTotal > 0) {
                $knownTotal = $headerTotal;
            }

            $dest = @fopen($filePath, 'w');
            if (!$dest) {
                $error = error_get_last();
                fclose($source);
                throw new \Exception("Could not open destination file: " . ($error['message'] ?? 'Unknown error'));
            }

            $bytes = stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($lastErrorDest = $dest);

            if ($bytes === false) {
                @unlink($filePath);
                throw new \Exception('Could not read the downloaded stream.');
            }

            $check = (string) @file_get_contents($filePath, false, null, 0, 512);
            if (stripos($check, '<html') !== false || stripos($check, '<!DOCTYPE') !== false) {
                @unlink($filePath);
                throw new \Exception('Download resulted in an HTML error page (expired link?).');
            }

            if ($bytes < 1024 * 1024) {
                @unlink($filePath);
                throw new \Exception('Downloaded file is too small to be a video (' . $bytes . ' bytes).');
            }

            if ($headerTotal > 0 && $bytes < $headerTotal) {
                @unlink($filePath);
                throw new \Exception('Incomplete download: ' . $bytes . ' bytes received for ' . $headerTotal . ' expected.');
            }

            if ($expectedSize > 0 && $bytes < (int) round($expectedSize * 0.9)) {
                @unlink($filePath);
                throw new \Exception('Incomplete download: ' . $bytes . ' bytes received for an expected size of ' . $expectedSize . '.');
            }

            $duration = microtime(true) - $startTime;
            $speed = $duration > 0 ? $bytes / $duration : 0;
            $speedStr = $this->formatSpeed($speed);

            $this->updateProgress($downloadId, [
                'status' => 'complete',
                'percentage' => 100,
                'speed' => '0 MB/s',
                'downloaded' => $bytes,
                'total' => $bytes
            ]);

            return [
                'success' => true,
                'message' => 'Complete.',
                'size' => $bytes,
                'duration' => round($duration, 2),
                'speed' => $speedStr
            ];

        } catch (\Exception $e) {
            @unlink($filePath);
            $this->updateProgress($downloadId, [
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', trim((string) $header), $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function parseLocation(array $headers): string
    {
        foreach ($headers as $header) {
            if (preg_match('/^location:\s*(.+)$/i', trim((string) $header), $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function urlHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unknown host';
    }

    private function getStatusFile(string $downloadId): string
    {
        $file = $this->storage->getStorageDir() . '/progress_' . $downloadId . '.json';
        if (is_file($file)) {
            @chmod($file, 0600);
        }

        return $file;
    }

    private function updateProgress(string $downloadId, array $data): void
    {
        $file = $this->getStatusFile($downloadId);
        $data['last_update'] = time(); // Heartbeat for mutex
        file_put_contents($file, json_encode($data));
        @chmod($file, 0600);
    }

    private function parseContentLength(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^content-length:\s*(\d+)/i', trim((string) $header), $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function formatSpeed(float $bytesPerSec): string
    {
        if ($bytesPerSec > 1024 * 1024)
            return round($bytesPerSec / 1024 / 1024, 2) . ' MB/s';
        if ($bytesPerSec > 1024)
            return round($bytesPerSec / 1024, 2) . ' KB/s';
        return round($bytesPerSec, 2) . ' B/s';
    }


    public function isDownloaded(string $filename): bool
    {
        $history = $this->storage->get('history', []);
        foreach ($history as $entry) {
            if (($entry['filename'] ?? '') === $filename) {
                return true;
            }
        }
        return false;
    }
}
