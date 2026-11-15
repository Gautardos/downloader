<?php

namespace App\Controller;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\LibraryIndexService;
use App\Service\Automation\LibraryMetadataResolver;
use App\Service\Automation\LibraryRenameService;
use App\Service\Automation\LibraryTitleRefreshService;
use App\Service\TmdbService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AutomationLibraryController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/automation/library', name: 'automation_library', methods: ['GET'])]
    public function index(Request $request, AutomationStore $store, AutomationSettings $settings, LibraryRenameService $renameService, TmdbService $tmdb, LibraryMetadataResolver $metadataResolver, LibraryIndexService $libraryIndex, LibraryTitleRefreshService $titleRefresh): Response
    {
        $filters = $this->normalizeFilters([
            'status' => $request->query->get('status', 'all'),
            'type' => $request->query->get('type', 'all'),
            'q' => $request->query->get('q', ''),
        ]);
        $view = (string) $request->query->get('view', '');
        if (!in_array($view, ['flat', 'folder', 'discovery'], true)) {
            $view = $request->query->get('grouped', '1') === '0' ? 'flat' : 'folder';
        }
        $counts = $store->getLibraryFilterCounts();
        $roots = $settings->getRoots();
        $scanState = $libraryIndex->getScanState();
        $lastScan = $store->getLastScan();
        $scanRequestedAt = (int) $store->getSetting('library_scan_requested_at', 0);
        $scanRequested = (bool) $store->getSetting('library_scan_requested', false);
        $lastScanAt = $lastScan ? (strtotime((string) ($lastScan['finished_at'] ?? $lastScan['started_at'] ?? '')) ?: 0) : 0;
        if ($scanRequestedAt > 0 && $lastScanAt >= $scanRequestedAt) {
            $scanRequested = false;
        }
        $lastResolution = $store->getSetting('library_resolution_last', null);
        $resolveRequestedAt = (int) $store->getSetting('library_resolve_requested_at', 0);
        $resolveRequested = (bool) $store->getSetting('library_resolve_requested', false);
        $lastResolutionAt = is_array($lastResolution) && !empty($lastResolution['finished_at'])
            ? (strtotime((string) $lastResolution['finished_at']) ?: 0)
            : 0;
        if ($resolveRequestedAt > 0 && $lastResolutionAt >= $resolveRequestedAt) {
            $resolveRequested = false;
        }

        $globalPlan = $renameService->lightPreview([], 5000);
        $namingSummary = $this->summarizeNaming($globalPlan['items']);

        $entries = [];
        $namingMap = [];
        $total = 0;
        $page = 1;
        $pages = 1;
        $offset = 0;

        $seriesGroups = [];
        $groupTotal = 0;
        $groupPage = 1;
        $groupPages = 1;
        $groupOffset = 0;
        $groupPerPage = 25;
        $movieEntries = [];
        $movieNamingMap = [];

        $folderTree = [];
        $selectedRootKey = '';
        $selectedFolderPath = '';

        $discoveryElements = [];
        $elementTotal = 0;
        $elementPage = 1;
        $elementPages = 1;
        $elementOffset = 0;
        $elementPerPage = 25;

        if ($view === 'folder') {
            $treeEntries = $store->getLibraryEntriesForFolderTree($filters);
            $folderTree = $this->buildFolderTree($treeEntries, $roots);

            $selectedRootKey = trim((string) $request->query->get('root', ''));
            $selectedFolderPath = trim((string) $request->query->get('folder', ''), '/');

            if ($selectedRootKey === '' || !isset($folderTree[$selectedRootKey])) {
                $selectedRootKey = $folderTree ? (string) array_key_first($folderTree) : '';
            }
            if ($selectedRootKey !== '' && !$this->folderNodeExists($folderTree[$selectedRootKey], $selectedFolderPath)) {
                $selectedFolderPath = '';
            }

            if (empty($settings->getScanner()['full_folder_tree'])) {
                $folderTree = $this->pruneFolderTreeForLazyLoad($folderTree);
            }
        } elseif ($view === 'discovery') {
            $allEntries = $store->getLibraryEntries($filters, 2000, 0);
            $elements = [];
            foreach ($allEntries as $entry) {
                $mediaType = (string) $entry['media_type'];
                if ($mediaType === 'movie') {
                    $key = 'movie|' . (!empty($entry['tmdb_id'])
                        ? 'tmdb:' . $entry['tmdb_id']
                        : ($entry['title_normalized'] . '|' . ($entry['year'] ?? 'unknown')));
                } else {
                    $showFolder = (string) ($entry['show_folder'] ?? '');
                    $key = $mediaType . '|' . ($showFolder !== ''
                        ? $showFolder
                        : ($entry['title_normalized'] . '|' . ($entry['year'] ?? 'unknown')));
                }
                $elements[$key]['entries'][] = $entry;
            }

            foreach ($elements as $key => &$element) {
                $elementEntries = $element['entries'];
                $first = $elementEntries[0];
                $elementNamingMap = $this->buildNamingMap($elementEntries, $renameService);
                $naming = ['conform' => 0, 'rename' => 0, 'conflict' => 0, 'skip' => 0];
                foreach ($elementNamingMap as $namingItem) {
                    $status = $namingItem['status'];
                    if ($status === 'conform') {
                        $naming['conform']++;
                    } elseif ($status === 'ok') {
                        $naming['rename']++;
                    } elseif ($status === 'conflict') {
                        $naming['conflict']++;
                    } else {
                        $naming['skip']++;
                    }
                }

                $matched = 0;
                $missing = 0;
                $ignored = 0;
                $seasons = [];
                foreach ($elementEntries as $entry) {
                    if (!empty($entry['tmdb_id']) || !empty($entry['imdb_id'])) {
                        $matched++;
                    }
                    if (($entry['status'] ?? '') === 'missing') {
                        $missing++;
                    }
                    if (!empty($entry['ignored_at'])) {
                        $ignored++;
                    }
                    if ($entry['season'] !== null) {
                        $seasons[(int) $entry['season']] = true;
                    }
                }

                $element['data'] = [
                    'key' => $key,
                    'media_type' => (string) $first['media_type'],
                    'title' => (string) $first['title'],
                    'year' => $first['year'] !== null ? (int) $first['year'] : null,
                    'show_folder' => $first['show_folder'],
                    'folder_path' => (string) ($first['folder_path'] ?? ''),
                    'tmdb_id' => $first['tmdb_id'] !== null ? (int) $first['tmdb_id'] : null,
                    'imdb_id' => $first['imdb_id'],
                    'files' => count($elementEntries),
                    'seasons' => count($seasons),
                    'matched' => $matched,
                    'missing' => $missing,
                    'ignored' => $ignored,
                    'naming' => $naming,
                    'match_group' => $this->buildMatchGroup($first),
                    'match_id' => $first['tmdb_id'] ?: '',
                    'match_type' => $first['media_type'] === 'movie' ? 'movie' : 'series',
                ];
            }
            unset($element);

            $discoveryElements = array_values(array_map(static fn ($element) => $element['data'], $elements));
            usort($discoveryElements, static function ($a, $b) {
                return strcasecmp($a['title'] . ' ' . ($a['year'] ?? ''), $b['title'] . ' ' . ($b['year'] ?? ''));
            });

            $elementTotal = count($discoveryElements);
            $elementPages = max(1, (int) ceil($elementTotal / $elementPerPage));
            $elementPage = min(max(1, (int) $request->query->get('page', 1)), $elementPages);
            $elementOffset = ($elementPage - 1) * $elementPerPage;
            $discoveryElements = array_slice($discoveryElements, $elementOffset, $elementPerPage);

            foreach ($discoveryElements as &$element) {
                if (empty($element['tmdb_id'])) {
                    continue;
                }
                $type = $element['media_type'] === 'movie' ? 'movie' : 'series';
                $details = $tmdb->details($type, (int) $element['tmdb_id']);
                if (!$details) {
                    continue;
                }
                $element['poster'] = $details['poster'] ?? null;
                $element['backdrop'] = $details['backdrop'] ?? null;
                $element['rating'] = $details['rating'] ?? null;
                $element['overview'] = $details['overview'] ?? '';
                $element['genres'] = $details['genres'] ?? [];
                $element['original_title'] = $details['original_title'] ?? null;
                $element['imdb_id'] = $element['imdb_id'] ?: ($details['imdb_id'] ?? null);
            }
            unset($element);
        } else {
            $page = max(1, (int) $request->query->get('page', 1));
            $total = $store->countLibraryEntries($filters);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min($page, $pages);
            $offset = ($page - 1) * self::PER_PAGE;
            $entries = $store->getLibraryEntries($filters, self::PER_PAGE, $offset);
            $namingMap = $this->buildNamingMap($entries, $renameService);
        }

        return $this->render('automation/library.html.twig', [
            'filters' => $filters,
            'view' => $view,
            'entries' => $entries,
            'naming_map' => $namingMap,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'offset' => $offset,
            'series_groups' => $seriesGroups,
            'group_total' => $groupTotal,
            'group_page' => $groupPage,
            'group_pages' => $groupPages,
            'group_per_page' => $groupPerPage,
            'group_offset' => $groupOffset,
            'movie_entries' => $movieEntries,
            'movie_naming_map' => $movieNamingMap,
            'folder_tree' => array_values($folderTree),
            'selected_root_key' => $selectedRootKey,
            'selected_folder_path' => $selectedFolderPath,
            'discovery_elements' => $discoveryElements,
            'element_total' => $elementTotal,
            'element_page' => $elementPage,
            'element_pages' => $elementPages,
            'element_per_page' => $elementPerPage,
            'element_offset' => $elementOffset,
            'naming_summary' => $namingSummary,
            'counts' => $counts,
            'roots' => $roots,
            'csrf_token' => $this->getAutomationCsrfToken($request),
            'recent_rename_runs' => $store->getRecentRenameRuns(5),
            'attention_groups' => $store->getAttentionGroups(50),
            'ignored_groups' => $store->getIgnoredGroups(50),
            'stats' => $store->getStats(),
            'tmdb_configured' => $metadataResolver->isConfigured(),
            'resolve_requested' => $resolveRequested,
            'resolve_requested_at' => $resolveRequestedAt,
            'last_resolution' => $lastResolution,
            'scan_running' => $scanState['running'],
            'scan_lock_error' => $scanState['error'],
            'scan_requested' => $scanRequested,
            'scan_requested_at' => $scanRequestedAt,
            'last_scan' => $lastScan,
            'running_scan' => $store->getRunningScan(),
            'title_refresh' => $titleRefresh->getState(),
        ]);
    }

    #[Route('/automation/library/title-refresh', name: 'automation_library_title_refresh', methods: ['POST'])]
    public function titleRefresh(Request $request, LibraryTitleRefreshService $titleRefresh): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $limit = isset($data['limit']) && is_numeric($data['limit']) ? (int) $data['limit'] : null;
        $result = $titleRefresh->startBackgroundRefresh($limit);

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/library/title-refresh/status', name: 'automation_library_title_refresh_status', methods: ['GET'])]
    public function titleRefreshStatus(LibraryTitleRefreshService $titleRefresh): Response
    {
        return $this->json(['success' => true, 'title_refresh' => $titleRefresh->getState()]);
    }

    #[Route('/automation/library/folder/contents', name: 'automation_library_folder_contents', methods: ['POST'])]
    public function folderContents(Request $request, AutomationStore $store, AutomationSettings $settings, LibraryRenameService $renameService): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $rootKey = trim((string) ($data['root_key'] ?? ''));
        $folderPath = trim((string) ($data['folder_path'] ?? ''), '/');
        $roots = $settings->getRoots();
        if ($rootKey === '' || !isset($roots[$rootKey])) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $filters = $this->normalizeFilters(is_array($data['filters'] ?? null) ? $data['filters'] : []);
        $entries = $store->getLibraryEntriesForFolderTree($filters);
        $subfolders = [];
        $directEntries = [];

        foreach ($entries as $entry) {
            if ((string) $entry['root_key'] !== $rootKey) {
                continue;
            }

            $entryFolder = trim((string) ($entry['folder_path'] ?? ''), '/');
            if ($entryFolder === $folderPath) {
                $directEntries[] = $entry;
                continue;
            }

            $prefix = $folderPath === '' ? '' : $folderPath . '/';
            if ($folderPath !== '' && !str_starts_with($entryFolder, $prefix)) {
                continue;
            }

            $remainder = $folderPath === '' ? $entryFolder : substr($entryFolder, strlen($prefix));
            if ($remainder === '') {
                $directEntries[] = $entry;
                continue;
            }

            $segments = explode('/', $remainder);
            $child = $segments[0];
            $childPath = $folderPath === '' ? $child : $folderPath . '/' . $child;
            if (!isset($subfolders[$child])) {
                $subfolders[$child] = [
                    'label' => $child,
                    'path' => $childPath,
                    'files' => 0,
                    'missing' => 0,
                    'matched' => 0,
                    'has_children' => false,
                ];
            }
            if (count($segments) > 1) {
                $subfolders[$child]['has_children'] = true;
            }
            $subfolders[$child]['files']++;
            if (($entry['status'] ?? '') === 'missing') {
                $subfolders[$child]['missing']++;
            }
            if (!empty($entry['tmdb_id']) || !empty($entry['imdb_id'])) {
                $subfolders[$child]['matched']++;
            }
        }

        usort($subfolders, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        $breadcrumb = [[
            'label' => (string) ($roots[$rootKey]['label'] ?? $rootKey),
            'root_key' => $rootKey,
            'path' => '',
        ]];
        if ($folderPath !== '') {
            $path = '';
            foreach (explode('/', $folderPath) as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                $breadcrumb[] = ['label' => $segment, 'root_key' => $rootKey, 'path' => $path];
            }
        }

        return $this->json([
            'success' => true,
            'root_key' => $rootKey,
            'folder_path' => $folderPath,
            'subfolders' => array_values($subfolders),
            'files' => $this->buildFileRows($directEntries, $renameService),
            'breadcrumb' => $breadcrumb,
        ]);
    }

    #[Route('/automation/library/folder/entries', name: 'automation_library_folder_entries', methods: ['POST'])]
    public function folderEntries(Request $request, AutomationStore $store, AutomationSettings $settings, LibraryRenameService $renameService): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $rootKey = trim((string) ($data['root_key'] ?? ''));
        $folderPath = (string) ($data['folder_path'] ?? '');
        if ($rootKey === '' || !array_key_exists($rootKey, $settings->getRoots())) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $entries = $store->getLibraryEntriesByFolder($rootKey, $folderPath);
        $rows = $this->buildFileRows($entries, $renameService);

        return $this->json(['success' => true, 'entries' => $rows]);
    }

    #[Route('/automation/library/element', name: 'automation_library_element', methods: ['POST'])]
    public function element(Request $request, AutomationStore $store, LibraryRenameService $renameService, TmdbService $tmdb): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $group = $this->normalizeGroup(is_array($data['group'] ?? null) ? $data['group'] : []);
        if ($group === null) {
            return $this->json(['success' => false, 'message' => 'Invalid discovery element.'], 400);
        }

        $tmdbId = isset($data['tmdb_id']) && $data['tmdb_id'] !== '' ? (int) $data['tmdb_id'] : null;
        $entries = $store->getLibraryEntriesForElement(
            (string) $group['media_type'],
            (string) $group['title_normalized'],
            $group['year'] !== null ? (int) $group['year'] : null,
            $tmdbId,
            $group['show_folder']
        );

        $details = null;
        if ($tmdbId !== null && $tmdbId > 0) {
            $type = $group['media_type'] === 'movie' ? 'movie' : 'series';
            $details = $tmdb->details($type, $tmdbId);
        }

        return $this->json([
            'success' => true,
            'group' => $group,
            'details' => $details,
            'files' => $this->buildFileRows($entries, $renameService),
        ]);
    }

    #[Route('/automation/library/match/search', name: 'automation_library_match_search', methods: ['POST'])]
    public function matchSearch(Request $request, LibraryMetadataResolver $metadataResolver): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $group = $this->normalizeGroup(is_array($data['group'] ?? null) ? $data['group'] : []);
        if ($group === null) {
            return $this->json(['success' => false, 'message' => 'Invalid matching group.'], 400);
        }

        $query = isset($data['query']) ? trim((string) $data['query']) : null;

        return $this->json([
            'success' => true,
            'group' => $group,
            'candidates' => $metadataResolver->searchCandidates($group, 20, $query ?: null),
        ]);
    }

    #[Route('/automation/library/match/details', name: 'automation_library_match_details', methods: ['POST'])]
    public function matchDetails(Request $request, TmdbService $tmdb): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $type = (string) ($data['type'] ?? '');
        $tmdbId = (int) ($data['tmdb_id'] ?? 0);
        if (!in_array($type, ['movie', 'series'], true) || $tmdbId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing or invalid TMDB identifier.'], 400);
        }

        $details = $tmdb->details($type, $tmdbId);
        if (!$details) {
            return $this->json(['success' => false, 'message' => 'Could not load TMDB details.'], 422);
        }

        return $this->json(['success' => true, 'details' => $details]);
    }

    #[Route('/automation/library/remove', name: 'automation_library_remove', methods: ['POST'])]
    public function removeEntry(Request $request, AutomationStore $store): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0 || !$store->deleteMissingEntry($id)) {
            return $this->json(['success' => false, 'message' => 'Missing entry not found.'], 404);
        }

        return $this->json(['success' => true, 'deleted' => 1]);
    }

    #[Route('/automation/library/purge-missing', name: 'automation_library_purge_missing', methods: ['POST'])]
    public function purgeMissing(Request $request, AutomationStore $store): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        return $this->json(['success' => true, 'deleted' => $store->purgeMissingEntries()]);
    }

    #[Route('/automation/library/rename/preview', name: 'automation_library_rename_preview', methods: ['POST'])]
    public function preview(Request $request, LibraryRenameService $renameService): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $filters = $this->normalizeFilters(is_array($data['filters'] ?? null) ? $data['filters'] : []);
        $limit = max(1, min(2000, (int) ($data['limit'] ?? 500)));
        $plan = $renameService->preview($filters, $limit);

        $items = [];
        $counts = ['ok' => 0, 'conform' => 0, 'skip' => 0, 'conflict' => 0];
        foreach ($plan['items'] as $item) {
            $entry = $item['entry'];
            $status = (string) $item['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $items[] = [
                'id' => (int) $entry['id'],
                'title' => (string) $entry['title'],
                'year' => $entry['year'] !== null ? (int) $entry['year'] : null,
                'media_type' => (string) $entry['media_type'],
                'season' => $entry['season'] !== null ? (int) $entry['season'] : null,
                'episode' => $entry['episode'] !== null ? (int) $entry['episode'] : null,
                'old_path' => (string) $entry['path'],
                'new_path' => $item['target'],
                'status' => $status,
                'reason' => $item['reason'],
                'sidecars' => count($item['sidecars'] ?? []),
            ];
        }

        return $this->json([
            'success' => true,
            'entries_seen' => $plan['entries_seen'],
            'counts' => $counts,
            'items' => $items,
        ]);
    }

    #[Route('/automation/library/rename/apply', name: 'automation_library_rename_apply', methods: ['POST'])]
    public function apply(Request $request, LibraryRenameService $renameService): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $filters = $this->normalizeFilters(is_array($data['filters'] ?? null) ? $data['filters'] : []);
        $limit = max(1, min(2000, (int) ($data['limit'] ?? 500)));
        $result = $renameService->apply($filters, $limit);

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    private function buildFolderTree(array $entries, array $roots): array
    {
        $tree = [];

        foreach ($entries as $entry) {
            $rootKey = (string) $entry['root_key'];
            $rootSettings = $roots[$rootKey] ?? [];
            if (!isset($tree[$rootKey])) {
                $tree[$rootKey] = [
                    'root_key' => $rootKey,
                    'path' => '',
                    'label' => (string) ($rootSettings['label'] ?? $rootKey),
                    'type' => (string) ($rootSettings['type'] ?? $entry['media_type']),
                    'children' => [],
                    'total_files' => 0,
                    'direct_files' => 0,
                    'missing' => 0,
                    'matched' => 0,
                    'open' => false,
                ];
            }

            $path = trim((string) ($entry['folder_path'] ?? ''), '/');
            $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
            $node = &$tree[$rootKey];
            $this->incrementFolderNode($node, $entry);
            $currentPath = '';

            foreach ($segments as $segment) {
                $currentPath = $currentPath === '' ? $segment : $currentPath . '/' . $segment;
                if (!isset($node['children'][$segment])) {
                    $node['children'][$segment] = [
                        'root_key' => $rootKey,
                        'path' => $currentPath,
                        'label' => $segment,
                        'type' => (string) ($rootSettings['type'] ?? $entry['media_type']),
                        'children' => [],
                        'total_files' => 0,
                        'direct_files' => 0,
                        'missing' => 0,
                        'matched' => 0,
                        'open' => false,
                    ];
                }
                $node = &$node['children'][$segment];
                $this->incrementFolderNode($node, $entry);
            }

            $node['direct_files']++;
            unset($node);
        }

        foreach ($tree as &$root) {
            $this->sortFolderChildren($root);
        }
        unset($root);

        uasort($tree, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $tree;
    }

    private function pruneFolderTreeForLazyLoad(array $tree): array
    {
        foreach ($tree as &$node) {
            $node['has_children'] = !empty($node['children']);
            $node['children'] = [];
            $node['open'] = false;
        }
        unset($node);

        return $tree;
    }

    private function incrementFolderNode(array &$node, array $entry): void
    {
        $node['total_files']++;
        if (($entry['status'] ?? '') === 'missing') {
            $node['missing']++;
        }
        if (!empty($entry['tmdb_id']) || !empty($entry['imdb_id'])) {
            $node['matched']++;
        }
    }

    private function sortFolderChildren(array &$node): void
    {
        uasort($node['children'], static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        foreach ($node['children'] as &$child) {
            $this->sortFolderChildren($child);
        }
        unset($child);
    }

    private function folderNodeExists(array $node, string $path): bool
    {
        $path = trim($path, '/');
        if ($path === '') {
            return true;
        }

        $current = $node;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!isset($current['children'][$segment])) {
                return false;
            }
            $current = $current['children'][$segment];
        }

        return true;
    }

    private function markOpenNodes(array &$node, ?string $selectedPath): void
    {
        $node['open'] = $node['path'] === ''
            || ($selectedPath !== null && ($selectedPath === $node['path'] || str_starts_with($selectedPath, $node['path'] . '/')));

        foreach ($node['children'] as &$child) {
            $this->markOpenNodes($child, $selectedPath);
        }
        unset($child);
    }

    private function buildFileRows(array $entries, LibraryRenameService $renameService): array
    {
        $namingMap = $this->buildNamingMap($entries, $renameService);
        $rows = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            $naming = $namingMap[$id] ?? ['status' => 'skip', 'label' => 'Skipped', 'badge' => 'badge-neutral', 'reason' => 'Not analyzed'];
            $rows[] = [
                'id' => $id,
                'filename' => (string) $entry['filename'],
                'relative_path' => (string) $entry['relative_path'],
                'title' => (string) $entry['title'],
                'year' => $entry['year'] !== null ? (int) $entry['year'] : null,
                'media_type' => (string) $entry['media_type'],
                'season' => $entry['season'] !== null ? (int) $entry['season'] : null,
                'episode' => $entry['episode'] !== null ? (int) $entry['episode'] : null,
                'tmdb_id' => $entry['tmdb_id'] !== null ? (int) $entry['tmdb_id'] : null,
                'imdb_id' => $entry['imdb_id'],
                'confidence' => (float) $entry['confidence'],
                'status' => (string) $entry['status'],
                'ignored' => !empty($entry['ignored_at']),
                'naming' => $naming,
                'match_group' => $this->buildMatchGroup($entry),
                'match_type' => $entry['media_type'] === 'movie' ? 'movie' : 'series',
                'match_id' => $entry['tmdb_id'] ?: '',
                'match_title' => (string) $entry['title'],
            ];
        }

        return $rows;
    }

    private function buildNamingMap(array $entries, LibraryRenameService $renameService): array
    {
        $namingMap = [];
        $plan = $renameService->lightPreviewEntries($entries);
        foreach ($plan['items'] as $item) {
            $entry = $item['entry'];
            $status = (string) $item['status'];
            $reason = (string) ($item['reason'] ?? '');
            $label = 'Skipped';
            $badge = 'badge-neutral';
            if ($status === 'conform') {
                $label = 'Conform';
                $badge = 'badge-success';
            } elseif ($status === 'ok') {
                $label = 'To rename';
                $badge = 'badge-info';
            } elseif ($status === 'conflict') {
                $label = 'Conflict';
                $badge = 'badge-danger';
            } elseif ($reason === 'Not matched to TMDB.') {
                $label = 'Unmatched';
                $badge = 'badge-warning';
            } elseif ($reason === 'Entry is ignored.') {
                $label = 'Ignored';
                $badge = 'badge-neutral';
            } elseif ($reason === 'Entry is not active.') {
                $label = 'Missing';
                $badge = 'badge-danger';
            } elseif ($reason === 'Library root is not writable.') {
                $label = 'Root read-only';
                $badge = 'badge-danger';
            }

            $namingMap[(int) $entry['id']] = [
                'status' => $status,
                'label' => $label,
                'badge' => $badge,
                'reason' => $reason,
                'target' => $item['target'] ?? null,
            ];
        }

        return $namingMap;
    }

    private function buildMatchGroup(array $entry): array
    {
        $mediaType = (string) ($entry['media_type'] ?? 'series');

        return [
            'group_key' => $mediaType . '|' . ($entry['title_normalized'] ?? '') . '|' . ($entry['year'] ?? 'unknown') . '|' . ($entry['show_folder'] ?? ''),
            'media_type' => $mediaType,
            'title' => (string) ($entry['title'] ?? ''),
            'title_normalized' => (string) ($entry['title_normalized'] ?? ''),
            'year' => $entry['year'] !== null ? (int) $entry['year'] : null,
            'show_folder' => $entry['show_folder'] ?? null,
            'scope' => in_array($mediaType, ['series', 'anime'], true) ? 'show_folder' : 'entry',
        ];
    }

    private function summarizeNaming(array $items): array
    {
        $summary = ['conform' => 0, 'rename' => 0, 'conflict' => 0, 'skip' => 0];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'skip');
            if ($status === 'conform') {
                $summary['conform']++;
            } elseif ($status === 'ok') {
                $summary['rename']++;
            } elseif ($status === 'conflict') {
                $summary['conflict']++;
            } else {
                $summary['skip']++;
            }
        }

        return $summary;
    }

    private function normalizeGroup(array $data): ?array
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

    private function normalizeFilters(array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        if (!in_array($status, ['all', 'matched', 'unmatched', 'ignored', 'missing'], true)) {
            $status = 'all';
        }
        $type = (string) ($filters['type'] ?? 'all');
        if (!in_array($type, ['all', 'movie', 'series', 'anime'], true)) {
            $type = 'all';
        }

        return [
            'status' => $status,
            'type' => $type,
            'q' => trim((string) ($filters['q'] ?? '')),
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
}
