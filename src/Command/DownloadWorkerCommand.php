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

        // --- Single Worker Mutex ---
        $lastHeartbeat = $storage->get('worker_heartbeat', 0);
        if ((time() - $lastHeartbeat) < 25) {
            $log('Another worker is already running (heartbeat: ' . (time() - $lastHeartbeat) . 's ago). Exiting.');
            return Command::SUCCESS;
        }

        $log('Starting Download Worker...');

        while (true) {
            // Heartbeat loop
            $storage->set('worker_heartbeat', time());

            $item = $this->queueManager->pop();

            if (!$item) {
                usleep(500000);
                $item = $this->queueManager->pop();
                if (!$item) {
                    $log('Queue empty. Worker exiting.');
                    $storage->set('worker_heartbeat', 0);
                    break;
                }
            }

            $type = $item['type'] ?? 'video';
            $filename = $item['filename'] ?? 'unknown';
            $log("Processing [$type]: " . $filename);
            $this->queueManager->setActiveTask($item);

            // Add start notification
            $this->addServerNotification($storage, 'info', "Starting $type download", 'Started', $filename, $type);

            try {
                if ($type === 'music') {
                    $this->processMusicTask($item, $storage, $log);
                } else {
                    $result = $this->downloadManager->downloadFile(
                        $item['url'],
                        $item['filename'] ?? 'undefined',
                        $item['path'],
                        $item['download_id'],
                        $item['overwrite'] ?? false,
                        fn() => $storage->set('worker_heartbeat', time()) // Heartbeat callback
                    );

                    if (!$result['success']) {
                        throw new \Exception($result['message']);
                    }

                    // Add video to history with stats
                    $this->queueManager->recordHistory($item, 'success', 1, $result);
                }

                $log("Finished [$type]: " . ($item['filename'] ?? 'unknown'));
                $this->addServerNotification($storage, 'success', "Successfully downloaded $type", 'Finished', ($item['filename'] ?? 'unknown'), $type);
            } catch (\Throwable $e) {
                $log('Error: ' . $e->getMessage());
                $log('Stack trace: ' . $e->getTraceAsString());

                // Add failed task to history
                $this->queueManager->recordHistory($item, 'error', 0, $type === 'music' ? [] : ['type' => $type]);
                $this->addServerNotification($storage, 'error', "Failed $type download", 'Failed', ($item['filename'] ?? 'unknown'), $type);
            }

            $this->queueManager->setActiveTask(null);
            usleep(100000);
        }

        return Command::SUCCESS;
    }

    private function processMusicTask(array $item, $storage, callable $log): void
    {
        $config = $storage->get('config', []);
        $downloadId = $item['download_id'];
        $url = $item['url'];

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

        // Execute using shell
        try {
            $process = \Symfony\Component\Process\Process::fromShellCommandline($cmdStr);
            $process->setTimeout(3600); // 1 hour max
            $process->setEnv([
                'HOME' => $customHome,
                'USERPROFILE' => $customHome,
                'APPDATA' => $customHome . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming',
                'PYTHONUNBUFFERED' => '1'
            ]);

            $process->run(function ($type, $buffer) use ($activeLogFile, $historyLogFile, $progressFile, $storage, &$lastLine) {
                // Write to logs
                file_put_contents($activeLogFile, $buffer, FILE_APPEND);
                file_put_contents($historyLogFile, $buffer, FILE_APPEND);

                // Update heartbeat
                $storage->set('worker_heartbeat', time());

                // Extract last line for progress
                $lines = explode("\n", trim($buffer));
                if (!empty($lines)) {
                    $lastLine = end($lines);
                    file_put_contents($progressFile, json_encode([
                        'status' => 'downloading',
                        'percentage' => 100, // We don't have real percentage, just show 100 or spinning
                        'filename' => $lastLine,
                        'speed' => 'STREAMING'
                    ]));
                }
            });
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

            file_put_contents($activeLogFile, "\n[FATAL ERROR]\n" . $errorMsg, FILE_APPEND);
            file_put_contents($historyLogFile, "\n[FATAL ERROR]\n" . $errorMsg, FILE_APPEND);
            throw new \RuntimeException("Music download failed. Check logs for details.");
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

        $expectedTracks = $item['expected_tracks'] ?? [];
        $matchedTracks = [];
        $validatedCount = 0;

        $missingTracks = [];
        $matchedTracks = [];
        foreach ($expectedTracks as $expected) {
            $found = false;
            foreach ($verifiedTracks as $verified) {
                if (
                    strtolower(trim($expected['artist'])) === strtolower(trim($verified['artist'])) &&
                    strtolower(trim($expected['album'])) === strtolower(trim($verified['album'])) &&
                    strtolower(trim($expected['song_name'])) === strtolower(trim($verified['song_name']))
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
        if (!empty($expectedTracks)) {
            if ($validatedCount === 0) {
                $finalStatus = 'error';
            } elseif ($validatedCount < count($expectedTracks)) {
                $finalStatus = 'warning';
            }
        }

        $log("Verification complete: $validatedCount/" . count($expectedTracks) . " tracks validated. Status: $finalStatus");

        $this->queueManager->recordHistory($item, $finalStatus, $validatedCount, [
            'downloaded_tracks' => $matchedTracks,
            'missing_tracks' => $missingTracks
        ]);
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
