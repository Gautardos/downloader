<?php

namespace App\Controller;

use App\Service\Maintenance\MaintenanceRunner;
use App\Service\Maintenance\MaintenanceScheduler;
use App\Service\Maintenance\MaintenanceSettings;
use App\Service\Maintenance\MaintenanceStore;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MaintenanceController extends AbstractController
{
    #[Route('/settings/maintenance', name: 'maintenance', methods: ['GET'])]
    public function index(
        MaintenanceStore $store,
        MaintenanceSettings $settingsService,
        MaintenanceScheduler $scheduler,
        MaintenanceRunner $runner
    ): Response {
        $settings = $settingsService->all();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->render('maintenance/index.html.twig', [
            'settings' => $settings,
            'jobs' => $runner->describeJobs(),
            'next_run' => $scheduler->nextRunAt($settings, $now),
            'runs' => $store->getRecentRuns(20),
            'log_excerpt' => $this->readLogTail($store->getLogPath(), 40),
            'timezones' => DateTimeZone::listIdentifiers(),
            'locked' => $runner->isLocked(),
            'pause_active' => is_file($store->getPausePath()),
        ]);
    }

    #[Route('/settings/maintenance/settings', name: 'maintenance_settings', methods: ['POST'])]
    public function saveSettings(
        Request $request,
        MaintenanceSettings $settingsService
    ): Response {
        $settingsService->save($request->request->all());
        $this->addFlash('success', 'Maintenance settings saved.');

        return $this->redirectToRoute('maintenance');
    }

    #[Route('/settings/maintenance/run', name: 'maintenance_run', methods: ['POST'])]
    public function runNow(
        Request $request,
        MaintenanceRunner $runner
    ): Response {
        @set_time_limit(0);

        $job = $request->request->get('job');
        $job = is_string($job) && $job !== '' && $job !== 'all' ? $job : null;

        $result = $runner->run([
            'force' => true,
            'job' => $job,
            'trigger' => 'manual',
        ]);

        if (!empty($result['locked'])) {
            $this->addFlash('error', (string) ($result['message'] ?? 'Another maintenance run is already in progress.'));

            return $this->redirectToRoute('maintenance');
        }

        if (empty($result['success'])) {
            $message = (string) ($result['message'] ?? 'Maintenance run failed.');
            foreach ($result['runs'] ?? [] as $run) {
                if (($run['status'] ?? '') === 'error') {
                    $message .= ' ' . ($run['job_label'] ?? $run['job'] ?? 'job') . ': ' . ($run['error'] ?? 'unknown error');
                }
            }
            $this->addFlash('error', $message);

            return $this->redirectToRoute('maintenance');
        }

        if (empty($result['runs'])) {
            $this->addFlash('success', (string) ($result['message'] ?? 'No maintenance job was due.'));
        } else {
            $labels = array_map(
                static fn (array $run): string => (string) ($run['job_label'] ?? $run['job'] ?? 'job'),
                $result['runs']
            );
            $this->addFlash('success', 'Maintenance completed: ' . implode(', ', $labels) . '.');
        }

        return $this->redirectToRoute('maintenance');
    }

    #[Route('/settings/maintenance/status', name: 'maintenance_status', methods: ['GET'])]
    public function status(
        MaintenanceStore $store,
        MaintenanceSettings $settingsService,
        MaintenanceScheduler $scheduler,
        MaintenanceRunner $runner
    ): Response {
        $settings = $settingsService->all();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $next = $scheduler->nextRunAt($settings, $now);
        $jobs = $runner->describeJobs();

        return $this->json([
            'success' => true,
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'locked' => $runner->isLocked(),
            'pause_active' => is_file($store->getPausePath()),
            'next_run' => $next?->format(DATE_ATOM),
            'jobs' => $jobs,
            'last_runs' => array_map(
                static fn (array $job): array => [
                    'job' => $job['key'],
                    'enabled' => $job['enabled'],
                    'last_run' => $job['last_run'],
                ],
                $jobs
            ),
        ]);
    }

    private function readLogTail(string $path, int $lines): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return '';
        }

        $buffer = '';
        $chunk = 4096;
        fseek($handle, 0, SEEK_END);
        $size = ftell($handle);
        $position = $size;
        $lineCount = 0;

        while ($position > 0 && $lineCount <= $lines) {
            $read = min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read) . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($handle);

        $rows = preg_split('/\R/', trim($buffer));
        if (!is_array($rows)) {
            return '';
        }

        return implode("\n", array_slice($rows, -$lines));
    }
}
