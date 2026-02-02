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

    public function downloadFile(string $url, string $filename, string $destinationPath, string $downloadId, bool $overwrite = false, ?callable $heartbeatCallback = null): array
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
                'total' => 0
            ]);

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
                'notification' => function ($code, $severity, $msg, $msg_code, $transferred, $max) use ($downloadId, $filename, &$lastTime, &$lastDownloaded, &$lastHeartbeatTime, $heartbeatCallback) {
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
                            $pct = $max > 0 ? round(($transferred / $max) * 100) : 0;

                            $this->updateProgress($downloadId, [
                                'status' => 'downloading',
                                'filename' => $filename,
                                'percentage' => $pct,
                                'speed' => $speedStr,
                                'downloaded' => $transferred,
                                'total' => $max
                            ]);

                            $lastTime = $now;
                            $lastDownloaded = $transferred;
                        }
                    }
                }
            ]);

            $source = @fopen($url, 'r', false, $ctx);
            if (!$source)
                throw new \Exception("Could not open source URL.");

            $dest = @fopen($filePath, 'w');
            if (!$dest) {
                fclose($source);
                throw new \Exception("Could not open destination file.");
            }

            $bytes = stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($dest);

            // Sanity check (Alldebrid sometimes returns HTML errors as small files)
            if ($bytes < 50000) {
                $check = file_get_contents($filePath, false, null, 0, 100);
                if (str_contains($check, '<html') || str_contains($check, '<!DOCTYPE')) {
                    @unlink($filePath);
                    throw new \Exception("Download resulted in an error page (expired link?).");
                }
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
        return $this->storage->getStorageDir() . '/progress_' . $downloadId . '.json';
    }

    private function updateProgress(string $downloadId, array $data): void
    {
        $file = $this->getStatusFile($downloadId);
        $data['last_update'] = time(); // Heartbeat for mutex
        file_put_contents($file, json_encode($data));
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
