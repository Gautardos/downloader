<?php

namespace App\Controller;

use App\Service\AlldebridService;
use App\Service\DownloadManager;
use App\Service\GrokService;
use App\Service\JsonStorage;
use App\Service\QueueManager;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/video', name: 'video')]
    public function video(AlldebridService $alldebrid, DownloadManager $downloadManager, JsonStorage $storage): Response
    {
        $packs = $alldebrid->getRecentLinksGrouped();
        $recentPaths = $storage->get('recent_paths', []);

        // Mark downloaded files and calculate progress
        foreach ($packs as &$pack) {
            $downloadedCount = 0;
            foreach ($pack['files'] as &$file) {
                $file['downloaded'] = $downloadManager->isDownloaded($file['filename']);
                if ($file['downloaded']) {
                    $downloadedCount++;
                }
            }
            $pack['downloaded_count'] = $downloadedCount;
            $pack['progress'] = $pack['file_count'] > 0 ? round(($downloadedCount / $pack['file_count']) * 100) : 0;
        }

        return $this->render('dashboard/index.html.twig', [
            'packs' => $packs,
            'recent_paths' => $recentPaths,
            'config' => $storage->get('config', [])
        ]);
    }

    #[Route('/download', name: 'download', methods: ['POST'])]
    public function download(Request $request, QueueManager $queueManager, JsonStorage $storage, AlldebridService $alldebrid): Response
    {
        $url = $request->request->get('url');
        $filename = $request->request->get('filename');
        $overrideFilename = $request->request->get('override_filename');
        $path = $request->request->get('path');
        $downloadId = $request->request->get('download_id');
        $overwrite = (bool) $request->request->get('overwrite', false);

        $finalFilename = $overrideFilename ?: $filename;

        if (!$path) {
            $config = $storage->get('config');
            $path = $config['default_path'] ?? null;
        } else {
            $recentPaths = $storage->get('recent_paths', []);
            if (!in_array($path, $recentPaths)) {
                $recentPaths[] = $path;
                if (count($recentPaths) > 10)
                    array_shift($recentPaths);
                $storage->set('recent_paths', $recentPaths);
            }
        }

        if (!$url || !$finalFilename || !$path || !$downloadId) {
            return $this->json(['success' => false, 'message' => 'Missing parameters.']);
        }

        // Unlock to get permanent link for worker
        $unlockedUrl = $alldebrid->unlockLink($url);
        if (!$unlockedUrl) {
            return $this->json(['success' => false, 'message' => 'Could not unlock link. API error.']);
        }

        // Enqueue for background worker
        $queueManager->enqueue([
            'url' => $unlockedUrl,
            'original_url' => $url,
            'filename' => $finalFilename,
            'path' => $path,
            'download_id' => $downloadId,
            'overwrite' => $overwrite,
            'date_added' => date('Y-m-d H:i:s')
        ], 'video');

        return $this->json(['success' => true, 'message' => 'Added to server queue.']);
    }

    #[Route('/queue-status', name: 'queue_status', methods: ['GET'])]
    public function queueStatus(QueueManager $queueManager): Response
    {
        return $this->json([
            'queue' => $queueManager->getQueue(),
            'active' => $queueManager->getActiveTask()
        ]);
    }

    #[Route('/queue-remove/{index}', name: 'queue_remove', methods: ['POST'])]
    public function queueRemove(int $index, QueueManager $queueManager): Response
    {
        $queueManager->removeFromQueue($index);
        return $this->json(['success' => true]);
    }

    #[Route('/queue-purge', name: 'queue_purge', methods: ['POST'])]
    public function queuePurge(QueueManager $queueManager): Response
    {
        $queueManager->purgeQueue();
        return $this->json(['success' => true]);
    }

    #[Route('/', name: 'music')]
    public function music(JsonStorage $storage): Response
    {
        return $this->render('dashboard/music.html.twig', [
            'config' => $storage->get('config', [])
        ]);
    }

    #[Route('/music-files', name: 'music_files')]
    public function musicFiles(JsonStorage $storage): Response
    {
        $config = $storage->get('config', []);
        $root = $config['music_root_path'] ?? '';
        $files = [];

        if (!empty($root) && is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            $audio_exts = ['mp3', 'flac', 'm4a', 'opus', 'ogg', 'wav'];

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), $audio_exts)) {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'path' => $file->getRealPath(),
                        'size' => $file->getSize(),
                        'mtime' => $file->getMTime(),
                        'rel_path' => str_replace($root, '', $file->getRealPath())
                    ];
                }
            }

            // Sort by mtime descending
            usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        }

        return $this->render('dashboard/music_files.html.twig', [
            'files' => $files,
            'root' => $root
        ]);
    }

    #[Route('/file-tags', name: 'file_tags', methods: ['POST'])]
    public function fileTags(Request $request, KernelInterface $kernel, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        $filePath = $data['path'] ?? '';

        if (empty($filePath) || !file_exists($filePath)) {
            return $this->json(['success' => false, 'message' => 'File not found']);
        }

        $config = $storage->get('config', []);
        $venvPath = $config['music_venv_path'] ?? 'venv';
        $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'music_downloader.py';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

        // Build shell command
        $cmdStr = "($activate && python3 \"$script\" --tags \"$filePath\")";

        $process = Process::fromShellCommandline($cmdStr);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to extract tags: ' . $process->getErrorOutput()
            ]);
        }

        $tags = json_decode($process->getOutput(), true);
        return $this->json(['success' => true, 'tags' => $tags]);
    }

    #[Route('/queue', name: 'queue')]
    public function queue(QueueManager $queueManager): Response
    {
        return $this->render('dashboard/queue.html.twig', [
            'queue' => $queueManager->getQueue(),
            'active' => $queueManager->getActiveTask(),
            'hide_tracker' => true
        ]);
    }

    #[Route('/music-add', name: 'music_add', methods: ['POST'])]
    public function musicAdd(Request $request, QueueManager $queueManager, JsonStorage $storage, \App\Service\SpotifyService $spotify): Response
    {
        $urls = $request->request->get('urls');
        if (!$urls) {
            return $this->json(['success' => false, 'message' => 'No URLs provided.']);
        }

        $config = $storage->get('config', []);
        $path = $config['music_root_path'] ?? '';

        $urlList = array_filter(array_map('trim', explode("\n", $urls)));
        $logsDir = $storage->getStorageDir() . '/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }

        foreach ($urlList as $url) {
            $metadata = $spotify->getMetadata($url);
            $downloadId = bin2hex(random_bytes(8));

            // Log metadata extraction attempt
            $historyLogFile = $logsDir . '/history_' . $downloadId . '.log';
            $logContent = "--- Spotify Metadata Extraction ---\n";
            $logContent .= implode("\n", $spotify->getLogs()) . "\n";
            $logContent .= str_repeat("-", 40) . "\n\n";
            file_put_contents($historyLogFile, $logContent, FILE_APPEND);

            $filename = $metadata ? 'Spotify: ' . $metadata['name'] : 'Music: ' . substr($url, -20);
            $expectedTracks = $metadata ? $metadata['tracks'] : [];

            $queueManager->enqueue([
                'url' => $url,
                'filename' => $filename,
                'path' => $path,
                'download_id' => $downloadId,
                'date_added' => date('Y-m-d H:i:s'),
                'type' => 'music',
                'expected_tracks' => $expectedTracks
            ], 'music');
        }

        return $this->json(['success' => true]);
    }

    #[Route('/queue-active-log', name: 'queue_active_log', methods: ['GET'])]
    public function queueActiveLog(QueueManager $queueManager, JsonStorage $storage): Response
    {
        $active = $queueManager->getActiveTask();
        if (!$active || ($active['type'] ?? '') !== 'music') {
            return $this->json(['log' => '']);
        }

        $logFile = $storage->getStorageDir() . '/logs/active_worker.log';
        if (!file_exists($logFile)) {
            return $this->json(['log' => 'Initializing log...']);
        }

        // Return last 100 lines
        $lines = file($logFile);
        $lastLines = array_slice($lines, -100);
        return $this->json(['log' => implode("", $lastLines)]);
    }

    #[Route('/history-log/{downloadId}', name: 'history_log', methods: ['GET'])]
    public function historyLog(string $downloadId, JsonStorage $storage): Response
    {
        $logFile = $storage->getStorageDir() . '/logs/history_' . $downloadId . '.log';
        if (!file_exists($logFile)) {
            return new Response('Log file not found.', 404);
        }

        return new Response(file_get_contents($logFile), 200, [
            'Content-Type' => 'text/plain'
        ]);
    }

    #[Route('/notifications-poll', name: 'notifications_poll', methods: ['GET'])]
    public function notificationsPoll(JsonStorage $storage): Response
    {
        $notifications = $storage->get('server_notifications', []);

        // Clear them after reading
        if (!empty($notifications)) {
            $storage->set('server_notifications', []);
        }

        return $this->json([
            'notifications' => $notifications
        ]);
    }

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(JsonStorage $storage): Response
    {
        $logsDir = $storage->getStorageDir() . '/logs';
        $logFiles = [];
        if (is_dir($logsDir)) {
            $files = scandir($logsDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === 'active_worker.log')
                    continue;
                $logFiles[] = [
                    'name' => $file,
                    'path' => $file,
                    'date' => date('Y-m-d H:i:s', filemtime($logsDir . '/' . $file)),
                    'size' => filesize($logsDir . '/' . $file),
                    'id' => str_replace(['history_', '.log'], '', $file)
                ];
            }
            // Sort by date descending
            usort($logFiles, fn($a, $b) => $b['date'] <=> $a['date']);
        }

        return $this->render('dashboard/logs.html.twig', [
            'logs' => $logFiles
        ]);
    }

    #[Route('/delete-path', name: 'delete_path', methods: ['POST'])]
    public function deletePath(Request $request, JsonStorage $storage): Response
    {
        $pathToDelete = $request->request->get('path');
        if ($pathToDelete) {
            $recentPaths = $storage->get('recent_paths', []);
            $recentPaths = array_values(array_filter($recentPaths, fn($p) => $p !== $pathToDelete));
            $storage->set('recent_paths', $recentPaths);
        }
        return $this->json(['success' => true]);
    }

    #[Route('/rename-ai', name: 'post_rename_ai', methods: ['POST'])]
    public function renameAi(Request $request, GrokService $grok): Response
    {
        $packName = $request->request->get('packName', 'Unknown');
        $files = $request->request->all('files');

        if (count($files) === 1) {
            $suggestion = $grok->suggestFilename($packName, $files[0]);
            return $this->json([
                'success' => true,
                'suggestions' => [$files[0] => $suggestion]
            ]);
        }

        $suggestions = $grok->suggestFilenames($packName, $files);
        return $this->json([
            'success' => true,
            'suggestions' => $suggestions
        ]);
    }

    #[Route('/check-files', name: 'check_files', methods: ['POST'])]
    public function checkFiles(Request $request, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        $files = $data['files'] ?? [];
        $results = [];

        $config = $storage->get('config', []);
        $defaultPath = $config['default_path'] ?? '';

        foreach ($files as $file) {
            $path = $file['path'];
            if (empty($path)) {
                $path = $defaultPath;
            }
            $filename = $file['filename'];

            // Clean paths and ensure they are absolute
            $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            $exists = file_exists($fullPath);
            $size = $exists ? filesize($fullPath) : null;
            $folderExists = !empty($path) && is_dir($path);

            $results[] = [
                'exists' => $exists,
                'currentSize' => $size,
                'fullPath' => $fullPath,
                'folderExists' => $folderExists,
                'path' => $path
            ];
        }

        return $this->json([
            'success' => true,
            'results' => $results
        ]);
    }

    #[Route('/create-folder', name: 'create_folder', methods: ['POST'])]
    public function createFolder(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $path = $data['path'] ?? null;

        if (!$path) {
            return $this->json(['success' => false, 'message' => 'Path is required.']);
        }

        try {
            if (!is_dir($path)) {
                if (!mkdir($path, 0777, true)) {
                    throw new \Exception("Failed to create directory: $path");
                }
            }
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request, JsonStorage $storage): Response
    {
        $history = $storage->get('history', []);
        $history = array_reverse($history); // Latest first

        $perPage = 50;
        $totalItems = count($history);
        $totalPages = ceil($totalItems / $perPage);
        $currentPage = max(1, (int) $request->query->get('page', 1));

        $pagedHistory = array_slice($history, ($currentPage - 1) * $perPage, $perPage);

        return $this->render('history/index.html.twig', [
            'history' => $pagedHistory,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems
        ]);
    }

    #[Route('/progress/{downloadId}', name: 'progress', methods: ['GET'])]
    public function progress(string $downloadId, JsonStorage $storage): Response
    {
        $progressFile = $storage->getStorageDir() . '/progress_' . $downloadId . '.json';

        if (!file_exists($progressFile)) {
            return $this->json(['status' => 'idle', 'percentage' => 0]);
        }

        $content = file_get_contents($progressFile);
        $data = json_decode($content, true);

        return $this->json($data);
    }
    #[Route('/debug-storage', name: 'debug_storage')]
    public function debugStorage(JsonStorage $storage): Response
    {
        $dir = $storage->getStorageDir();
        $exists = is_dir($dir);
        $writable = is_writable($dir);
        $files = $exists ? scandir($dir) : [];

        return $this->json([
            'path' => $dir,
            'exists' => $exists,
            'writable' => $writable,
            'files' => $files,
            'user' => get_current_user(),
            'uid' => getmyuid(),
            'gid' => getmygid(),
        ]);
    }

    #[Route('/debug-api', name: 'debug_api')]
    public function debugApi(AlldebridService $alldebrid): Response
    {
        $links = $alldebrid->getRecentLinks();
        $saved = $alldebrid->getSavedLinks();

        return $this->json([
            'recent_count' => count($links),
            'recent_sample' => array_slice($links, 0, 1),
            'recent_fields' => !empty($links) ? array_keys($links[0]) : [],
            'saved_count' => count($saved),
            'saved_sample' => array_slice($saved, 0, 1),
            'saved_fields' => !empty($saved) ? array_keys($saved[0]) : []
        ]);
    }

    #[Route('/debug-grouping', name: 'debug_grouping')]
    public function debugGrouping(AlldebridService $alldebrid): Response
    {
        try {
            $history = $alldebrid->getRecentLinks();
            $grouped = $alldebrid->getRecentLinksGrouped();

            $summary = [];
            foreach ($grouped as $pack) {
                $summary[$pack['name']] = count($pack['files']);
            }

            $result = [
                'history_count' => count($history),
                'total_groups' => count($grouped),
                'groups_summary' => $summary,
                'groups_sample' => array_slice($grouped, 0, 5)
            ];

            return new Response(json_encode($result, JSON_PRETTY_PRINT), 200, [
                'Content-Type' => 'application/json'
            ]);
        } catch (\Exception $e) {
            return new Response(json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]), 500, ['Content-Type' => 'application/json']);
        }
    }

    #[Route('/debug-all-endpoints', name: 'debug_all_endpoints')]
    public function debugAllEndpoints(AlldebridService $alldebrid): Response
    {
        try {
            $allData = $alldebrid->testAllEndpoints();

            return new Response(json_encode($allData, JSON_PRETTY_PRINT), 200, [
                'Content-Type' => 'application/json'
            ]);
        } catch (\Exception $e) {
            return new Response(json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]), 500, ['Content-Type' => 'application/json']);
        }
    }
}
