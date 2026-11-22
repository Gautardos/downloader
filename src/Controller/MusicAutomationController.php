<?php

namespace App\Controller;

use App\Service\JsonStorage;
use App\Service\MusicAutomation\MusicAutomationRunner;
use App\Service\MusicAutomation\MusicAutomationScheduler;
use App\Service\MusicAutomation\MusicAutomationStore;
use App\Service\MusicAutomation\PlaylistSearchService;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MusicAutomationController extends AbstractController
{
    #[Route('/music/automations', name: 'music_automations', methods: ['GET'])]
    public function index(
        MusicAutomationStore $store,
        MusicAutomationScheduler $scheduler,
        JsonStorage $storage
    ): Response {
        $config = $storage->get('config', []);
        $rules = $store->getRules(true);
        foreach ($rules as &$rule) {
            $rule['schedule_label'] = $scheduler->describeFrequency($rule);
        }
        unset($rule);

        return $this->render('music_automations/index.html.twig', [
            'rules' => $rules,
            'runs' => $store->getRuns(30),
            'config' => $config,
            'timezone' => $scheduler->normalizeTimezone((string) ($config['music_automation_timezone'] ?? 'UTC')),
            'spotify_ready' => $storage->hasSpotify(),
            'music_root_ready' => $storage->hasMusicPath(),
            'frequencies' => MusicAutomationScheduler::FREQUENCIES,
        ]);
    }

    #[Route('/music/automations/search', name: 'music_automations_search', methods: ['GET'])]
    public function search(Request $request, PlaylistSearchService $search): JsonResponse
    {
        $query = (string) $request->query->get('q', '');
        $scope = (string) $request->query->get('scope', 'all');
        $limit = (int) $request->query->get('limit', 20);

        return $this->json($search->search($query, $scope, $limit));
    }

    #[Route('/music/automations/save', name: 'music_automations_save', methods: ['POST'])]
    public function save(
        Request $request,
        MusicAutomationStore $store,
        MusicAutomationScheduler $scheduler,
        JsonStorage $storage
    ): JsonResponse {
        $playlistId = trim((string) $request->request->get('playlist_id', ''));
        if (!PlaylistSearchService::isValidPlaylistId($playlistId)) {
            return $this->json(['success' => false, 'message' => 'Invalid Spotify playlist ID.'], 422);
        }

        $playlistUrl = trim((string) $request->request->get('playlist_url', ''));
        if ($playlistUrl === '') {
            $playlistUrl = 'https://open.spotify.com/playlist/' . $playlistId;
        }
        if (!PlaylistSearchService::isValidPlaylistUrl($playlistUrl)) {
            return $this->json(['success' => false, 'message' => 'Only open.spotify.com playlist URLs are accepted.'], 422);
        }

        $config = $storage->get('config', []);
        $timezone = $scheduler->normalizeTimezone((string) ($config['music_automation_timezone'] ?? 'UTC'));
        $frequency = $scheduler->normalizeFrequency((string) $request->request->get('frequency', 'daily'));
        $runTime = $scheduler->normalizeRunTime((string) $request->request->get('run_time', MusicAutomationScheduler::DEFAULT_RUN_TIME));
        $weekday = $scheduler->normalizeWeekday($request->request->get('weekday', 1));
        $enabled = $request->request->getBoolean('enabled', true);
        $visibility = strtolower(trim((string) $request->request->get('visibility', 'public')));
        $visibility = in_array($visibility, ['public', 'private'], true) ? $visibility : 'public';

        $data = [
            'playlist_id' => $playlistId,
            'playlist_url' => $playlistUrl,
            'name' => mb_substr(trim((string) $request->request->get('name', 'Playlist')) ?: 'Playlist', 0, 200),
            'owner' => mb_substr(trim((string) $request->request->get('owner', '')), 0, 200),
            'visibility' => $visibility,
            'frequency' => $frequency,
            'run_time' => in_array($frequency, ['6h', '12h'], true) ? null : $runTime,
            'weekday' => $frequency === 'weekly' ? $weekday : null,
            'timezone' => $timezone,
            'enabled' => $enabled,
        ];

        $ruleId = (int) $request->request->get('rule_id', 0);
        $now = $scheduler->computeNextRun(array_merge($data, [
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'last_run_at' => null,
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $data['next_run_at'] = $now;

        try {
            if ($ruleId > 0) {
                $existing = $store->getRule($ruleId);
                if ($existing === null) {
                    return $this->json(['success' => false, 'message' => 'Rule not found.'], 404);
                }
                $data['next_run_at'] = $scheduler->computeNextRun(array_merge($existing, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $store->updateRule($ruleId, $data);
            } else {
                $ruleId = $store->createRule($data);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return $this->json([
            'success' => true,
            'message' => 'Music automation rule saved.',
            'rule' => $store->getRule($ruleId),
        ]);
    }

    #[Route('/music/automations/delete', name: 'music_automations_delete', methods: ['POST'])]
    public function delete(Request $request, MusicAutomationStore $store): JsonResponse
    {
        $ruleId = (int) $request->request->get('rule_id', 0);
        if ($ruleId <= 0 || !$store->deleteRule($ruleId)) {
            return $this->json(['success' => false, 'message' => 'Rule not found.'], 404);
        }

        return $this->json(['success' => true, 'message' => 'Rule deleted.']);
    }

    #[Route('/music/automations/run', name: 'music_automations_run', methods: ['POST'])]
    public function run(
        Request $request,
        MusicAutomationStore $store,
        MusicAutomationRunner $runner
    ): JsonResponse {
        $ruleId = (int) $request->request->get('rule_id', 0);
        $rule = $ruleId > 0 ? $store->getRule($ruleId) : null;
        if ($rule === null) {
            return $this->json(['success' => false, 'message' => 'Rule not found.'], 404);
        }

        $mode = strtolower(trim((string) $request->request->get('mode', 'dry')));
        $dryRun = $mode !== 'enqueue';
        $result = $runner->runRule($rule, $dryRun, true, 'manual');

        return $this->json($result, ($result['status'] ?? '') === 'error' ? 422 : 200);
    }

    #[Route('/music/automations/status', name: 'music_automations_status', methods: ['GET'])]
    public function status(MusicAutomationStore $store): JsonResponse
    {
        $heartbeat = (int) @file_get_contents($store->getHeartbeatPath());

        return $this->json([
            'success' => true,
            'worker' => [
                'heartbeat' => $heartbeat,
                'age' => $heartbeat > 0 ? max(0, time() - $heartbeat) : null,
            ],
            'rules' => $store->getRules(true),
            'runs' => $store->getRuns(30),
        ]);
    }
}
