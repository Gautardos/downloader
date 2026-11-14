<?php

namespace App\Command;

use App\Service\DownloadManager;
use App\Service\QueueManager;
use App\Service\JsonStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:download-worker',
    description: 'Processes the download queue sequentially.',
)]
class DownloadWorkerCommand extends Command
{
    private const API_RETRY_DELAY = 300;
    private const MAX_API_RETRIES = 5;

    private QueueManager $queueManager;
    private DownloadManager $downloadManager;
    private string $projectDir;

    public function __construct(QueueManager $queueManager, DownloadManager $downloadManager, string $projectDir)
    {
        parent::__construct();
        $this->queueManager = $queueManager;
        $this->downloadManager = $downloadManager;
        $this->projectDir = $projectDir;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storage = $this->queueManager->getStorage();
        $logFile = $storage->getStorageDir() . '/worker.log';
        $log = function ($msg) use ($logFile, $output) {
            $line = "[" . date('Y-m-d H:i:s') . "] $msg\n";
            file_put_contents($logFile, $line, FILE_APPEND);
            $output->writeln($msg);
        };

        $log('Checking for existing worker...');

        // --- Robust flock Mutex ---
        $lockFile = $storage->getStorageDir() . '/worker.lock';
        $lockFp = fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $log('Another worker is already running (lock acquired by other process). Exiting.');
            if ($lockFp)
                fclose($lockFp);
            return Command::SUCCESS;
        }

        $log('Starting Download Worker...');
        $this->recoverInterruptedTask($storage, $log);

        while (true) {
            // Heartbeat loop (UI visibility only)
            $storage->set('worker_heartbeat', time());

            $item = $this->queueManager->pop();

            if (!$item) {
                $this->queueManager->setActiveTask(null);
                $storage->set('worker_heartbeat', time());
                sleep(2);
                continue;
            }

            $type = $item['type'] ?? 'video';
            $filename = $item['filename'] ?? 'unknown';
            $log("Processing [$type]: " . $filename);
            $this->queueManager->setActiveTask($item);

            // Add start notification
            $this->addServerNotification($storage, 'info', "Starting $type download", 'Started', $filename, $type);

            try {
                $musicStatus = null;

                if ($type === 'music') {
                    $musicStatus = $this->processMusicTask($item, $storage, $log);

                    if ($musicStatus === 'requeued') {
                        $log("Requeued [$type]: " . ($item['filename'] ?? 'unknown') . " (retry scheduled)");
                    } elseif ($musicStatus === 'warning') {
                        $log("Finished with warnings [$type]: " . ($item['filename'] ?? 'unknown'));
                        $this->addServerNotification($storage, 'info', "Finished with missing tracks", 'Finished', ($item['filename'] ?? 'unknown'), $type);
                    } elseif ($musicStatus === 'error') {
                        $log("Finished with errors [$type]: " . ($item['filename'] ?? 'unknown'));
                        $this->addServerNotification($storage, 'error', "Failed $type download", 'Failed', ($item['filename'] ?? 'unknown'), $type);
                    } else {
                        $log("Finished [$type]: " . ($item['filename'] ?? 'unknown'));
                        $this->addServerNotification($storage, 'success', "Successfully downloaded $type", 'Finished', ($item['filename'] ?? 'unknown'), $type);
                    }
                } else {
                    $result = $this->downloadManager->downloadFile(
                        $url = $item['url'],
                        $filename = $item['filename'] ?? 'undefined',
                        $path = $item['path'],
                        $downloadId = $item['download_id'],
                        $overwrite = $item['overwrite'] ?? false,
                        $heartbeatCallback = fn() => $storage->set('worker_heartbeat', time()) // Heartbeat callback
                    );

                    if (!$result['success']) {
                        throw new \Exception($result['message']);
                    }

                    // Add video to history with stats
                    $this->queueManager->recordHistory($item, 'success', 1, $result);

                    $log("Finished [$type]: " . ($item['filename'] ?? 'unknown'));
                    $this->addServerNotification($storage, 'success', "Successfully downloaded $type", 'Finished', ($item['filename'] ?? 'unknown'), $type);
                }
            } catch (\Throwable $e) {
                $log('Error: ' . $e->getMessage());
                $log('Stack trace: ' . $e->getTraceAsString());

                // Add failed task to history
                $details = $type === 'music' ? ['missing_tracks' => $item['expected_tracks'] ?? []] : ['type' => $type];
                $details['message'] = $e->getMessage();
                $this->queueManager->recordHistory($item, 'error', 0, $details);
                $this->addServerNotification($storage, 'error', "Failed $type download", 'Failed', ($item['filename'] ?? 'unknown'), $type);
            }

            $this->queueManager->setActiveTask(null);
            usleep(100000);
        }

