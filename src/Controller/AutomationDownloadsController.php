<?php

namespace App\Controller;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\ReleaseDryRunService;
use App\Service\Automation\ReleaseIntentService;
use App\Service\Automation\ReleaseResolverService;
use App\Service\TmdbService;
use App\Service\Automation\ReleasePreferenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AutomationDownloadsController extends AbstractController
{
    private const PER_PAGE = 40;

    #[Route('/automation/downloads', name: 'automation_downloads', methods: ['GET'])]
    public function index(
        Request $request,
        AutomationStore $store,
        AutomationSettings $settings,
        ReleaseIntentService $intents,
        ListSyncService $listSync,
        ReleaseDryRunService $dryRun
    ): Response {
        $filters = [
            'status' => (string) $request->query->get('status', 'all'),
            'source' => (string) $request->query->get('source', ''),
            'type' => (string) $request->query->get('type', 'all'),
            'season' => trim((string) $request->query->get('season', '')),
            'q' => trim((string) $request->query->get('q', '')),
        ];
        if (!in_array($filters['status'], ['all', 'pending', 'resolving', 'queued', 'downloading', 'success', 'error', 'no_source', 'ignored'], true)) {
            $filters['status'] = 'all';
        }
        if (!in_array($filters['type'], ['all', 'movie', 'series', 'anime'], true)) {
            $filters['type'] = 'all';
        }
        if ($filters['season'] !== '' && !is_numeric($filters['season'])) {
            $filters['season'] = '';
        }

        $total = $store->countIntents($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);
        $items = $store->getIntents($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $sources = $listSync->getSources();
        $sourceLabels = [];
        $sourceValidation = [];
        $autoSources = 0;
        $autoSeriesSources = 0;
        foreach ($sources as $source) {
            $sourceId = (string) $source['id'];
            $sourceLabels[$sourceId] = (string) $source['label'];
            $sourceValidation[$sourceId] = $intents->dryRunValidated($sourceId);
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            if (!empty($source['enabled']) && !empty($config['auto_download'])) {
                $autoSources++;
            }
            if (!empty($source['enabled']) && !empty($config['auto_download_series'])) {
                $autoSeriesSources++;
            }
        }

        $allSettings = $settings->all();

        return $this->render('automation/downloads.html.twig', [
            'filters' => $filters,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'offset' => max(0, ($page - 1) * self::PER_PAGE),
            'counts' => $store->getIntentStatusCounts(),
            'sources' => $sources,
            'source_labels' => $sourceLabels,
            'source_validation' => $sourceValidation,
            'validated_sources' => count(array_filter($sourceValidation)),
            'auto_sources' => $autoSources,
            'auto_series_sources' => $autoSeriesSources,
            'queue_guard' => (int) ($allSettings['backfill']['queue_guard'] ?? 20),
            'missing_series' => $store->countListItems(['status' => 'missing', 'type' => 'series']),
            'missing_series_episodes' => (int) ($store->getListEpisodeCounts()['missing'] ?? 0),
            'last_run' => $store->getLastIntentRun(),
            'running_run' => $store->getRunningIntentRun(),
            'recent_runs' => $store->getRecentIntentRuns(8),
            'queue_depth' => $intents->getQueueDepth(),
            'run_requested' => $intents->isRunRequested(),
            'worker_online' => $store->isWorkerOnline(),
            'dry_run' => $dryRun->getState(),
            'profile_hash_current' => $dryRun->getProfileHash(),
            'csrf_token' => $this->getAutomationCsrfToken($request),
        ]);
    }

    #[Route('/automation/downloads/quality', name: 'automation_downloads_quality', methods: ['POST'])]
    public function saveQuality(Request $request, AutomationSettings $settings, ReleasePreferenceService $preferences): Response
    {
        $payload = $request->request->all();
        $token = $payload['_token'] ?? $request->headers->get('X-Automation-Csrf');
        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('automation_config');
        }

        $codecs = $payload['codecs'] ?? [];
        if (is_string($codecs)) {
            $codecs = preg_split('/[\s,;]+/', $codecs) ?: [];
        }
        $profile = $preferences->normalizeProfile([
            'resolution_order' => (string) ($payload['resolution_order'] ?? '2160p,1080p,720p'),
            'max_resolution' => (string) ($payload['max_resolution'] ?? '2160p'),
            'min_resolution' => (string) ($payload['min_resolution'] ?? '720p'),
            'codecs' => $codecs,
            'hdr' => (string) ($payload['hdr'] ?? 'prefer'),
            'audio_langs' => (string) ($payload['audio_langs'] ?? 'VF,VOSTFR,MULTI'),
            'min_seeders' => (int) ($payload['min_seeders'] ?? 5),
            'max_movie_size_gb' => (float) ($payload['max_movie_size_gb'] ?? 25),
            'max_episode_size_gb' => (float) ($payload['max_episode_size_gb'] ?? 5),
            'exclude' => (string) ($payload['exclude'] ?? 'CAM,TS,3D,SAMPLE'),
            'addon_priority' => isset($payload['addon_priority']),
        ]);
        $settings->save(['quality_profile' => $profile]);
        $this->addFlash('success', 'Quality profile saved.');

        return $this->redirectToRoute('automation_config');
    }

    #[Route('/automation/downloads/settings', name: 'automation_downloads_settings', methods: ['POST'])]
    public function saveSettings(Request $request, AutomationSettings $settings): Response
    {
        $payload = $request->request->all();
        $token = $payload['_token'] ?? $request->headers->get('X-Automation-Csrf');
        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('automation_config');
        }

        $settings->save([
            'backfill' => [
                'max_intents_per_run' => max(1, min(200, (int) ($payload['max_intents_per_run'] ?? 50))),
                'queue_guard' => max(1, min(500, (int) ($payload['queue_guard'] ?? 20))),
            ],
            'intents' => [
                'retry_backoff_minutes' => max(1, min(1440, (int) ($payload['retry_backoff_minutes'] ?? 60))),
                'max_attempts' => max(1, min(20, (int) ($payload['max_attempts'] ?? 5))),
                'stale_resolving_minutes' => max(5, min(1440, (int) ($payload['stale_resolving_minutes'] ?? 30))),
                'min_interval_seconds' => max(15, min(3600, (int) ($payload['min_interval_seconds'] ?? 60))),
            ],
        ]);
        $this->addFlash('success', 'Download automation settings saved.');

        return $this->redirectToRoute('automation_config');
    }

    #[Route('/automation/downloads/queue', name: 'automation_downloads_queue', methods: ['POST'])]
    public function queue(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        $token = $request->headers->get('X-Automation-Csrf');
        $autoOnly = false;
        if (is_array($data)) {
            $token = $data['csrf'] ?? $token;
            $autoOnly = !empty($data['auto']);
        } else {
            $token = $request->request->get('_token', $token);
            $autoOnly = !empty($request->request->get('auto'));
        }

        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->queueMissingMovies($autoOnly);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/downloads/queue-episodes', name: 'automation_downloads_queue_episodes', methods: ['POST'])]
    public function queueEpisodes(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        $token = $request->headers->get('X-Automation-Csrf');
        $autoOnly = false;
        if (is_array($data)) {
            $token = $data['csrf'] ?? $token;
            $autoOnly = !empty($data['auto']);
        } else {
            $token = $request->request->get('_token', $token);
            $autoOnly = !empty($request->request->get('auto'));
        }

        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->queueMissingEpisodes($autoOnly);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/downloads/run-all', name: 'automation_downloads_run_all', methods: ['POST'])]
    public function runAll(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $queueResult = $intents->queueMissingMovies(false);
        $episodeResult = $intents->queueMissingEpisodes(false);
        $runResult = $intents->startBackgroundRun();

        return $this->json([
            'success' => !empty($runResult['success']),
            'message' => ($queueResult['message'] ?? 'Missing movies prepared.') . ' '
                . ($episodeResult['message'] ?? '') . ' ' . ($runResult['message'] ?? ''),
            'queue' => $queueResult['stats'] ?? [],
            'episodes' => $episodeResult['stats'] ?? [],
            'run' => $runResult,
        ], !empty($runResult['success']) ? 200 : 409);
    }

    #[Route('/automation/downloads/run', name: 'automation_downloads_run', methods: ['POST'])]
    public function run(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->startBackgroundRun();

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/downloads/intent/retry', name: 'automation_downloads_intent_retry', methods: ['POST'])]
    public function retry(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->retryIntent((int) ($data['id'] ?? 0));

        return $this->json($result, !empty($result['success']) ? 200 : 404);
    }

    #[Route('/automation/downloads/intent/ignore', name: 'automation_downloads_intent_ignore', methods: ['POST'])]
    public function ignore(Request $request, ReleaseIntentService $intents): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $result = $intents->ignoreIntent((int) ($data['id'] ?? 0), !empty($data['ignored']));

        return $this->json($result, !empty($result['success']) ? 200 : 404);
    }

    #[Route('/automation/downloads/intent/sources', name: 'automation_downloads_intent_sources', methods: ['POST'])]
    public function intentSources(Request $request, AutomationStore $store, ReleaseResolverService $resolver, TmdbService $tmdb, AutomationSettings $settings): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $intentId = (int) ($data['intent_id'] ?? 0);
        $intent = $store->getIntent($intentId);
        if ($intent === null) {
            return $this->json(['success' => false, 'message' => 'Intent not found.'], 404);
        }

        $preview = $resolver->previewManualSelection($intent);
        $tmdbId = !empty($preview['identity']['tmdb_id'])
            ? (int) $preview['identity']['tmdb_id']
            : (!empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null);
        $isSeries = in_array((string) ($intent['media_type'] ?? 'movie'), ['series', 'anime'], true);
        $metadataSettings = $settings->get('metadata', []);
        $titleLanguage = trim((string) ($metadataSettings['title_language'] ?? 'en-US')) ?: 'en-US';
        $details = $tmdbId !== null ? $tmdb->details($isSeries ? 'series' : 'movie', $tmdbId, $titleLanguage) : null;

        return $this->json([
            'success' => (bool) ($preview['success'] ?? false),
            'message' => $preview['message'] ?? null,
            'intent' => [
                'id' => (int) $intent['id'],
                'title' => $intent['title'],
                'year' => $intent['year'],
                'media_type' => $intent['media_type'] ?? 'movie',
                'season' => $intent['season'] ?? null,
                'episode' => $intent['episode'] ?? null,
                'release_season' => $intent['release_season'] ?? null,
                'release_episode' => $intent['release_episode'] ?? null,
                'numbering_source' => $intent['numbering_source'] ?? null,
                'status' => $intent['status'],
            ],
            'identity' => $preview['identity'] ?? [],
            'details' => $details,
            'candidates' => $preview['candidates'] ?? [],
            'streams_count' => $preview['streams_count'] ?? 0,
        ], !empty($preview['success']) ? 200 : 422);
    }

    #[Route('/automation/downloads/intent/download', name: 'automation_downloads_intent_download', methods: ['POST'])]
    public function intentDownload(Request $request, AutomationStore $store, ReleaseResolverService $resolver): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $intentId = (int) ($data['intent_id'] ?? 0);
        $intent = $store->getIntent($intentId);
        if ($intent === null) {
            return $this->json(['success' => false, 'message' => 'Intent not found.'], 404);
        }
        if (!is_array($data['candidate'] ?? null)) {
            return $this->json(['success' => false, 'message' => 'Missing selected source.'], 400);
        }

        $result = $resolver->queueManualCandidate($intent, $data['candidate']);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/automation/downloads/dry-run', name: 'automation_downloads_dry_run', methods: ['POST'])]
    public function dryRun(Request $request, ReleaseDryRunService $dryRun): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !$this->isAutomationCsrfValid($request, is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
            return $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
        }

        $limit = isset($data['limit']) && is_numeric($data['limit']) ? (int) $data['limit'] : null;
        $sourceId = isset($data['source']) && $data['source'] !== '' ? trim((string) $data['source']) : null;
        $result = $dryRun->startBackgroundRun($limit, $sourceId);

        return $this->json($result, !empty($result['success']) ? 200 : 409);
    }

    #[Route('/automation/downloads/dry-run/status', name: 'automation_downloads_dry_run_status', methods: ['GET'])]
    public function dryRunStatus(ReleaseDryRunService $dryRun): Response
    {
        return $this->json(['success' => true, 'dry_run' => $dryRun->getState()]);
    }

    #[Route('/automation/downloads/status', name: 'automation_downloads_status', methods: ['GET'])]
    public function status(AutomationStore $store, ReleaseIntentService $intents): Response
    {
        return $this->json([
            'success' => true,
            'worker_online' => $store->isWorkerOnline(),
            'counts' => $store->getIntentStatusCounts(),
            'last_run' => $store->getLastIntentRun(),
            'running_run' => $store->getRunningIntentRun(),
            'recent_runs' => $store->getRecentIntentRuns(5),
            'queue_depth' => $intents->getQueueDepth(),
            'run_requested' => $intents->isRunRequested(),
            'auto_sources' => $intents->hasAutoDownloadSources(),
            'auto_series_sources' => $intents->hasAutoDownloadSeriesSources(),
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
