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

        $lockFile = $this->storage->getStorageDir() . '/worker.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return;
        }

        // Try to acquire an exclusive lock without blocking.
        // If it fails, another worker is already running.
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return; // Already running
        }

        // We got the lock! But we don't want to keep it in the web process.
        // We close it and let the CLI worker take its own lock.
        // However, there is a tiny race condition here. To minimize it,
        // we check the heartbeat as a secondary fallback.
        $lastHeartbeat = $this->storage->get('worker_heartbeat', 0);
        if ((time() - $lastHeartbeat) <= 10) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;

        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe')) {
            $phpPath = 'php';
        }

        if (str_starts_with(PHP_OS, 'WIN') && !str_contains(getenv('SHELL') ?: '', 'bash')) {
            $cmd = "start /B \"\" \"$phpPath\" \"$consolePath\" app:download-worker";
            @pclose(@popen($cmd, "r"));
        } else {
            $cmd = "nohup \"$phpPath\" \"$consolePath\" app:download-worker > /dev/null 2>&1 &";
            @exec($cmd);
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
        $active = $this->storage->get(self::ACTIVE_TASK_KEY, null);
        if (!$active) {
            return null;
        }

        // Resiliency: if worker is dead, the task is a ghost
        $lastHeartbeat = $this->storage->get('worker_heartbeat', 0);
        $type = $active['type'] ?? 'video';

        // Music tasks can be VERY long and silent. We give them 1 hour.
        // Standard tasks get 2 minutes.
        $threshold = ($type === 'music') ? 3600 : 120;

        if ((time() - $lastHeartbeat) > $threshold) {
            return null;
        }

        return $active;
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

        // Enforce history limit
        $config = $this->storage->get('config', []);
        $limit = (int) ($config['history_retention_limit'] ?? 100);
        if ($limit < 10)
            $limit = 10; // Safety minimum

        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        $this->storage->set('history', array_values($history));
    }

    public function getStorage(): JsonStorage
    {
        return $this->storage;
    }
}
