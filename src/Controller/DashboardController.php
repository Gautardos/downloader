<?php

namespace App\Controller;

use App\Service\AiService;
use App\Service\CsrfTokenService;
use App\Service\Automation\QueueSizeResolver;
use App\Service\AlldebridService;
use App\Service\DebridHistoryService;
use App\Service\DownloadManager;
use App\Service\GrokService;
use App\Service\JsonStorage;
use App\Service\LogRedactor;
use App\Service\QueueManager;
use App\Service\ServerNotificationService;
use App\Service\SpotifyService;
use App\Service\SpotifyUserService;
use App\Service\TorrentDbService;
use App\Service\MediaTypeHelper;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/torrent', name: 'torrent')]
    public function torrent(Request $request, AlldebridService $alldebrid, DownloadManager $downloadManager, JsonStorage $storage, MediaTypeHelper $mediaTypeHelper, AiService $ai): Response
    {
        if (!$storage->hasAlldebrid() || !$storage->hasDefaultTorrentPath()) {
            return $this->render('dashboard/locked.html.twig', [
                'title' => 'Configuration Required',
                'message' => 'The torrent management screen requires both an Alldebrid API key and a default download path.',
                'requirements' => [
                    'Alldebrid API Key (Settings > Global Settings)',
                    'Default Torrent Download Path (Settings > Global Settings)'
                ]
            ]);
        }

        $validation = $alldebrid->validateApiKey();
        if ($validation !== true) {
            return $this->render('dashboard/locked.html.twig', [
                'title' => 'Alldebrid API Error',
                'message' => 'Could not connect to Alldebrid. ' . $validation,
                'requirements' => [
                    'Please check your Alldebrid API Key in Settings'
                ]
            ]);
        }

        $recentPaths = $storage->get('recent_paths', []);
        $config = $storage->get('config', []);

        if ($request->query->get('view') !== 'packs') {
            return $this->render('dashboard/debrid.html.twig', [
                'recent_paths' => $recentPaths,
                'config' => $config,
                'is_torrent_page' => true,
            ]);
        }

        $packs = $alldebrid->getRecentLinksGrouped();

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
            'config' => $config,
            'is_torrent_page' => true,
            'is_ai_configured' => $ai->hasAi('renaming')
        ]);
    }

    #[Route('/debrid/recent', name: 'debrid_recent', methods: ['GET'])]
    public function debridRecent(Request $request, DebridHistoryService $history): Response
    {
        $offset = max(0, (int) $request->query->get('offset', 0));
        $limit = (int) $request->query->get('limit', 5);
        $refresh = $request->query->getBoolean('refresh');

        return $this->json([
            'success' => true,
            ...$history->page($offset, $limit, $refresh),
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
    public function queueStatus(QueueManager $queueManager, QueueSizeResolver $sizeResolver): Response
    {
        return $this->json([
            'queue' => $sizeResolver->enrich($queueManager->getQueue()),
            'active' => ($active = $queueManager->getActiveTask()) ? $sizeResolver->enrichItem($active) : null
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
        if (!$storage->hasMusicPath()) {
            return $this->render('dashboard/locked.html.twig', [
                'title' => 'Music Configuration Required',
                'message' => 'The music downloader requires a existing download path to be configured.',
                'requirements' => [
                    'Music Download Path (Settings > Music Settings)'
                ]
            ]);
        }

        if (!$storage->hasSpotify()) {
            return $this->render('dashboard/locked.html.twig', [
                'title' => 'Spotify Credentials Required',
                'message' => 'The music search screen requires Spotify API credentials and a valid credentials file path.',
                'requirements' => [
                    'Spotify Client ID',
                    'Spotify Client Secret',
                    'Music Credentials Path (existing file)'
                ]
            ]);
        }

        return $this->render('dashboard/music.html.twig', [
            'config' => $storage->get('config', [])
        ]);
    }

    #[Route('/music-files', name: 'music_files')]
    public function musicFiles(): Response
    {
        return $this->redirectToRoute('music_library', ['root' => 'downloads']);
    }

    #[Route('/music/stream', name: 'music_stream', methods: ['GET'])]
    public function musicStream(Request $request, JsonStorage $storage): Response
    {
        $config = $storage->get('config', []);
        $libraryRoot = $config['music_library_path'] ?? '';
        $downloadRoot = $config['music_root_path'] ?? '';
        $libraryReal = $libraryRoot ? realpath($libraryRoot) : false;
        $downloadReal = $downloadRoot ? realpath($downloadRoot) : false;
        $rootMap = [
            'downloads' => $downloadReal && is_dir($downloadReal) ? $downloadReal : null,
            'library' => $libraryReal && is_dir($libraryReal) ? $libraryReal : null,
        ];
        $requestedRoot = $request->query->get('root', '');
        $requestedRoot = is_string($requestedRoot) ? $requestedRoot : '';
        $primaryRoot = $requestedRoot !== ''
            ? ($rootMap[$requestedRoot] ?? null)
            : ($rootMap['library'] ?? $rootMap['downloads']);

        $target = (string) $request->query->get('path', '');
        if ($target !== '' && $primaryRoot) {
            $isAbsolute = str_starts_with($target, '/')
                || str_starts_with($target, '\\\\')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $target) === 1;
            if (!$isAbsolute) {
                $relative = ltrim(str_replace('\\', '/', $target), '/');
                $suffix = str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $target = $primaryRoot . DIRECTORY_SEPARATOR . $suffix;
                if ($requestedRoot === '' && !is_file($target) && $rootMap['downloads'] && $rootMap['downloads'] !== $primaryRoot) {
                    $fallback = $rootMap['downloads'] . DIRECTORY_SEPARATOR . $suffix;
                    if (is_file($fallback)) {
                        $target = $fallback;
                    }
                }
            }
        }

        $targetReal = $target !== '' ? realpath($target) : false;
        $allowedRoots = [];
        foreach ([$libraryReal, $downloadReal] as $allowed) {
            if ($allowed && is_dir($allowed)) {
                $allowedRoots[] = rtrim($allowed, '/\\') . DIRECTORY_SEPARATOR;
            }
        }

        $isAllowed = false;
        if ($targetReal !== false) {
            foreach ($allowedRoots as $prefix) {
                if (str_starts_with($targetReal, $prefix)) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        if (!$isAllowed || !$targetReal || !is_file($targetReal)) {
            return new Response('File not found.', 404);
        }

        $extension = strtolower(pathinfo($targetReal, PATHINFO_EXTENSION));
        if (!in_array($extension, ['mp3', 'flac', 'm4a', 'opus', 'ogg', 'wav'], true)) {
            return new Response('Unsupported media type.', 415);
        }

        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $size = filesize($targetReal);
        if ($size === false || $size <= 0) {
            return new Response('File is empty.', 404);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => $this->getAudioMimeType($targetReal),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $range = $request->headers->get('Range');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            if ($matches[1] !== '') {
                $start = (int) $matches[1];
            }
            if ($matches[2] !== '') {
                $end = (int) $matches[2];
            }
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffixLength = (int) $matches[2];
                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
            }

            $end = min($end, $size - 1);
            if ($start >= $size || $start > $end) {
                return new Response('Requested range not satisfiable.', 416, [
                    'Content-Range' => 'bytes */' . $size,
                ]);
            }

            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }

        $headers['Content-Length'] = (string) ($end - $start + 1);

        return new StreamedResponse(function () use ($targetReal, $start, $end): void {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');

            $handle = fopen($targetReal, 'rb');
            if ($handle === false) {
                return;
            }

            fseek($handle, $start);
            $remaining = $end - $start + 1;

            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }

            fclose($handle);
        }, $status, $headers);
    }

    private function getAudioMimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && str_starts_with($mime, 'audio/')) {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'opus', 'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };
    }

    #[Route('/file-tags', name: 'file_tags', methods: ['POST'])]
    public function fileTags(Request $request, KernelInterface $kernel, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        $filePath = $data['path'] ?? '';

        if (empty($filePath) || !file_exists($filePath)) {
            return $this->json(['success' => false, 'message' => 'File not found: ' . $filePath]);
        }

        $config = $storage->get('config', []);
        $venvPath = $config['music_venv_path'] ?? 'venv';
        $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'music_downloader.py';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

        // Build shell command
        $cmdStr = "($activate && python3 " . escapeshellarg($script) . " --tags " . escapeshellarg($filePath) . ")";

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

    #[Route('/update-tags', name: 'update_tags', methods: ['POST'])]
    public function updateTags(Request $request, KernelInterface $kernel, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        $filePath = $data['path'] ?? '';
        $tags = $data['tags'] ?? [];

        if (empty($filePath) || !file_exists($filePath)) {
            return $this->json(['success' => false, 'message' => 'File not found']);
        }

        if (empty($tags)) {
            return $this->json(['success' => false, 'message' => 'No tags provided']);
        }

        $config = $storage->get('config', []);
        $venvPath = $config['music_venv_path'] ?? 'venv';
        $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'music_downloader.py';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

        // Build shell command
        $cmdStr = "($activate && python3 " . escapeshellarg($script) . " --update-tags " . escapeshellarg($filePath);
        foreach ($tags as $name => $value) {
            $cmdStr .= " --$name " . escapeshellarg($value);
        }
        $cmdStr .= ")";

        $process = Process::fromShellCommandline($cmdStr);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json([
                'success' => false,
                'message' => 'Failed to update tags: ' . $process->getErrorOutput()
            ]);
        }

        return $this->json(['success' => true]);
    }

    #[Route('/queue', name: 'queue')]
    public function queue(QueueManager $queueManager, QueueSizeResolver $sizeResolver): Response
    {
        return $this->render('dashboard/queue.html.twig', [
            'queue' => $sizeResolver->enrich($queueManager->getQueue()),
            'active' => ($active = $queueManager->getActiveTask()) ? $sizeResolver->enrichItem($active) : null
        ]);
    }

    #[Route('/music-add', name: 'music_add', methods: ['POST'])]
    public function musicAdd(Request $request, QueueManager $queueManager, JsonStorage $storage, SpotifyService $spotify, SpotifyUserService $spotifyUser): Response
    {
        if (!$storage->hasSpotify()) {
            return $this->json([
                'success' => false,
                'message' => 'Spotify credentials or credentials file missing. Please check your settings.'
            ]);
        }

        if (!$spotify->testCredentials()) {
            return $this->json([
                'success' => false,
                'message' => 'Spotify authentication failed. Please check your Client ID and Client Secret in Settings.'
            ]);
        }

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
            $metadata = null;
            $metadataLogs = [];
            $parsed = $spotify->parseUrl($url);

            if (($parsed['type'] ?? null) === 'playlist') {
                $metadata = $spotifyUser->getPlaylistMetadata($parsed['id']);
                if ($metadata) {
                    $metadataLogs[] = 'Playlist metadata fetched with Zotify user token.';
                } elseif ($spotifyUser->getLastError()) {
                    $metadataLogs[] = 'User-token playlist metadata failed: ' . $spotifyUser->getLastError();
                }
            }

            if (!$metadata) {
                $metadata = $spotify->getMetadata($url);
                $metadataLogs = array_merge($metadataLogs, $spotify->getLogs());
            }

            $downloadId = bin2hex(random_bytes(8));

            // Log metadata extraction attempt
            $historyLogFile = $logsDir . '/history_' . $downloadId . '.log';
            $logContent = "--- Spotify Metadata Extraction ---\n";
            $logContent .= implode("\n", $metadataLogs) . "\n";
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
    public function queueActiveLog(QueueManager $queueManager, JsonStorage $storage, LogRedactor $redactor): Response
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
        return $this->json(['log' => $redactor->redact(implode("", $lastLines))]);
    }

    #[Route('/history-log/{downloadId}', name: 'history_log', methods: ['GET'])]
    public function historyLog(string $downloadId, JsonStorage $storage, LogRedactor $redactor): Response
    {
        $logFile = $storage->getStorageDir() . '/logs/history_' . $downloadId . '.log';
        if (!file_exists($logFile)) {
            return new Response('Log file not found.', 404);
        }

        return new Response($redactor->redact((string) file_get_contents($logFile)), 200, [
            'Content-Type' => 'text/plain'
        ]);
    }

    #[Route('/notifications-poll', name: 'notifications_poll', methods: ['GET'])]
    public function notificationsPoll(JsonStorage $storage, ServerNotificationService $notifications): Response
    {
        return $this->json([
            'notifications' => $notifications->all($storage),
        ]);
    }

    #[Route('/notifications-ack', name: 'notifications_ack', methods: ['POST'])]
    public function notificationsAck(
        Request $request,
        JsonStorage $storage,
        ServerNotificationService $notifications,
        CsrfTokenService $csrf
    ): Response {
        if (!$csrf->isValid($request, $csrf->fromRequest($request))) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $ids = is_array($payload) && is_array($payload['ids'] ?? null) ? $payload['ids'] : [];

        return $this->json([
            'success' => true,
            'acknowledged' => $notifications->acknowledge($storage, $ids),
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
        $path = $request->request->get('path');
        if (!$path) {
            return $this->json(['success' => false, 'message' => 'Path missing'], 400);
        }

        $recentPaths = $storage->get('recent_paths', []);
        $recentPaths = array_values(array_filter($recentPaths, fn($p) => $p !== $path));
        $storage->set('recent_paths', $recentPaths);

        return $this->json(['success' => true]);
    }

    #[Route('/purge-history', name: 'purge_history', methods: ['POST'])]
    public function purgeHistory(AlldebridService $debrid, DebridHistoryService $history): Response
    {
        $result = $debrid->purgeHistory();
        if ($result['success'] ?? false) {
            $history->invalidate();
        }
        return $this->json($result);
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

    #[Route('/music/move-to-library', name: 'move_to_library', methods: ['POST'])]
    public function moveToLibrary(Request $request, JsonStorage $storage, KernelInterface $kernel, AiService $ai): Response
    {
        try {
            $config = $storage->get('config', []);
            $sourcePath = $config['music_root_path'] ?? '';
            $libraryPath = $config['music_library_path'] ?? '';
            $mode = $config['music_genre_tagging_mode'] ?? 'ai';
            $mapping = $config['music_genre_mapping'] ?? '{}';
            $aiPrompt = $config['music_genre_grok_prompt'] ?? '';
            $aiRuntime = $ai->getRuntimeConfig('music_genre');
            $venvPath = $config['music_venv_path'] ?? 'venv';

            if (!$sourcePath || !$libraryPath) {
                return new Response(json_encode(['error' => 'Source or Library path not configured.']), 400);
            }

            $target = $this->resolveMusicFileTarget($request, $storage);
            if ($target['error'] !== null) {
                return $this->json(['success' => false, 'message' => $target['error']], 400);
            }
            $isPerFile = $target['requested'];
            $processSource = $isPerFile ? $target['path'] : $sourcePath;

            $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'tag_rename_move.py';

            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

            $cmd = "($activate && python3 " . escapeshellarg($script) . " " .
                "--source " . escapeshellarg($processSource) . " " .
                "--library " . escapeshellarg($libraryPath) . " " .
                "--mode " . escapeshellarg($mode) . " " .
                "--mapping " . escapeshellarg($mapping) . " " .
                "--ai-endpoint " . escapeshellarg($aiRuntime['endpoint']) . " " .
                "--ai-key " . escapeshellarg($aiRuntime['api_key']) . " " .
                "--ai-model " . escapeshellarg($aiRuntime['model']) . " " .
                "--ai-referer " . escapeshellarg($aiRuntime['referer']) . " " .
                "--ai-title " . escapeshellarg($aiRuntime['title']) . " " .
                "--ai-prompt " . escapeshellarg($aiPrompt) . ")";

            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                return new Response(json_encode([
                    'success' => false,
                    'error' => 'Script failed.',
                    'message' => 'Script failed.',
                    'details' => $process->getErrorOutput(),
                    'cmd' => $cmd
                ]), 500);
            }

            $output = json_decode($process->getOutput(), true);
            $results = $output['results'] ?? [];

            if ($isPerFile) {
                $result = $results[0] ?? ['status' => 'error', 'error' => 'No result returned by script.'];

                return $this->json([
                    'success' => ($result['status'] ?? 'error') !== 'error',
                    'status' => $result['status'] ?? 'error',
                    'result' => $result,
                    'message' => $result['error'] ?? null,
                ]);
            }

            // Determine aggregated status
            $params = ['error' => 0, 'warning' => 0, 'success' => 0];
            foreach ($results as $res) {
                $s = $res['status'] ?? 'success';
                if (isset($params[$s]))
                    $params[$s]++;
            }

            $globalStatus = 'success';
            if ($params['error'] > 0) {
                $globalStatus = ($params['error'] === count($results)) ? 'error' : 'warning';
            } elseif ($params['warning'] > 0) {
                $globalStatus = 'warning';
            }

            // Add history entry
            $history = $storage->get('history', []);
            $history[] = [
                'date' => date('Y-m-d H:i:s'),
                'status' => $globalStatus,
                'action' => 'library_move',
                'type' => 'music',
                'filename' => 'Moved files to music library',
                'file_count' => count($results),
                'message' => 'Processed ' . count($results) . ' files into ' . $libraryPath,
                'details' => $results
            ];

            // Enforce history limit
            $limit = (int) ($config['history_retention_limit'] ?? 100);
            if ($limit < 10)
                $limit = 10;

            if (count($history) > $limit) {
                $history = array_slice($history, -$limit);
            }

            $storage->set('history', array_values($history));

            // Server-side notification
            $notifs = $storage->get('server_notifications', []);
            $notifs[] = [
                'id' => uniqid(),
                'timestamp' => time(),
                'type' => 'success',
                'action' => 'Finished',
                'media_type' => 'music',
                'item' => count($results) . ' files moved to Library'
            ];
            $storage->set('server_notifications', $notifs);

            return new Response(json_encode([
                'success' => true,
                'status' => 'success',
                'processed' => count($results),
                'results' => $results
            ]), 200, ['Content-Type' => 'application/json']);

        } catch (\Exception $e) {
            return new Response(json_encode(['success' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()]), 500);
        }
    }

    private function resolveMusicFileTarget(Request $request, JsonStorage $storage): array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['path'])) {
            return ['requested' => false, 'path' => null, 'error' => null];
        }

        $config = $storage->get('config', []);
        $allowedRoots = [];
        foreach ([$config['music_root_path'] ?? '', $config['music_library_path'] ?? ''] as $root) {
            $real = $root ? realpath($root) : false;
            if ($real !== false && is_dir($real)) {
                $allowedRoots[] = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
            }
        }
        $targetReal = realpath((string) $data['path']);

        if ($allowedRoots === [] || !$targetReal || !is_file($targetReal)) {
            return ['requested' => true, 'path' => null, 'error' => 'Invalid file path specified.'];
        }

        $allowed = false;
        foreach ($allowedRoots as $rootPrefix) {
            if (str_starts_with($targetReal, $rootPrefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return ['requested' => true, 'path' => null, 'error' => 'File path is outside the music roots.'];
        }

        if (!is_writable($targetReal)) {
            return [
                'requested' => true,
                'path' => null,
                'error' => 'File is not writable by the application user (www-data, UID 1000). '
                    . 'Fix ownership on the host with: sudo chown -R 1000:1000 <downloads directory>.',
            ];
        }

        return ['requested' => true, 'path' => $targetReal, 'error' => null];
    }

    #[Route('/music/batch/record', name: 'music_batch_record', methods: ['POST'])]
    public function musicBatchRecord(Request $request, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $action = (string) ($data['action'] ?? '');
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $labels = [
            'genres' => 'Genre update',
            'lyrics' => 'Lyrics fetching',
            'library_move' => 'Library move',
        ];

        if (!isset($labels[$action])) {
            return $this->json(['success' => false, 'message' => 'Invalid batch action.'], 400);
        }

        $counts = ['success' => 0, 'warning' => 0, 'error' => 0, 'skipped' => 0];
        foreach ($results as $result) {
            $status = $result['status'] ?? 'success';
            if ($status === 'found' || $status === 'completed') {
                $status = 'success';
            } elseif ($status === 'not_found') {
                $status = 'warning';
            }
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $total = count($results);
        $globalStatus = 'success';
        if ($counts['error'] > 0) {
            $globalStatus = ($counts['error'] === $total) ? 'error' : 'warning';
        } elseif ($counts['warning'] > 0) {
            $globalStatus = 'warning';
        }

        if ($action === 'library_move') {
            $config = $storage->get('config', []);
            $libraryPath = $config['music_library_path'] ?? '';
            $history = $storage->get('history', []);
            $history[] = [
                'date' => date('Y-m-d H:i:s'),
                'status' => $globalStatus,
                'action' => 'library_move',
                'type' => 'music',
                'filename' => 'Moved files to music library',
                'file_count' => $total,
                'message' => 'Processed ' . $total . ' files into ' . $libraryPath,
                'details' => $results,
            ];

            $limit = (int) ($config['history_retention_limit'] ?? 100);
            if ($limit < 10) {
                $limit = 10;
            }
            if (count($history) > $limit) {
                $history = array_slice($history, -$limit);
            }
            $storage->set('history', array_values($history));
        }

        $notifs = $storage->get('server_notifications', []);
        $notifs[] = [
            'id' => uniqid(),
            'timestamp' => time(),
            'type' => $globalStatus === 'error' ? 'error' : ($globalStatus === 'warning' ? 'info' : 'success'),
            'action' => 'Finished',
            'media_type' => 'music',
            'item' => $labels[$action] . ' completed: ' . $counts['success'] . ' success, '
                . $counts['warning'] . ' warning, ' . $counts['error'] . ' error, '
                . $counts['skipped'] . ' skipped',
        ];
        $storage->set('server_notifications', $notifs);

        return $this->json([
            'success' => true,
            'status' => $globalStatus,
            'counts' => $counts,
            'total' => $total,
        ]);
    }

    #[Route('/dashboard/upload-torrent', name: 'upload_torrent', methods: ['POST'])]
    public function uploadTorrent(Request $request, AlldebridService $debrid): Response
    {
        try {
            $magnet = $request->request->get('magnet');
            $file = $request->files->get('file');

            if ($magnet) {
                $result = $debrid->uploadMagnet([trim($magnet)]);
            } elseif ($file) {
                $result = $debrid->uploadTorrent($file->getPathname(), $file->getClientOriginalName());
            } else {
                return $this->json(['success' => false, 'message' => 'No magnet or file provided']);
            }

            $magnetId = $result['data']['magnets'][0]['id'] ?? $result['data']['files'][0]['id'] ?? null;

            if ($result['success'] && $magnetId) {
                $maxRetries = 10;
                $saveResult = null;
                $magnetData = null;

                $extractLinks = function ($items) use (&$extractLinks) {
                    $found = [];
                    foreach ($items as $item) {
                        if (isset($item['l']) && isset($item['n'])) {
                            $found[] = ['link' => $item['l'], 'filename' => $item['n']];
                        } elseif (isset($item['link']) && isset($item['filename'])) {
                            $found[] = ['link' => $item['link'], 'filename' => $item['filename']];
                        }
                        if (isset($item['e']) && is_array($item['e'])) {
                            $found = array_merge($found, $extractLinks($item['e']));
                        }
                    }
                    return $found;
                };

                for ($i = 0; $i < $maxRetries; $i++) {
                    $saveResult = $debrid->saveMagnet((int) $magnetId);
                    $magnetsRetry = $saveResult['data']['magnets'] ?? [];

                    if (isset($magnetsRetry['files']) || isset($magnetsRetry['links'])) {
                        $magnetData = $magnetsRetry;
                    } elseif (is_array($magnetsRetry)) {
                        foreach ($magnetsRetry as $item) {
                            if (is_array($item) && (isset($item['files']) || isset($item['links']))) {
                                $magnetData = $item;
                                break;
                            }
                        }
                    }

                    $allLinksRetry = $extractLinks($magnetData['links'] ?? $magnetData['files'] ?? []);
                    if (!empty($allLinksRetry) && ($magnetData['statusCode'] ?? 0) === 4) {
                        break;
                    }
                    if (($magnetData['statusCode'] ?? 0) > 4) {
                        break;
                    }
                    if ($i < $maxRetries - 1)
                        sleep(1);
                }

                if (!$saveResult['success']) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Upload successful but failed to get status: ' . ($saveResult['message'] ?? 'Unknown error'),
                        'debug_save_result' => $saveResult
                    ]);
                }

                $linksRecap = [];
                $filesToProcess = $magnetData['links'] ?? $magnetData['files'] ?? [];
                $allLinks = $extractLinks($filesToProcess);

                if (!empty($allLinks)) {
                    foreach ($allLinks as $fileData) {
                        $rawLink = $fileData['link'];
                        if ($rawLink) {
                            $unlockedLink = $debrid->unlockLink($rawLink);
                            if ($unlockedLink) {
                                $streamingLink = $debrid->getStreamingLink($unlockedLink);
                                $linksRecap[] = [
                                    'filename' => $fileData['filename'],
                                    'unlocked' => $unlockedLink,
                                    'streaming' => $streamingLink ?: $unlockedLink
                                ];
                            }
                        }
                    }
                }

                $finalMessage = "Torrent uploaded successfully.";
                if (empty($linksRecap)) {
                    $status = $magnetData['status'] ?? 'Unknown';
                    $finalMessage .= " However, no downloadable links were found (Status: $status).";
                } else {
                    $finalMessage .= " " . count($linksRecap) . " file(s) processed.";
                }

                return $this->json([
                    'success' => true,
                    'message' => $finalMessage,
                    'links' => $linksRecap,
                    'debug_save_result' => $saveResult
                ]);
            }

            return $this->json($result);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/dashboard/upload-direct-link', name: 'upload_direct_link', methods: ['POST'])]
    public function uploadDirectLink(Request $request, AlldebridService $debrid): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            $links = $data['links'] ?? [];

            if (empty($links)) {
                return $this->json(['success' => false, 'message' => 'No links provided']);
            }

            $linksRecap = [];
            $errors = [];

            foreach ($links as $rawLink) {
                $rawLink = trim($rawLink);
                if (empty($rawLink))
                    continue;

                $unlocked = $debrid->unlockLinkFull($rawLink);
                if ($unlocked) {
                    $streamingLink = $debrid->getStreamingLink($unlocked['link']);
                    $linksRecap[] = [
                        'filename' => $unlocked['filename'],
                        'filesize' => $unlocked['filesize'],
                        'host' => $unlocked['host'],
                        'unlocked' => $unlocked['link'],
                        'streaming' => $streamingLink ?: $unlocked['link'],
                    ];
                } else {
                    $errors[] = $rawLink;
                }
            }

            $message = count($linksRecap) . ' link(s) unlocked successfully.';
            if (!empty($errors)) {
                $message .= ' ' . count($errors) . ' link(s) failed: ' . implode(', ', array_map(fn($e) => substr($e, 0, 60) . '...', $errors));
            }

            return $this->json([
                'success' => !empty($linksRecap),
                'message' => $message,
                'links' => $linksRecap,
                'errors' => $errors,
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/dashboard/magnet-info', name: 'magnet_info', methods: ['POST'])]
    public function magnetInfo(Request $request, AlldebridService $debrid): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            $magnet = $data['magnet'] ?? '';

            if (!$magnet) {
                return $this->json(['success' => false, 'message' => 'No magnet provided']);
            }

            // Upload the magnet to get its ID
            $uploadResult = $debrid->uploadMagnet([trim($magnet)]);
            if (!$uploadResult['success']) {
                return $this->json(['success' => false, 'message' => $uploadResult['message'] ?? 'Upload failed']);
            }

            $magnetId = $uploadResult['data']['magnets'][0]['id'] ?? null;
            if (!$magnetId) {
                return $this->json(['success' => false, 'message' => 'Could not get magnet ID']);
            }

            // Get magnet status
            $saveResult = $debrid->saveMagnet((int) $magnetId);
            $magnetData = null;
            $magnetsData = $saveResult['data']['magnets'] ?? [];

            if (isset($magnetsData['files']) || isset($magnetsData['links'])) {
                $magnetData = $magnetsData;
            } elseif (is_array($magnetsData)) {
                foreach ($magnetsData as $item) {
                    if (is_array($item) && (isset($item['files']) || isset($item['links']))) {
                        $magnetData = $item;
                        break;
                    }
                }
            }

            if (!$magnetData) {
                return $this->json([
                    'success' => true,
                    'data' => [
                        'status' => 'pending',
                        'message' => 'Torrent is being processed by Alldebrid. Files not yet available.',
                        'files' => [],
                        'total_size' => 0
                    ]
                ]);
            }

            // Extract files recursively
            $extractFiles = function ($items, $parentPath = '') use (&$extractFiles) {
                $found = [];
                foreach ($items as $item) {
                    $name = $item['n'] ?? $item['filename'] ?? '';
                    $size = $item['s'] ?? 0;
                    $link = $item['l'] ?? $item['link'] ?? null;

                    if (isset($item['e']) && is_array($item['e'])) {
                        $subPath = $parentPath ? $parentPath . '/' . $name : $name;
                        $found = array_merge($found, $extractFiles($item['e'], $subPath));
                    } else {
                        $found[] = [
                            'filename' => $name,
                            'path' => $parentPath,
                            'size' => $size,
                            'has_link' => (bool) $link
                        ];
                    }
                }
                return $found;
            };

            $filesToProcess = $magnetData['files'] ?? $magnetData['links'] ?? [];
            $files = $extractFiles($filesToProcess);
            $totalSize = array_sum(array_column($files, 'size'));

            return $this->json([
                'success' => true,
                'data' => [
                    'status' => $magnetData['status'] ?? 'Ready',
                    'statusCode' => $magnetData['statusCode'] ?? 4,
                    'filename' => $magnetData['filename'] ?? 'Unknown',
                    'files' => $files,
                    'total_size' => $totalSize,
                    'file_count' => count($files)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/torrent-db/categories', name: 'torrent_db_categories', methods: ['GET'])]
    public function torrentDbCategories(TorrentDbService $torrentDb, JsonStorage $storage): Response
    {
        if (!$torrentDb->isDbInitialized()) {
            return $this->json([
                'success' => false,
                'error' => 'db_not_found',
                'message' => 'Database not initialized'
            ]);
        }

        $config = $storage->get('config', []);
        $allowXxx = $config['allow_xxx_search'] ?? false;

        return $this->json([
            'success' => true,
            'categories' => $torrentDb->getCategories($allowXxx)
        ]);
    }

    #[Route('/torrent-db/search', name: 'torrent_db_search', methods: ['POST'])]
    public function torrentDbSearch(Request $request, TorrentDbService $torrentDb): Response
    {
        $data = json_decode($request->getContent(), true);
        $categoryId = $data['categoryId'] ?? '';
        $query = $data['query'] ?? '';

        if (!$categoryId || !$query) {
            return $this->json(['success' => false, 'message' => 'Category and query required']);
        }

        $results = $torrentDb->search($categoryId, $query, 50);

        return $this->json([
            'success' => true,
            'results' => $results
        ]);
    }

    #[Route('/torrent-db/description', name: 'torrent_db_description', methods: ['POST'])]
    public function torrentDbDescription(Request $request, TorrentDbService $torrentDb): Response
    {
        $data = json_decode($request->getContent(), true);
        $hash = $data['hash'] ?? '';

        if (!$hash) {
            return $this->json(['success' => false, 'message' => 'Hash required']);
        }

        return $this->json([
            'success' => true,
            'description' => $torrentDb->getDescription($hash)
        ]);
    }

    #[Route('/torrent-db/preview', name: 'torrent_db_preview', methods: ['POST'])]
    public function torrentDbPreview(Request $request, AlldebridService $debrid): Response
    {
        $data = json_decode($request->getContent(), true);
        $hash = $data['hash'] ?? null;

        if (!$hash) {
            return $this->json(['success' => false, 'message' => 'Hash is required']);
        }

        // 1. Upload the hash (as a minimal magnet link) to get an ID
        $magnetLink = "magnet:?xt=urn:btih:" . $hash;
        $result = $debrid->uploadMagnet([$magnetLink]);

        if (!$result['success']) {
            return $this->json(['success' => false, 'message' => $result['message']]);
        }

        $magnetId = $result['data']['magnets'][0]['id'] ?? null;
        if (!$magnetId) {
            return $this->json(['success' => false, 'message' => 'No magnet ID returned from Alldebrid']);
        }

        // 2. Fetch full status via v4.1 endpoint (consistent with magnetInfo)
        $statusResult = $debrid->saveMagnet((int) $magnetId);
        $magnetData = $statusResult['data']['magnets'] ?? null;

        if (!$magnetData) {
            return $this->json(['success' => false, 'message' => 'No status returned from Alldebrid']);
        }

        // If returned as a list, pick the first one
        if (!isset($magnetData['statusCode']) && is_array($magnetData)) {
            foreach ($magnetData as $item) {
                if (is_array($item) && isset($item['statusCode'])) {
                    $magnetData = $item;
                    break;
                }
            }
        }

        $statusCode = $magnetData['statusCode'] ?? 0;
        $isReady = ($statusCode === 4);

        return $this->json([
            'success' => true,
            'filename' => $magnetData['filename'] ?? 'Unknown',
            'size' => $magnetData['size'] ?? 0,
            'status' => $magnetData['status'] ?? ($isReady ? 'Ready' : 'Not Ready'),
            'isReady' => $isReady
        ]);
    }

    #[Route('/music/update-genres', name: 'music_update_genres', methods: ['POST'])]
    public function musicUpdateGenres(Request $request, JsonStorage $storage, KernelInterface $kernel, AiService $ai): Response
    {
        try {
            $config = $storage->get('config', []);
            $requestData = json_decode($request->getContent(), true);
            $forceAi = is_array($requestData) && !empty($requestData['force_ai']);

            $sourcePath = $config['music_root_path'] ?? '';
            $mode = $forceAi ? 'ai' : ($config['music_genre_tagging_mode'] ?? 'ai');
            $mapping = $config['music_genre_mapping'] ?? '{}';
            $aiPrompt = $config['music_genre_grok_prompt'] ?? '';
            $aiRuntime = $ai->getRuntimeConfig('music_genre');
            $venvPath = $config['music_venv_path'] ?? 'venv';

            if ($forceAi && !$ai->hasAi('music_genre')) {
                return $this->json(['success' => false, 'message' => 'No AI model configured for music genre.'], 400);
            }

            if (!$sourcePath) {
                return $this->json(['success' => false, 'message' => 'Music root path not configured.']);
            }

            $target = $this->resolveMusicFileTarget($request, $storage);
            if ($target['error'] !== null) {
                return $this->json(['success' => false, 'message' => $target['error']], 400);
            }
            $isPerFile = $target['requested'];
            $processSource = $isPerFile ? $target['path'] : $sourcePath;

            $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'tag_rename_move.py';

            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

            $cmd = "($activate && python3 " . escapeshellarg($script) . " " .
                "--source " . escapeshellarg($processSource) . " " .
                "--library " . escapeshellarg($sourcePath) . " " . // Pass source as library since move is disabled
                "--mode " . escapeshellarg($mode) . " " .
                "--mapping " . escapeshellarg($mapping) . " " .
                "--ai-endpoint " . escapeshellarg($aiRuntime['endpoint']) . " " .
                "--ai-key " . escapeshellarg($aiRuntime['api_key']) . " " .
                "--ai-model " . escapeshellarg($aiRuntime['model']) . " " .
                "--ai-referer " . escapeshellarg($aiRuntime['referer']) . " " .
                "--ai-title " . escapeshellarg($aiRuntime['title']) . " " .
                "--ai-prompt " . escapeshellarg($aiPrompt) . " " .
                ($forceAi ? "--force-ai " : "") .
                "--tags-only)";

            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(300); // 5 minutes for AI processing
            $process->run();

            if (!$process->isSuccessful()) {
                return $this->json(['success' => false, 'message' => $process->getErrorOutput()]);
            }

            $output = json_decode($process->getOutput(), true);
            $results = $output['results'] ?? [];

            if ($isPerFile) {
                $result = $results[0] ?? ['status' => 'error', 'error' => 'No result returned by script.'];

                return $this->json([
                    'success' => ($result['status'] ?? 'error') !== 'error',
                    'status' => $result['status'] ?? 'error',
                    'result' => $result,
                    'message' => $result['error'] ?? null,
                ]);
            }

            return $this->json(['success' => true, 'output' => $output, 'results' => $results]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/music/fetch-lyrics', name: 'fetch_lyrics', methods: ['POST'])]
    public function fetchLyrics(Request $request, JsonStorage $storage, KernelInterface $kernel): Response
    {
        try {
            $config = $storage->get('config', []);
            $data = json_decode($request->getContent(), true);
            $targetPath = $data['path'] ?? null;

            $root = $config['music_root_path'] ?? '';

            $path = $root;
            // If a specific file is requested, use it if it exists
            if ($targetPath) {
                $rootReal = realpath($root);
                $fullTargetPath = realpath($targetPath);
                $rootPrefix = $rootReal ? rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR : null;

                if (!$rootPrefix || !$fullTargetPath || !is_file($fullTargetPath) || !str_starts_with($fullTargetPath, $rootPrefix)) {
                    return $this->json(['success' => false, 'message' => 'Invalid file path specified.'], 400);
                }
                if (!is_writable($fullTargetPath)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'File is not writable by the application user (www-data, UID 1000). '
                            . 'Fix ownership on the host with: sudo chown -R 1000:1000 <downloads directory>.',
                    ], 400);
                }
                $path = $fullTargetPath;
            }

            if (empty($path) || !file_exists($path)) {
                return $this->json(['success' => false, 'message' => 'Invalid path specified.']);
            }

            $script = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'lyrics_fetcher.py';

            // Determine OS and activate command
            $venvPath = $config['music_venv_path'] ?? 'venv';

            // Check if venv path is relative or absolute
            if (!file_exists($venvPath)) {
                // Try relative to project dir
                $venvPath = $kernel->getProjectDir() . DIRECTORY_SEPARATOR . $venvPath;
            }

            if (!file_exists($venvPath)) {
                return $this->json(['success' => false, 'message' => 'Virtual environment not found at: ' . $venvPath]);
            }

            // Determine OS and activate command
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

            // Build command: activate venv AND run script
            // Note: On Windows, use & or &&. Chaining with && ensures python runs only if activate succeeds.
            // We need to run inside a shell.
            $cmd = "($activate && python3 " . escapeshellarg($script) . " " . escapeshellarg($path) . " --force-save --add-unsync --json)";

            $process = Process::fromShellCommandline($cmd);
            $process->setWorkingDirectory($kernel->getProjectDir());
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Lyrics search failed.',
                    'details' => $process->getErrorOutput()
                ]);
            }

            $results = json_decode($process->getOutput(), true);

            if ($targetPath) {
                $result = is_array($results) ? ($results[0] ?? ['status' => 'error', 'reason' => 'No result returned by script.']) : ['status' => 'error', 'reason' => 'Invalid script output.'];

                return $this->json([
                    'success' => true,
                    'status' => $result['status'] ?? 'error',
                    'result' => $result,
                    'results' => $results,
                ]);
            }

            return $this->json([
                'success' => true,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
