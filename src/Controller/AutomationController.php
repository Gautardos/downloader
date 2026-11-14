<?php

namespace App\Controller;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\LibraryIndexService;
use App\Service\Automation\LibraryMetadataResolver;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\ReleaseIntentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AutomationController extends AbstractController
{
    private const ALLOWED_ROOT_PREFIXES = ['/nas', '/mnt', '/media', '/storage'];

    #[Route('/automation', name: 'automation', methods: ['GET'])]
    public function index(
        AutomationStore $store,
        AutomationSettings $settings,
        LibraryIndexService $libraryIndex,
        ListSyncService $listSync,
        ReleaseIntentService $releaseIntents
    ): Response {
        $lastScan = $store->getLastScan();
        $schedule = $settings->getSchedule();
        $nextScanAt = null;
        if ($lastScan) {
            $lastAt = strtotime((string) ($lastScan['finished_at'] ?? $lastScan['started_at'] ?? '')) ?: null;
            if ($lastAt) {
                $nextScanAt = $lastAt + max(300, (int) ($schedule['library'] ?? 21600));
            }
        }

        $scanState = $libraryIndex->getScanState();

        return $this->render('automation/index.html.twig', [
            'stats' => $store->getStats(),
            'root_stats' => $store->getRootStats(),
            'last_scan' => $lastScan,
            'running_scan' => $store->getRunningScan(),
            'scan_running' => $scanState['running'],
            'scan_lock_error' => $scanState['error'],
            'worker_online' => $store->isWorkerOnline(),
            'worker_heartbeat' => $store->getWorkerHeartbeat(),
            'next_scan_at' => $nextScanAt,
            'list_counts' => $store->getListStatusCounts(),
            'last_list_run' => $store->getLastListRun(),
            'running_list_run' => $store->getRunningListRun(),
            'list_running' => $listSync->isRunning(),
            'list_sync_requested' => (bool) $store->getSetting('lists_sync_requested', false),
            'intent_counts' => $store->getIntentStatusCounts(),
            'last_intent_run' => $store->getLastIntentRun(),
            'running_intent_run' => $store->getRunningIntentRun(),
            'intent_run_requested' => $releaseIntents->isRunRequested(),
            'intent_queue_depth' => $releaseIntents->getQueueDepth(),
            'auto_download_sources' => $releaseIntents->hasAutoDownloadSources(),
        ]);
    }

    #[Route('/automation/activity', name: 'automation_activity', methods: ['GET'])]
    public function activity(
        AutomationStore $store,
        AutomationSettings $settings,
        LibraryIndexService $libraryIndex,
        ListSyncService $listSync,
        ReleaseIntentService $releaseIntents
    ): Response {
        $lastScan = $store->getLastScan();
        $schedule = $settings->getSchedule();
        $nextScanAt = null;
        if ($lastScan) {
            $lastAt = strtotime((string) ($lastScan['finished_at'] ?? $lastScan['started_at'] ?? '')) ?: null;
            if ($lastAt) {
                $nextScanAt = $lastAt + max(300, (int) ($schedule['library'] ?? 21600));
            }
        }

        $scanState = $libraryIndex->getScanState();
        $lastResolution = $store->getSetting('library_resolution_last', null);
        $recentResolutions = $store->getRecentResolutions(12);
        if (empty($recentResolutions) && is_array($lastResolution)) {
            $recentResolutions[] = [
                'id' => 0,
                'started_at' => $lastResolution['finished_at'] ?? '—',
                'finished_at' => $lastResolution['finished_at'] ?? '—',
                'status' => !empty($lastResolution['success']) ? 'success' : 'error',
                'groups_seen' => (int) ($lastResolution['groups_seen'] ?? 0),
                'groups_resolved' => (int) ($lastResolution['groups_resolved'] ?? 0),
                'entries_resolved' => (int) ($lastResolution['entries_resolved'] ?? 0),
                'groups_skipped' => (int) ($lastResolution['groups_skipped'] ?? 0),
                'lookups' => (int) ($lastResolution['lookups'] ?? 0),
                'duration' => $lastResolution['duration'] ?? null,
                'error' => $lastResolution['error'] ?? null,
                'stats' => $lastResolution,
            ];
        }

        return $this->render('automation/activity.html.twig', [
            'worker_online' => $store->isWorkerOnline(),
            'worker_heartbeat' => $store->getWorkerHeartbeat(),
            'scan_running' => $scanState['running'],
            'scan_lock_error' => $scanState['error'],
            'last_scan' => $lastScan,
            'running_scan' => $store->getRunningScan(),
            'next_scan_at' => $nextScanAt,
            'resolve_requested' => (bool) $store->getSetting('library_resolve_requested', false),
            'last_resolution' => $lastResolution,
            'recent_scans' => $store->getRecentScans(12),
            'recent_resolutions' => $recentResolutions,
            'recent_rename_runs' => $store->getRecentRenameRuns(12),
            'recent_list_runs' => $store->getRecentListRuns(12),
            'list_running' => $listSync->isRunning(),
            'list_sync_requested' => (bool) $store->getSetting('lists_sync_requested', false),
            'list_requested_at' => $listSync->getRequestedAt(),
            'running_list_run' => $store->getRunningListRun(),
            'list_last_run' => $store->getLastListRun(),
            'recent_intent_runs' => $store->getRecentIntentRuns(12),
            'intent_run_requested' => $releaseIntents->isRunRequested(),
            'running_intent_run' => $store->getRunningIntentRun(),
            'intent_last_run' => $store->getLastIntentRun(),
            'intent_counts' => $store->getIntentStatusCounts(),
            'intent_queue_depth' => $releaseIntents->getQueueDepth(),
            'stats' => $store->getStats(),
        ]);
    }

    #[Route('/automation/scan', name: 'automation_scan', methods: ['POST'])]
    public function scan(Request $request, AutomationSettings $settings, LibraryIndexService $libraryIndex): Response
    {
        $data = json_decode($request->getContent(), true);
        $root = null;
        $token = $request->headers->get('X-Automation-Csrf');

        if (is_array($data)) {
            $root = isset($data['root']) ? trim((string) $data['root']) : null;
            $token = $data['csrf'] ?? $token;
        } else {
            $root = trim((string) $request->request->get('root', ''));
            $token = $request->request->get('_token', $token);
        }

        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        if ($root !== null && $root !== '' && !array_key_exists($root, $settings->getRoots())) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $libraryIndex->startBackgroundScan($root ?: null);

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/resolve', name: 'automation_resolve', methods: ['POST'])]
    public function resolve(Request $request, LibraryMetadataResolver $metadataResolver): Response
    {
        $data = json_decode($request->getContent(), true);
        $token = $request->headers->get('X-Automation-Csrf');
        if (is_array($data)) {
            $token = $data['csrf'] ?? $token;
        } else {
            $token = $request->request->get('_token', $token);
        }

        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $metadataResolver->requestBackgroundResolve();

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/attention/search', name: 'automation_attention_search', methods: ['POST'])]
    public function attentionSearch(Request $request, LibraryMetadataResolver $metadataResolver): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $group = $this->normalizeAttentionGroup(is_array($data['group'] ?? null) ? $data['group'] : []);
        if ($group === null) {
            return $this->json(['success' => false, 'message' => 'Invalid attention group.'], 400);
        }

        $query = isset($data['query']) ? trim((string) $data['query']) : null;

        return $this->json([
            'success' => true,
            'group' => $group,
            'candidates' => $metadataResolver->searchCandidates($group, 20, $query ?: null),
        ]);
    }

    #[Route('/automation/attention/resolve', name: 'automation_attention_resolve', methods: ['POST'])]
    public function attentionResolve(Request $request, LibraryMetadataResolver $metadataResolver): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $group = $this->normalizeAttentionGroup(is_array($data['group'] ?? null) ? $data['group'] : []);
        if ($group === null) {
            return $this->json(['success' => false, 'message' => 'Invalid attention group.'], 400);
        }

        $tmdbId = (int) ($data['tmdb_id'] ?? 0);
        $type = isset($data['type']) ? (string) $data['type'] : null;
        if (($group['scope'] ?? 'entry') === 'show_folder' && in_array($group['media_type'], ['series', 'anime'], true) && !empty($group['show_folder'])) {
            $result = $metadataResolver->resolveShowFolderManually($group, $tmdbId, $type);
        } else {
            $result = $metadataResolver->resolveGroupManually($group, $tmdbId, $type);
        }

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/attention/ignore', name: 'automation_attention_ignore', methods: ['POST'])]
    public function attentionIgnore(Request $request, AutomationStore $store): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $group = $this->normalizeAttentionGroup(is_array($data['group'] ?? null) ? $data['group'] : []);
        if ($group === null) {
            return $this->json(['success' => false, 'message' => 'Invalid attention group.'], 400);
        }

        $ignored = !empty($data['ignored']);
        $updated = $store->ignoreGroup($group, $ignored);

        return $this->json([
            'success' => true,
            'updated' => $updated,
            'ignored' => $ignored,
        ]);
    }

    #[Route('/automation/status', name: 'automation_status', methods: ['GET'])]
    public function status(AutomationStore $store, LibraryIndexService $libraryIndex, ListSyncService $listSync, ReleaseIntentService $releaseIntents): Response
    {
        $lastScan = $store->getLastScan();
        $scanState = $libraryIndex->getScanState();
        $listState = $listSync->getRunState();

        return $this->json([
            'success' => true,
            'worker_online' => $store->isWorkerOnline(),
            'worker_heartbeat' => $store->getWorkerHeartbeat(),
            'scan_running' => $scanState['running'],
            'scan_lock_error' => $scanState['error'],
            'scan_requested' => (bool) $store->getSetting('library_scan_requested', false),
            'scan_requested_at' => (int) $store->getSetting('library_scan_requested_at', 0),
            'last_scan' => $lastScan,
            'running_scan' => $store->getRunningScan(),
            'stats' => $store->getStats(),
            'root_stats' => $store->getRootStats(),
            'resolve_requested' => (bool) $store->getSetting('library_resolve_requested', false),
            'resolve_requested_at' => (int) $store->getSetting('library_resolve_requested_at', 0),
            'last_resolution' => $store->getSetting('library_resolution_last', null),
            'list_running' => $listState['running'],
            'list_sync_requested' => (bool) $store->getSetting('lists_sync_requested', false),
            'list_sync_requested_at' => $listSync->getRequestedAt(),
            'list_run' => $store->getRunningListRun(),
            'list_last_run' => $store->getLastListRun(),
            'intent_running' => $store->getRunningIntentRun() !== null,
            'intent_run_requested' => $releaseIntents->isRunRequested(),
            'intent_last_run' => $store->getLastIntentRun(),
            'intent_counts' => $store->getIntentStatusCounts(),
            'intent_queue_depth' => $releaseIntents->getQueueDepth(),
        ]);
    }

    #[Route('/automation/settings', name: 'automation_settings', methods: ['POST'])]
    public function saveSettings(Request $request, AutomationSettings $settings): Response
    {
        $payload = $request->request->all();
        $token = $payload['_token'] ?? $request->headers->get('X-Automation-Csrf');
        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('automation_config');
        }

        $current = $settings->all();
        $roots = is_array($current['roots'] ?? null) ? $current['roots'] : [];
        $postedRoots = is_array($payload['roots'] ?? null) ? $payload['roots'] : [];
        $errors = [];

        foreach ($roots as $key => $root) {
            if (!is_array($root)) {
                continue;
            }

            $posted = is_array($postedRoots[$key] ?? null) ? $postedRoots[$key] : [];
            $path = trim((string) ($posted['path'] ?? $root['path'] ?? ''));
            $enabled = isset($posted['enabled']) && (string) $posted['enabled'] === '1';

            if ($enabled) {
                $error = $this->validateRootPath($path);
                if ($error !== null) {
                    $errors[] = ($root['label'] ?? $key) . ': ' . $error;
                }
            }

            $roots[$key]['path'] = $path;
            $roots[$key]['enabled'] = $enabled;
        }

        if ($errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            return $this->redirectToRoute('automation_config');
        }

        $scanner = is_array($current['scanner'] ?? null) ? $current['scanner'] : [];
        $extensions = trim((string) ($payload['extensions'] ?? implode(',', $scanner['extensions'] ?? [])));
        $parsedExtensions = array_values(array_filter(array_map(
            static fn ($extension) => strtolower(trim((string) $extension)),
            explode(',', $extensions)
        )));
        $scanner['extensions'] = $parsedExtensions ?: $settings->defaults()['scanner']['extensions'];
        $scanner['skip_samples'] = isset($payload['skip_samples']);
        $scanner['read_nfo'] = isset($payload['read_nfo']);
        $scanner['follow_symlinks'] = isset($payload['follow_symlinks']);
        $scanner['min_size_mb'] = max(0, (int) ($payload['min_size_mb'] ?? 20));
        $scanner['scan_interval'] = max(300, (int) ($payload['scan_interval'] ?? 21600));

        $schedule = is_array($current['schedule'] ?? null) ? $current['schedule'] : [];
        $schedule['library'] = $scanner['scan_interval'];

        $settings->save([
            'roots' => $roots,
            'scanner' => $scanner,
            'schedule' => $schedule,
        ]);

        $this->addFlash('success', 'Automation settings saved.');

        return $this->redirectToRoute('automation_config');
    }

    private function normalizeAttentionGroup(array $data): ?array
    {
        $mediaType = (string) ($data['media_type'] ?? '');
        if (!in_array($mediaType, ['movie', 'series', 'anime'], true)) {
            return null;
        }

        $titleNormalized = trim((string) ($data['title_normalized'] ?? ''));
        if ($titleNormalized === '') {
            return null;
        }

        $title = trim((string) ($data['title'] ?? $titleNormalized));
        $year = isset($data['year']) && $data['year'] !== null && $data['year'] !== '' ? (int) $data['year'] : null;
        $showFolder = isset($data['show_folder']) && $data['show_folder'] !== '' ? (string) $data['show_folder'] : null;
        if ($mediaType === 'movie') {
            $showFolder = null;
        }

        return [
            'group_key' => (string) ($data['group_key'] ?? ($mediaType . '|' . $titleNormalized . '|' . ($year ?? 'unknown') . '|' . ($showFolder ?? ''))),
            'media_type' => $mediaType,
            'title' => $title,
            'title_normalized' => $titleNormalized,
            'year' => $year,
            'show_folder' => $showFolder,
            'scope' => in_array($data['scope'] ?? '', ['show_folder', 'entry'], true) ? (string) $data['scope'] : 'entry',
        ];
    }

    private function getAutomationCsrfToken(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get('automation_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('automation_csrf_token', $token);
        }

        return $token;
    }

    private function isAutomationCsrfValid(Request $request, ?string $token): bool
    {
        $expected = $request->getSession()->get('automation_csrf_token');

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    private function validateRootPath(string $path): ?string
    {
        if ($path === '') {
            return 'path is required when the root is enabled.';
        }
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            return 'path must be absolute and must not contain traversal segments.';
        }

        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $allowed = false;
        foreach (self::ALLOWED_ROOT_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix . '/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return 'path must be under /nas, /mnt, /media, or /storage.';
        }

        $real = realpath($path);
        if ($real !== false) {
            $real = rtrim(str_replace('\\', '/', $real), '/');
            $realAllowed = false;
            foreach (self::ALLOWED_ROOT_PREFIXES as $prefix) {
                if ($real === $prefix || str_starts_with($real, $prefix . '/')) {
                    $realAllowed = true;
                    break;
                }
            }
            if (!$realAllowed) {
                return 'resolved path escapes the allowed library roots.';
            }
        }

        return null;
    }
}
