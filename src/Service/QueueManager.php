<?php

namespace App\Service;

class QueueManager
{
    private JsonStorage $storage;
    private string $projectDir;
    private MediaTypeHelper $mediaTypeHelper;
    private const QUEUE_KEY = 'server_queue';
    private const ACTIVE_TASK_KEY = 'active_worker_task';

    public function __construct(JsonStorage $storage, string $projectDir, MediaTypeHelper $mediaTypeHelper)
    {
        $this->storage = $storage;
        $this->projectDir = $projectDir;
        $this->mediaTypeHelper = $mediaTypeHelper;
    }

    public function enqueue(array $item, string $type = 'video'): void
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);

        // If type is video (default), detect granular type
        if ($type === 'video') {
            $type = $this->mediaTypeHelper->getType($item['filename'] ?? '');
        }

        $item['type'] = $type;
        $queue[] = $item;
        $this->storage->set(self::QUEUE_KEY, $queue);

        $this->triggerWorker();
    }

    public function triggerWorker(): void
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);
        if (empty($queue)) {
            return;
        }

        $lastHeartbeat = $this->storage->get('worker_heartbeat', 0);

        if ((time() - $lastHeartbeat) > 25) {
            $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
            $phpPath = PHP_BINARY;

            // In web context, PHP_BINARY might be 'php-fpm' or 'php-cgi'. 
            // We need the CLI version 'php'.
            if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe')) {
                $phpPath = 'php';
            }

            if (str_starts_with(PHP_OS, 'WIN') && !str_contains(getenv('SHELL') ?: '', 'bash')) {
                // Windows Native: start /B "" "$php" "$console" command
                $cmd = "start /B \"\" \"$phpPath\" \"$consolePath\" app:download-worker";
                @pclose(@popen($cmd, "r"));
            } else {
                // Linux / WSL / Bash: Detach process properly
                // nohup allows it to survive the web request termination
                $cmd = "nohup \"$phpPath\" \"$consolePath\" app:download-worker > /dev/null 2>&1 &";
                @exec($cmd);
            }
        }
    }

    public function getQueue(): array
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);
        if (count($queue) > 0) {
            $this->triggerWorker();
        }
        return $queue;
    }

    public function pop(): ?array
    {
        $queue = $this->getQueue();
        if (empty($queue))
            return null;

        $item = array_shift($queue);
        $this->storage->set(self::QUEUE_KEY, $queue);
        return $item;
    }

    public function setActiveTask(?array $task): void
    {
        $this->storage->set(self::ACTIVE_TASK_KEY, $task);
    }

    public function getActiveTask(): ?array
    {
        // Resiliency: if worker is dead, the task is a ghost
        $lastHeartbeat = $this->storage->get('worker_heartbeat', 0);
        if ((time() - $lastHeartbeat) > 60) {
            return null;
        }

        return $this->storage->get(self::ACTIVE_TASK_KEY);
    }

    public function purgeQueue(): void
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);
        foreach ($queue as $item) {
            $this->recordHistory($item, 'canceled', 0);
        }

        $this->storage->set(self::QUEUE_KEY, []);
        $this->storage->set(self::ACTIVE_TASK_KEY, null);
        $this->storage->set('worker_heartbeat', 0);

        // Clean all progress files
        $dir = $this->storage->getStorageDir();
        $files = glob($dir . '/progress_*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    public function removeFromQueue(int $index): void
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);
        if (isset($queue[$index])) {
            $item = $queue[$index];
            $this->recordHistory($item, 'canceled', 0);
            array_splice($queue, $index, 1);
            $this->storage->set(self::QUEUE_KEY, $queue);
        }
    }

    public function recordHistory(array $item, string $status, int $fileCount, array $stats = []): void
    {
        $history = $this->storage->get('history', []);

        $type = $item['type'] ?? 'video';
        if ($type === 'video') {
            $type = $this->mediaTypeHelper->getType($item['filename'] ?? '');
        }

        $history[] = [
            'download_id' => $item['download_id'] ?? bin2hex(random_bytes(8)),
            'filename' => $item['filename'] ?? 'Download',
            'type' => $type,
            'status' => $status,
            'date' => date('Y-m-d H:i:s'),
            'path' => $item['path'] ?? '',
            'file_count' => $fileCount,
            'size' => $stats['size'] ?? ($item['size'] ?? null),
            'speed' => $stats['speed'] ?? ($item['speed'] ?? null),
            'duration' => $stats['duration'] ?? ($item['duration'] ?? null),
            'expected_tracks' => $item['expected_tracks'] ?? [],
            'downloaded_tracks' => $stats['downloaded_tracks'] ?? [],
            'missing_tracks' => $stats['missing_tracks'] ?? [],
            'error_message' => $stats['message'] ?? null
        ];
        $this->storage->set('history', array_values($history));
    }

    public function getStorage(): JsonStorage
    {
        return $this->storage;
    }
}
