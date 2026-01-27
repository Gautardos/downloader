<?php

namespace App\Controller;

use App\Service\AlldebridService;
use App\Service\DownloadManager;
use App\Service\GrokService;
use App\Service\JsonStorage;
use App\Service\QueueManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(AlldebridService $alldebrid, DownloadManager $downloadManager, JsonStorage $storage): Response
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
            'recent_paths' => $recentPaths
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
        ]);

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
    public function checkFiles(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $files = $data['files'] ?? [];
        $results = [];

        foreach ($files as $file) {
            $path = $file['path'];
            $filename = $file['filename'];

            // Clean paths and ensure they are absolute
            $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

            $exists = file_exists($fullPath);
            $size = $exists ? filesize($fullPath) : null;
            $folderExists = is_dir($path);

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