        // Technically unreachable due to while(true), but for completeness:
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        return Command::SUCCESS;
    }

    private function processMusicTask(array $item, $storage, callable $log): string
    {
        $config = $storage->get('config', []);
        $downloadId = $item['download_id'];
        $url = $item['url'];
        unset($item['not_before']);

        $cmdArgs = [
            '--output' => $config['music_output'] ?? '{artist} - {album} - {song_name}.{ext}',
            '--download-format' => $config['music_format'] ?? 'mp3',
            '--root-path' => $config['music_root_path'] ?? '',
            '--credentials-location' => $config['music_creds'] ?? '',
            '--song-archive' => $config['music_archive'] ?? '',
            '--download-quality' => $config['music_quality'] ?? 'very_high',
            '--retry-attempts' => $config['music_retries'] ?? 3,
            '--skip-previously-downloaded' => ($config['music_skip_existing'] ?? false) ? 'True' : 'False',
            '--download-lyrics' => ($config['music_lyrics'] ?? true) ? 'True' : 'False',
            '--print-download-progress' => ($config['music_progress'] ?? false) ? 'True' : 'False',
            '--print-downloads' => ($config['music_print_downloads'] ?? true) ? 'True' : 'False',
            '--print-progress-info' => ($config['music_progress_info'] ?? false) ? 'True' : 'False',
            '--client-id' => $config['spotify_client_id'] ?? '',
            '--client-secret' => $config['spotify_client_secret'] ?? '',
        ];

        // Build Command using absolute paths
        $venvInput = $config['music_venv_path'] ?? 'venv';
        $isAbsolute = str_starts_with($venvInput, '/') || str_starts_with($venvInput, '\\') || (isset($venvInput[1]) && $venvInput[1] === ':');

        $venvPath = $isAbsolute ? $venvInput : rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $venvInput;
        $wrapperPath = rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'music_downloader.py';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $activate = $isWindows ? "call \"$venvPath\\Scripts\\activate\"" : ". \"$venvPath/bin/activate\"";

        $cmdStr = "($activate && python3 \"$wrapperPath\" \"$url\"";
        foreach ($cmdArgs as $key => $val) {
            $cmdStr .= " $key \"$val\"";
        }
        $cmdStr .= ")"; // Redirection will be handled by Symfony Process

        // Prepare logs directory
        $logsDir = $storage->getStorageDir() . '/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }

        $activeLogFile = $logsDir . '/active_worker.log';
        $historyLogFile = $logsDir . '/history_' . $downloadId . '.log';

        // Custom home directory

        // Custom home directory to avoid permission issues (e.g. zotify trying to write to /var/www/.config)
        $customHome = rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'home';
        if (!is_dir($customHome)) {
            mkdir($customHome, 0777, true);
        }

        // Append to logs to preserve metadata logs from enqueue phase
        file_put_contents($activeLogFile, "Executing Music Downloader:\n\"$url\" ...\n" . str_repeat("-", 40) . "\n", FILE_APPEND);
        file_put_contents($historyLogFile, "Executing Full Command:\n$cmdStr\n" . str_repeat("-", 40) . "\n", FILE_APPEND);

        $progressFile = $storage->getStorageDir() . '/progress_' . $downloadId . '.json';

        $log("Executing: $cmdStr");

        $expectedTracks = $item['expected_tracks'] ?? [];
        $matchedTracks = [];
        $missingTracks = $expectedTracks;
        $validatedCount = 0;
        $processOutput = '';

        // Execute using non-blocking start to maintain heartbeat
        try {
            $process = \Symfony\Component\Process\Process::fromShellCommandline($cmdStr);
            $process->setTimeout(3600); // 1 hour max
            $process->setEnv([
                'HOME' => $customHome,
                'USERPROFILE' => $customHome,
                'APPDATA' => $customHome . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming',
                'PYTHONUNBUFFERED' => '1'
            ]);

            $process->start();

            while ($process->isRunning()) {
                // Update heartbeat every iteration
                $storage->set('worker_heartbeat', time());

                // Read incremental output
                $out = $process->getIncrementalOutput();
                $err = $process->getIncrementalErrorOutput();
                $buffer = $out . $err;

                if ($buffer !== '') {
                    file_put_contents($activeLogFile, $buffer, FILE_APPEND);
                    file_put_contents($historyLogFile, $buffer, FILE_APPEND);

                    $processOutput .= $buffer;
                    if (strlen($processOutput) > 2000000) {
                        $processOutput = substr($processOutput, -1500000);
                    }

                    // Extract last line for progress
                    $lines = explode("\n", trim($buffer));
                    if (!empty($lines)) {
                        $lastLine = end($lines);
                        file_put_contents($progressFile, json_encode([
                            'status' => 'downloading',
                            'percentage' => 100,
                            'filename' => $lastLine,
                            'speed' => 'STREAMING'
                        ]));
                    }
                }

                usleep(500000); // 0.5s
            }

            // Final check for remaining output
            $buffer = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
            if ($buffer !== '') {
                file_put_contents($activeLogFile, $buffer, FILE_APPEND);
                file_put_contents($historyLogFile, $buffer, FILE_APPEND);
                $processOutput .= $buffer;
            }

        } catch (\Throwable $e) {
            $startupError = "Failed to start process: " . $e->getMessage();
            file_put_contents($activeLogFile, "\n[STARTUP ERROR]\n" . $startupError, FILE_APPEND);
            file_put_contents($historyLogFile, "\n[STARTUP ERROR]\n" . $startupError, FILE_APPEND);
            throw $e;
        }

        if (!$process->isSuccessful()) {
            $errorMsg = "Command failed with exit code " . $process->getExitCode() . "\n";
            $errorMsg .= "ERROR OUTPUT:\n" . $process->getErrorOutput() . "\n";
            $errorMsg .= "STANDARD OUTPUT:\n" . $process->getOutput();
            $processOutput .= "\n" . $errorMsg;

            // Log fatal error but DO NOT throw if we want to retry
            file_put_contents($activeLogFile, "\n[FATAL ERROR]\n" . $errorMsg, FILE_APPEND);
            file_put_contents($historyLogFile, "\n[FATAL ERROR]\n" . $errorMsg, FILE_APPEND);

            $log("Music download script failed. Marking as error to trigger retry check.");
            $finalStatus = 'error';
            goto retry_check; // Skip verification and jump to retry logic
        }

        // Post-download Verification
        $rootPath = $config['music_root_path'] ?? '';
        $log("Verifying downloaded tracks in: $rootPath");
        $verifyCmd = "($activate && python3 \"$wrapperPath\" --verify \"$rootPath\")";
        $verifyProcess = \Symfony\Component\Process\Process::fromShellCommandline($verifyCmd);
        $verifyProcess->setEnv([
            'HOME' => $customHome,
            'USERPROFILE' => $customHome,
            'APPDATA' => $customHome . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming'
        ]);

        $verifyProcess->run();

        $verifiedTracks = [];
        if ($verifyProcess->isSuccessful()) {
            $verifiedTracks = json_decode($verifyProcess->getOutput(), true) ?: [];
        }

        $missingTracks = [];
        foreach ($expectedTracks as $expected) {
            $found = false;
            foreach ($verifiedTracks as $verified) {
                if (
                    $this->normalizeTrackPart($expected['artist'] ?? '') === $this->normalizeTrackPart($verified['artist'] ?? '') &&
                    $this->normalizeTrackPart($expected['album'] ?? '') === $this->normalizeTrackPart($verified['album'] ?? '') &&
                    $this->normalizeTrackPart($expected['song_name'] ?? '') === $this->normalizeTrackPart($verified['song_name'] ?? '')
                ) {
                    $found = true;
                    $matchedTracks[] = $verified;
                    $validatedCount++;
                    break;
                }
            }
            if (!$found) {
                $missingTracks[] = $expected;
            }
        }

        // Determine status: success (all found), warning (some found), error (none found)
        $finalStatus = 'success';
        if (empty($expectedTracks)) {
            $log("No expected tracks found (Metadata missing). Marking as error to trigger retry.");
            $finalStatus = 'error';
        } elseif ($validatedCount === 0) {
            $finalStatus = 'error';
        } elseif ($validatedCount < count($expectedTracks)) {
            $finalStatus = 'warning';
        }

        retry_check:
        $apiErrorDetected = !empty($expectedTracks) && $this->hasSpotifyApiErrors($processOutput);
        $downloadErrorDetected = !empty($expectedTracks) && $this->hasSpotifyDownloadErrors($processOutput);
        $shouldDelayRetry = ($finalStatus === 'error' && $apiErrorDetected)
            || ($finalStatus === 'warning' && $downloadErrorDetected);

        if ($shouldDelayRetry) {
            $scheduledRetryCount = (int) ($item['scheduled_retry_count'] ?? 0);

            if ($scheduledRetryCount < self::MAX_API_RETRIES) {
                $attemptNum = $scheduledRetryCount + 1;
                $log("Spotify API/audio key error detected. Scheduling retry in " . (self::API_RETRY_DELAY / 60) . " minutes (Attempt $attemptNum/" . self::MAX_API_RETRIES . ").");

                $this->queueManager->recordHistory($item, 'failed_requeued', $validatedCount, [
                    'downloaded_tracks' => $matchedTracks,
                    'missing_tracks' => $missingTracks,
                    'message' => "Spotify API limit detected. Retry scheduled in " . (self::API_RETRY_DELAY / 60) . " minutes (attempt $attemptNum/" . self::MAX_API_RETRIES . ")."
                ]);

                $item['scheduled_retry_count'] = $attemptNum;
                $item['not_before'] = time() + self::API_RETRY_DELAY;
                $item['download_id'] = $downloadId . '_api_retry_' . $attemptNum;

                $this->queueManager->enqueue($item, 'music');
                $this->addServerNotification(
                    $storage,
                    'info',
                    "Spotify API limit — retry scheduled in " . (self::API_RETRY_DELAY / 60) . " min",
                    'Requeued',
                    $item['filename'] ?? 'Music',
                    'music'
                );

                return 'requeued';
            }

            $log("Spotify API retry limit reached (" . self::MAX_API_RETRIES . " attempts).");
        }

        // Retry Logic for non-API failures
        if ($finalStatus === 'error' && !$apiErrorDetected) {
            $retryCount = $item['retry_count'] ?? 0;
            $maxRetries = 2; // Total 3 attempts (0, 1, 2)

            if ($retryCount < $maxRetries) {
                $attemptNum = $retryCount + 1;
                $log("Validation failed (0 tracks found). Retrying... (Attempt $attemptNum of " . ($maxRetries + 1) . ")");

                // Record the failure in history
                $this->queueManager->recordHistory($item, 'failed_requeued', $validatedCount, [
                    'downloaded_tracks' => $matchedTracks,
                    'missing_tracks' => $missingTracks
                ]);

                // Prepare for re-queue
                $item['retry_count'] = $attemptNum;
                // Append retry suffix to ID to create a distinct history entry next time
                $item['download_id'] = $downloadId . '_retry_' . $attemptNum;

                // Re-enqueue
                $this->queueManager->enqueue($item, 'music');
                return 'requeued';
            }
        }

        $log("Verification complete: $validatedCount/" . count($expectedTracks) . " tracks validated. Status: $finalStatus");

        $this->queueManager->recordHistory($item, $finalStatus, $validatedCount, [
            'downloaded_tracks' => $matchedTracks,
            'missing_tracks' => $missingTracks
        ]);

        return $finalStatus;
    }

    private function normalizeTrackPart(?string $value): string
    {
        $value = trim((string) $value);
        $value = str_replace([' / ', ' /', '/ ', '/'], ', ', $value);
        $value = str_replace(['; ', ';'], ', ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    private function hasSpotifyApiErrors(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $patterns = [
            '/Spotify API Error.*received an empty response/is',
            '/received an empty response/i',
            '/Audio key error/i',
            '/Failed fetching audio key/i',
            '/GENERAL DOWNLOAD ERROR/i',
            "/Couldn't extract expiration/i",
            '/Failed reading packet/i',
            '/Connection reset by peer/i',
            '/Network is unreachable/i',
            '/Connection aborted/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true;
            }
        }

        return false;
    }

    private function hasSpotifyDownloadErrors(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $patterns = [
            '/Audio key error/i',
            '/Failed fetching audio key/i',
            '/GENERAL DOWNLOAD ERROR/i',
            '/Failed reading packet/i',
            '/Connection reset by peer/i',
            '/Network is unreachable/i',
            '/Connection aborted/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true;
            }
        }

        return false;
    }

    private function recoverInterruptedTask($storage, callable $log): void
    {
        $active = $storage->get('active_worker_task', null);
        if (!is_array($active) || empty($active['url'])) {
            return;
        }

        $queue = $storage->get('server_queue', []);

        // If the task was already requeued before the crash, keep that copy only.
        foreach ($queue as $queued) {
            if (
                ($queued['url'] ?? null) === ($active['url'] ?? null)
                && ($queued['filename'] ?? null) === ($active['filename'] ?? null)
            ) {
                $storage->set('active_worker_task', null);
                return;
            }
        }

        // If it already reached a final status, it was only a stale active marker.
        $finalStatuses = ['success', 'warning', 'error', 'canceled'];
        $history = $storage->get('history', []);
        foreach (array_reverse($history) as $entry) {
            if (
                ($entry['download_id'] ?? null) === ($active['download_id'] ?? null)
                && in_array($entry['status'] ?? '', $finalStatuses, true)
            ) {
                $storage->set('active_worker_task', null);
                return;
            }
        }

        $resumeCount = (int) ($active['resume_count'] ?? 0);
        if ($resumeCount >= 3) {
            $this->queueManager->recordHistory($active, 'error', 0, [
                'message' => 'Interrupted repeatedly during worker restarts.'
            ]);
            $this->addServerNotification(
                $storage,
                'error',
                'Interrupted download abandoned',
                'Failed',
                $active['filename'] ?? 'Download',
                $active['type'] ?? 'video'
            );
            $storage->set('active_worker_task', null);
            $log('Interrupted task exceeded resume limit: ' . ($active['filename'] ?? 'unknown'));
            return;
        }

        $active['resume_count'] = $resumeCount + 1;
        unset($active['not_before']);

        if (($active['type'] ?? '') !== 'music') {
            $active['overwrite'] = true;
        }

        $queue[] = $active;
        $storage->set('server_queue', $queue);
        $storage->set('active_worker_task', null);

        $log('Requeued interrupted task: ' . ($active['filename'] ?? 'unknown'));
        $this->addServerNotification(
            $storage,
            'info',
            'Resuming interrupted download',
            'Started',
            $active['filename'] ?? 'Download',
            $active['type'] ?? 'video'
        );
    }

    private function addServerNotification($storage, string $type, string $message, string $action = '', string $item = '', string $mediaType = ''): void
    {
        $notifications = $storage->get('server_notifications', []);
        $notifications[] = [
            'type' => $type,
            'message' => $message,
            'action' => $action,
            'item' => $item,
            'media_type' => $mediaType,
            'time' => date('H:i:s')
        ];
        $storage->set('server_notifications', $notifications);
    }
}
