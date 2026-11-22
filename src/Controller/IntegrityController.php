<?php

namespace App\Controller;

use App\Service\Integrity\FileIntegrityChecker;
use App\Service\Integrity\IntegrityRequeueService;
use App\Service\Integrity\IntegritySettings;
use App\Service\Integrity\VideoIntegrityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IntegrityController extends AbstractController
{
    private const FILTERS = ['all', 'incomplete', 'corrupted', 'requeued', 'healthy'];

    #[Route('/video/integrity', name: 'video_integrity', methods: ['GET'])]
    public function index(Request $request, VideoIntegrityService $integrity): Response
    {
        $filters = $this->resolveFilters($request);
        $policy = $integrity->policy();

        if ($integrity->count() === 0) {
            $integrity->scan((int) $policy['scan_limit'], false, true);
        }

        $checks = $integrity->listChecks($filters, (int) $policy['scan_limit']);

        return $this->render('integrity/index.html.twig', [
            'checks' => $checks,
            'summary' => $integrity->summary(),
            'policy' => $policy,
            'filter' => (string) $request->query->get('filter', 'all'),
            'search' => (string) $request->query->get('q', ''),
            'filters' => self::FILTERS,
        ]);
    }

    #[Route('/video/integrity/check', name: 'video_integrity_check', methods: ['POST'])]
    public function check(Request $request, VideoIntegrityService $integrity): Response
    {
        $policy = $integrity->policy();
        $limit = (int) $request->request->get('limit', $policy['scan_limit']);
        $deep = $request->request->getBoolean('deep');

        $result = $integrity->scan($limit, $deep, true);

        if ($this->wantsJson($request)) {
            return $this->json([
                'success' => true,
                'message' => 'Integrity check completed.',
                'checked' => $result['checked'],
                'summary' => $result['summary'],
                'deep' => $result['deep'],
            ]);
        }

        $this->addFlash('success', sprintf(
            'Integrity check completed: %d file(s) checked, %d incomplete, %d corrupted.',
            (int) $result['checked'],
            (int) ($result['summary']['incomplete'] ?? 0),
            (int) ($result['summary']['corrupted'] ?? 0)
        ));

        return $this->redirectToRoute('video_integrity');
    }

    #[Route('/video/integrity/requeue', name: 'video_integrity_requeue', methods: ['POST'])]
    public function requeue(Request $request, IntegrityRequeueService $requeue): Response
    {
        $limit = $request->request->get('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $dryRun = $request->request->getBoolean('dry_run');
        $all = $request->request->getBoolean('all');

        if ($all) {
            $result = $requeue->requeueCandidates($dryRun, $limit);
        } else {
            $identifiers = [];
            $single = $request->request->get('download_id');
            if (is_string($single) && trim($single) !== '') {
                $identifiers[] = trim($single);
            }
            $bulk = $request->request->all('download_ids');
            if (is_array($bulk)) {
                foreach ($bulk as $identifier) {
                    if (is_string($identifier) && trim($identifier) !== '') {
                        $identifiers[] = trim($identifier);
                    }
                }
            }

            $result = $requeue->requeueMany(array_values(array_unique($identifiers)), $dryRun, $limit);
        }

        if ($this->wantsJson($request)) {
            return $this->json([
                'success' => (int) ($result['failed'] ?? 0) === 0 && (int) ($result['succeeded'] ?? 0) > 0,
                'message' => $this->requeueMessage($result),
                'result' => $result,
            ]);
        }

        $flashType = ((int) ($result['failed'] ?? 0) > 0 && (int) ($result['succeeded'] ?? 0) === 0) ? 'error' : 'success';
        $this->addFlash($flashType, $this->requeueMessage($result));

        return $this->redirectToRoute('video_integrity');
    }

    #[Route('/video/integrity/settings', name: 'video_integrity_settings', methods: ['POST'])]
    public function saveSettings(Request $request, IntegritySettings $settings): Response
    {
        $saved = $settings->save([
            'max_requeue_attempts' => $request->request->get('max_requeue_attempts'),
            'auto_requeue' => $request->request->getBoolean('auto_requeue'),
            'deep_check' => $request->request->getBoolean('deep_check'),
            'small_file_threshold' => $request->request->get('small_file_threshold'),
            'scan_limit' => $request->request->get('scan_limit'),
            'requeue_bulk_limit' => $request->request->get('requeue_bulk_limit'),
        ]);

        if ($this->wantsJson($request)) {
            return $this->json(['success' => true, 'message' => 'Integrity policy saved.', 'policy' => $saved]);
        }

        $this->addFlash('success', 'Integrity policy saved.');

        return $this->redirectToRoute('video_integrity');
    }

    #[Route('/video/integrity/status', name: 'video_integrity_status', methods: ['GET'])]
    public function status(Request $request, VideoIntegrityService $integrity): Response
    {
        $filters = $this->resolveFilters($request);
        $policy = $integrity->policy();
        $checks = $integrity->listChecks($filters, min(500, (int) $policy['scan_limit']));

        return new JsonResponse([
            'success' => true,
            'summary' => $integrity->summary(),
            'policy' => $policy,
            'checks' => array_map(static fn (array $check): array => [
                'id' => (int) $check['id'],
                'download_id' => (string) ($check['download_id'] ?? ''),
                'filename' => (string) ($check['filename'] ?? ''),
                'path' => (string) ($check['path'] ?? ''),
                'type' => (string) ($check['type'] ?? 'video'),
                'expected_size' => (int) ($check['expected_size'] ?? 0),
                'actual_size' => (int) ($check['actual_size'] ?? 0),
                'status' => (string) ($check['status'] ?? ''),
                'reason' => (string) ($check['reason'] ?? ''),
                'attempts' => (int) ($check['attempts'] ?? 0),
                'last_checked_at' => $check['last_checked_at'] ?? null,
                'last_requeued_at' => $check['last_requeued_at'] ?? null,
                'source_available' => (bool) ($check['source_available'] ?? false),
                'source_display' => $check['source_display'] ?? null,
            ], $checks),
        ]);
    }

    private function resolveFilters(Request $request): array
    {
        $filter = (string) $request->query->get('filter', 'all');
        $filters = [
            'search' => trim((string) $request->query->get('q', '')),
        ];

        switch ($filter) {
            case 'incomplete':
                $filters['status'] = [
                    FileIntegrityChecker::STATUS_INCOMPLETE,
                    FileIntegrityChecker::STATUS_MISSING,
                    FileIntegrityChecker::STATUS_OUTSIDE_ROOT,
                    FileIntegrityChecker::STATUS_UNSUPPORTED,
                ];
                break;
            case 'corrupted':
                $filters['status'] = FileIntegrityChecker::STATUS_CORRUPTED;
                break;
            case 'requeued':
                $filters['requeued'] = true;
                break;
            case 'healthy':
                $filters['status'] = FileIntegrityChecker::STATUS_HEALTHY;
                break;
        }

        return $filters;
    }

    private function wantsJson(Request $request): bool
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        return str_contains($contentType, 'application/json')
            || str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json')
            || $request->isXmlHttpRequest()
            || $request->query->getBoolean('json')
            || $request->request->getBoolean('json');
    }

    private function requeueMessage(array $result): string
    {
        $succeeded = (int) ($result['succeeded'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $prefix = !empty($result['dry_run']) ? 'Would requeue' : 'Requeued';

        return sprintf('%s %d file(s); %d failed, %d skipped.', $prefix, $succeeded, $failed, $skipped);
    }
}
