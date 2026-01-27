<?php

namespace App\Service;

class QueueManager
{
    private JsonStorage $storage;
    private string $projectDir;
    private const QUEUE_KEY = 'server_queue';
    private const ACTIVE_TASK_KEY = 'active_worker_task';

    public function __construct(JsonStorage $storage, string $projectDir)
    {
        $this->storage = $storage;
        $this->projectDir = $projectDir;
    }

    public function enqueue(array $item): void
    {
        $queue = $this->storage->get(self::QUEUE_KEY, []);
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
            array_splice($queue, $index, 1);
            $this->storage->set(self::QUEUE_KEY, $queue);
        }
    }

    public function getStorage(): JsonStorage
    {
        return $this->storage;
    }
}
