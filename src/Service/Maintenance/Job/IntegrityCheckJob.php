<?php

namespace App\Service\Maintenance\Job;

use App\Service\Integrity\IntegrityRequeueService;
use App\Service\Integrity\IntegritySettings;
use App\Service\Integrity\VideoIntegrityService;
use App\Service\Maintenance\MaintenanceSettings;

class IntegrityCheckJob implements MaintenanceJobInterface
{
    private VideoIntegrityService $integrity;
    private IntegrityRequeueService $requeue;
    private IntegritySettings $integritySettings;
    private MaintenanceSettings $maintenanceSettings;

    public function __construct(
        VideoIntegrityService $integrity,
        IntegrityRequeueService $requeue,
        IntegritySettings $integritySettings,
        MaintenanceSettings $maintenanceSettings
    ) {
        $this->integrity = $integrity;
        $this->requeue = $requeue;
        $this->integritySettings = $integritySettings;
        $this->maintenanceSettings = $maintenanceSettings;
    }

    public function key(): string
    {
        return 'integrity';
    }

    public function label(): string
    {
        return 'Integrity deep check';
    }

    public function isEnabled(array $settings): bool
    {
        return $this->maintenanceSettings->isJobEnabled($settings, $this->key());
    }

    public function run(callable $log): array
    {
        $policy = $this->integritySettings->all();
        $limit = (int) ($policy['scan_limit'] ?? 200);
        $deep = !empty($policy['deep_check']);

        $result = $this->integrity->scan($limit, $deep, true);
        $summary = $result['summary'] ?? [];
        $log(sprintf(
            'Integrity check finished: %d file(s) checked, %d healthy, %d incomplete, %d corrupted, %d missing.',
            (int) ($result['checked'] ?? 0),
            (int) ($summary['healthy'] ?? 0),
            (int) ($summary['incomplete'] ?? 0),
            (int) ($summary['corrupted'] ?? 0),
            (int) ($summary['missing'] ?? 0)
        ));

        $payload = [
            'status' => 'success',
            'checked' => (int) ($result['checked'] ?? 0),
            'deep' => $deep,
            'counts' => $summary,
            'requeued' => 0,
        ];

        if (!empty($policy['auto_requeue'])) {
            $requeue = $this->requeue->requeueCandidates(false, (int) ($policy['requeue_bulk_limit'] ?? 10));
            $payload['requeued'] = (int) ($requeue['succeeded'] ?? 0);
            $payload['requeue_failed'] = (int) ($requeue['failed'] ?? 0);
            $payload['requeue'] = $requeue;
            $log(sprintf(
                'Integrity auto-requeue: %d succeeded, %d failed.',
                (int) ($requeue['succeeded'] ?? 0),
                (int) ($requeue['failed'] ?? 0)
            ));
        }

        return $payload;
    }
}
