<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;
use App\Service\ServerNotificationService;

class AutomationNotifier
{
    private JsonStorage $storage;
    private ServerNotificationService $notifications;

    public function __construct(JsonStorage $storage, ?ServerNotificationService $notifications = null)
    {
        $this->storage = $storage;
        $this->notifications = $notifications ?? new ServerNotificationService();
    }

    public function notify(string $type, string $message, string $action = '', string $item = '', string $mediaType = ''): void
    {
        $this->notifications->push($this->storage, [
            'type' => $type,
            'message' => $message,
            'action' => $action,
            'item' => $item,
            'media_type' => $mediaType,
            'time' => date('H:i:s'),
        ]);
    }

    public function queueDownloadSummary(string $mediaType, string $status, string $label, ?string $error = null): void
    {
        $summary = $this->storage->get('automation_download_summary', []);
        if (!is_array($summary) || $summary === []) {
            $summary = [
                'started_at' => time(),
                'last_event_at' => time(),
                'success' => 0,
                'error' => 0,
                'series' => 0,
                'movie' => 0,
                'samples' => [],
                'errors' => [],
            ];
        }

        $summary['last_event_at'] = time();
        if ($status === 'success') {
            $summary['success'] = (int) ($summary['success'] ?? 0) + 1;
        } else {
            $summary['error'] = (int) ($summary['error'] ?? 0) + 1;
        }
        if (in_array($mediaType, ['series', 'anime'], true)) {
            $summary['series'] = (int) ($summary['series'] ?? 0) + 1;
        } else {
            $summary['movie'] = (int) ($summary['movie'] ?? 0) + 1;
        }
        if ($label !== '' && count($summary['samples'] ?? []) < 3) {
            $summary['samples'][] = $label;
        }
        if ($error !== null && $error !== '' && count($summary['errors'] ?? []) < 3) {
            $summary['errors'][] = $error;
        }

        $this->storage->set('automation_download_summary', $summary);
    }

    public function flushDownloadSummary(bool $force = false): bool
    {
        $summary = $this->storage->get('automation_download_summary', []);
        if (!is_array($summary) || $summary === []) {
            return false;
        }

        $lastEvent = (int) ($summary['last_event_at'] ?? 0);
        if (!$force && $lastEvent > 0 && (time() - $lastEvent) < 10) {
            return false;
        }

        $success = (int) ($summary['success'] ?? 0);
        $errors = (int) ($summary['error'] ?? 0);
        $series = (int) ($summary['series'] ?? 0);
        $movies = (int) ($summary['movie'] ?? 0);
        if ($success === 0 && $errors === 0) {
            $this->storage->set('automation_download_summary', []);

            return false;
        }

        $parts = [];
        if ($success > 0) {
            $parts[] = $success . ' success';
        }
        if ($errors > 0) {
            $parts[] = $errors . ' error';
        }
        $message = 'Downloads: ' . implode(', ', $parts) . '.';
        if ($series > 0) {
            $message .= ' Series: ' . $series . '.';
        }
        if ($movies > 0) {
            $message .= ' Movies: ' . $movies . '.';
        }
        $errorSamples = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
        if ($errors > 0 && $errorSamples !== []) {
            $message .= ' Last error: ' . (string) $errorSamples[0];
        }

        $type = $errors > 0 ? ($success > 0 ? 'warning' : 'error') : 'success';
        $this->notify($type, $message, 'Downloads', '', '');
        $this->storage->set('automation_download_summary', []);

        return true;
    }

    public function notifyIntentRun(array $stats, ?string $error = null): void
    {
        $queued = (int) ($stats['intents_queued'] ?? 0);
        $noSource = (int) ($stats['intents_no_source'] ?? 0);
        $failed = (int) ($stats['intents_error'] ?? 0);

        if ($queued === 0 && $noSource === 0 && $failed === 0) {
            return;
        }

        $parts = [];
        if ($queued > 0) {
            $parts[] = $queued . ' queued';
        }
        if ($noSource > 0) {
            $parts[] = $noSource . ' without a valid source';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' failed';
        }

        $type = $failed > 0 ? 'error' : ($noSource > 0 ? 'warning' : 'success');
        $this->notify($type, 'Automation: ' . implode(', ', $parts) . '.', 'Automation', $error ?? '', 'movie');
    }

    public function notifyProblem(string $message, string $item = '', string $type = 'error', string $mediaType = 'movie'): void
    {
        $this->notify($type, $message, 'Automation', $item, $mediaType);
    }
}
