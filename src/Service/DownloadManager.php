<?php

namespace App\Service;

use GuzzleHttp\Client;
// Guzzle is kept for constructor compatibility if needed, but we use native streams
use GuzzleHttp\RequestOptions;

class DownloadManager
{
    private JsonStorage $storage;
    private Client $client; // Kept for structure but unused in downloadFile

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
        $this->client = new Client(); // Dummy client
    }

    public function downloadFile(string $url, string $filename, string $destinationPath, string $downloadId, bool $overwrite = false, ?callable $heartbeatCallback = null, int $expectedSize = 0): array
    {
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
            $contextOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: AlldebridDL/1.0\r\n",
                    'follow_location' => 1,
                    'max_redirects' => 20,
                    'ignore_errors' => true
                ]
            ];

            $ctx = stream_context_create($contextOptions);
            stream_context_set_params($ctx, [
                'notification' => function ($code, $severity, $msg, $msg_code, $transferred, $max) use ($downloadId, $filename, &$lastTime, &$lastDownloaded, &$lastHeartbeatTime, &$knownTotal, $heartbeatCallback) {
                    if ($code === STREAM_NOTIFY_PROGRESS) {
                        $now = microtime(true);

                        // Heartbeat for Worker
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
                                'total' => $total
                            ]);

                            $lastTime = $now;
                            $lastDownloaded = $transferred;
                        }
                    }
                }
            ]);

            $source = @fopen($url, 'r', false, $ctx);
            if (!$source) {
                $error = error_get_last();
                throw new \Exception("Could not open source URL: " . ($error['message'] ?? 'Unknown error'));
            }
            $headerTotal = $this->parseContentLength($http_response_header ?? []);
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
