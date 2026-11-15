<?php

namespace App\Controller;

use App\Service\Automation\AutomationStore;
use App\Service\Automation\EpisodeNumberingService;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\ReleaseIntentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AutomationListsController extends AbstractController
{
    private const PER_PAGE = 60;

    #[Route('/automation/lists', name: 'automation_lists', methods: ['GET'])]
    public function index(Request $request, AutomationStore $store, ListSyncService $listSync, ReleaseIntentService $intents): Response
    {
        $filters = [
            'status' => (string) $request->query->get('status', 'all'),
            'source' => (string) $request->query->get('source', ''),
            'type' => (string) $request->query->get('type', 'all'),
            'q' => trim((string) $request->query->get('q', '')),
        ];
        if (!in_array($filters['status'], ['all', 'present', 'missing', 'ambiguous', 'ignored', 'pending'], true)) {
            $filters['status'] = 'all';
        }
        if (!in_array($filters['type'], ['all', 'movie', 'series'], true)) {
            $filters['type'] = 'all';
        }

        $total = $store->countListItems($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $sources = $listSync->getSources();
        $sourceLabels = [];
        foreach ($sources as &$source) {
            $sourceId = (string) $source['id'];
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $source['configured'] = $listSync->isConfigured($source);
            $source['can_test'] = $listSync->canTestSource($source);
            $source['last_run'] = $store->getLastListRun($sourceId);
            $source['counts'] = $store->getListStatusCounts($sourceId);
            $source['episode_counts'] = $store->getListEpisodeCounts($sourceId);
            $source['dry_run_validated'] = $intents->dryRunValidated($sourceId);

            $options = [];
            foreach (is_array($config['available_lists'] ?? null) ? $config['available_lists'] : [] as $list) {
                if (!is_array($list) || (string) ($list['ref'] ?? '') === '') {
                    continue;
                }
                $ref = (string) $list['ref'];
                $name = (string) ($list['name'] ?? $ref);
                $count = (int) ($list['count'] ?? 0);
                $options[$ref] = $name . ($count > 0 ? ' (' . $count . ')' : '');
            }
            foreach (is_array($config['list_ids'] ?? null) ? $config['list_ids'] : [] as $ref) {
                $ref = (string) $ref;
                if ($ref !== '' && !isset($options[$ref])) {
                    $options[$ref] = $ref;
                }
            }
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);
            $source['list_options'] = $options;
            $sourceLabels[$sourceId] = (string) $source['label'];
        }
        unset($source);

        $runState = $listSync->getRunState();

        $items = $store->getListItems($filters, self::PER_PAGE, $offset);
        foreach ($items as &$item) {
            $missing = json_decode((string) ($item['missing_json'] ?? '[]'), true);
            $missing = is_array($missing) ? array_values($missing) : [];
            $item['missing_episodes_total'] = count($missing);
            $item['missing_episodes'] = array_slice($missing, 0, 50);
            $item['numbering'] = (string) ($item['media_type'] ?? '') === 'series'
                ? $store->getSeriesNumbering(!empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null, $item['imdb_id'] ?? null)
                : null;
        }
        unset($item);

        return $this->render('automation/lists.html.twig', [
            'filters' => $filters,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'offset' => $offset,
            'counts' => $store->getListStatusCounts(),
            'sources' => $sources,
            'source_labels' => $sourceLabels,
            'recent_runs' => $store->getRecentListRuns(12),
            'running' => $runState['running'],
            'lock_error' => $runState['error'],
            'sync_requested' => (bool) $store->getSetting('lists_sync_requested', false),
            'requested_at' => $listSync->getRequestedAt(),
            'running_run' => $store->getRunningListRun(),
            'last_run' => $store->getLastListRun(),
            'episode_counts' => $store->getListEpisodeCounts(),
            'csrf_token' => $this->getAutomationCsrfToken($request),
        ]);
    }

    #[Route('/automation/lists/sync', name: 'automation_lists_sync', methods: ['POST'])]
    public function sync(Request $request, ListSyncService $listSync): Response
    {
        $data = json_decode($request->getContent(), true);
        $token = $request->headers->get('X-Automation-Csrf');
        $sourceId = null;
        if (is_array($data)) {
            $token = $data['csrf'] ?? $token;
            $sourceId = isset($data['source']) && $data['source'] !== '' ? trim((string) $data['source']) : null;
        } else {
            $token = $request->request->get('_token', $token);
            $sourceId = trim((string) $request->request->get('source', '')) ?: null;
        }

        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        if ($sourceId !== null && $listSync->getSource($sourceId) === null) {
            return $this->json(['success' => false, 'message' => 'Unknown list source.'], 400);
        }

        $result = $listSync->startBackgroundSync($sourceId ?: null);

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/lists/settings', name: 'automation_lists_settings', methods: ['POST'])]
    public function saveSettings(Request $request, ListSyncService $listSync): Response
    {
        $payload = $request->request->all();
        $token = $payload['_token'] ?? $request->headers->get('X-Automation-Csrf');
        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('automation_lists');
        }

        $existing = [];
        foreach ($listSync->getSources() as $source) {
            $existing[(string) $source['provider']] = $source;
        }

        $postedSources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
        foreach ($postedSources as $provider => $posted) {
            if (!is_array($posted) || !isset($existing[$provider])) {
                continue;
            }

            $current = $existing[$provider];
            $currentConfig = is_array($current['config'] ?? null) ? $current['config'] : [];
            $postedConfig = is_array($posted['config'] ?? null) ? $posted['config'] : [];
            $apiKey = trim((string) ($postedConfig['api_key'] ?? ''));
            if ($apiKey === '' && !empty($currentConfig['api_key'])) {
                $apiKey = (string) $currentConfig['api_key'];
            }

            $postedListIds = array_key_exists('list_ids', $postedConfig) ? $postedConfig['list_ids'] : [];
            $config = [
                'api_key' => $apiKey,
                'list_ids' => $postedListIds,
                'auto_download' => !empty($postedConfig['auto_download']),
                'auto_download_series' => !empty($postedConfig['auto_download_series']),
            ];
            if (is_array($currentConfig['available_lists'] ?? null)) {
                $config['available_lists'] = $currentConfig['available_lists'];
            }
            if ($provider === 'scrob') {
                $config['base_url'] = trim((string) ($postedConfig['base_url'] ?? ($currentConfig['base_url'] ?? '')));
                $config['api_prefix'] = trim((string) ($postedConfig['api_prefix'] ?? ($currentConfig['api_prefix'] ?? '/api/proxy')));
            }

            $existing[$provider] = ListSyncService::normalizeSource([
                'id' => $current['id'],
                'provider' => $provider,
                'label' => (string) ($posted['label'] ?? $current['label']),
                'enabled' => isset($posted['enabled']) && (string) $posted['enabled'] === '1',
                'config' => $config,
            ]);
        }

        $listSync->saveSources(array_values($existing));
        $this->addFlash('success', 'List sources saved.');

        return $this->redirectToRoute('automation_lists');
    }

    #[Route('/automation/lists/test', name: 'automation_lists_test', methods: ['POST'])]
    public function test(Request $request, ListSyncService $listSync): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $provider = strtolower(trim((string) ($data['provider'] ?? '')));
        $source = $listSync->getSource($provider);
        if ($source === null) {
            return $this->json(['success' => false, 'message' => 'Unknown list provider.', 'lists' => []], 400);
        }

        $currentConfig = is_array($source['config'] ?? null) ? $source['config'] : [];
        $postedConfig = is_array($data['config'] ?? null) ? $data['config'] : [];
        if (trim((string) ($postedConfig['api_key'] ?? '')) === '' && !empty($currentConfig['api_key'])) {
            $postedConfig['api_key'] = (string) $currentConfig['api_key'];
        }
        $config = array_merge($currentConfig, $postedConfig);
        $source = ListSyncService::normalizeSource([
            'id' => $source['id'],
            'provider' => $provider,
            'label' => $source['label'],
            'enabled' => true,
            'config' => $config,
        ]);

        $result = $listSync->testSource($source);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/lists/numbering', name: 'automation_lists_numbering', methods: ['POST'])]
    public function numbering(Request $request, AutomationStore $store, EpisodeNumberingService $numbering): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $item = $store->getListItem((int) ($data['id'] ?? 0));
        if ($item === null) {
            return $this->json(['success' => false, 'message' => 'List item not found.'], 404);
        }
        if ((string) ($item['media_type'] ?? '') !== 'series') {
            return $this->json(['success' => false, 'message' => 'Numbering is only available for series.'], 400);
        }

        $identity = $numbering->identity($item);
        $action = (string) ($data['action'] ?? 'detect');
        if ($action === 'confirm') {
            $profile = $numbering->confirm($identity);
            if ($profile === null) {
                return $this->json(['success' => false, 'message' => 'No numbering mapping to confirm.'], 422);
            }

            return $this->json(['success' => true, 'profile' => $profile, 'message' => 'Numbering mapping confirmed.']);
        }
        if ($action === 'modes') {
            $profile = $store->getSeriesNumbering(!empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null, $item['imdb_id'] ?? null);
            if (!$numbering->isUsable($profile)) {
                return $this->json(['success' => false, 'message' => 'Confirm a valid numbering before changing modes.'], 422);
            }
            $profile = $numbering->updateModes(
                $identity,
                (string) ($data['target_mode'] ?? $profile['target_mode'] ?? 'local'),
                (string) ($data['jellyfin_mode'] ?? $profile['jellyfin_mode'] ?? 'local'),
                null,
                'manual'
            );

            return $this->json(['success' => true, 'profile' => $profile]);
        }

        $profile = $numbering->profileFor($identity, true, true);
        if ($profile === null) {
            return $this->json(['success' => false, 'message' => 'Numbering detection failed.'], 422);
        }

        return $this->json([
            'success' => true,
            'profile' => $profile,
            'message' => (string) ($profile['status'] ?? '') === 'ok'
                ? 'Series numbering detected.'
                : 'Series numbering is ambiguous: ' . (string) ($profile['reason'] ?? 'manual review required'),
        ]);
    }

    #[Route('/automation/lists/queue-episodes', name: 'automation_lists_queue_episodes', methods: ['POST'])]
    public function queueEpisodes(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->queueMissingEpisodes(!empty($data['auto']));

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/lists/ignore', name: 'automation_lists_ignore', methods: ['POST'])]
    public function ignore(Request $request, AutomationStore $store): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $id = (int) ($data['id'] ?? 0);
        $item = $store->getListItem($id);
        if ($item === null) {
            return $this->json(['success' => false, 'message' => 'List item not found.'], 404);
        }

        $ignored = !empty($data['ignored']);
        $store->setListItemIgnored($id, $ignored);

        return $this->json(['success' => true, 'ignored' => $ignored]);
    }

    #[Route('/automation/lists/status', name: 'automation_lists_status', methods: ['GET'])]
    public function status(AutomationStore $store, ListSyncService $listSync): Response
    {
        $runState = $listSync->getRunState();

        return $this->json([
            'success' => true,
            'running' => $runState['running'],
            'lock_error' => $runState['error'],
            'sync_requested' => (bool) $store->getSetting('lists_sync_requested', false),
            'requested_at' => $listSync->getRequestedAt(),
            'running_run' => $store->getRunningListRun(),
            'counts' => $store->getListStatusCounts(),
            'last_run' => $store->getLastListRun(),
            'recent_runs' => $store->getRecentListRuns(5),
        ]);
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
