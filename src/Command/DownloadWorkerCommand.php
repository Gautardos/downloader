<?php

namespace App\Command;

use App\Service\DownloadManager;
use App\Service\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:download-worker',
    description: 'Processes the download queue sequentially.',
)]
class DownloadWorkerCommand extends Command
{
    private QueueManager $queueManager;
    private DownloadManager $downloadManager;

    public function __construct(QueueManager $queueManager, DownloadManager $downloadManager)
    {
        parent::__construct();
        $this->queueManager = $queueManager;
        $this->downloadManager = $downloadManager;
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

            $log('Processing: ' . ($item['filename'] ?? 'unknown'));
            $this->queueManager->setActiveTask($item);

            try {
                // The DownloadManager::downloadFile now updates its own progress JSON
                // which acts as a heartbeat for the specific download.
                // We'll also update the GLOBAL worker heartbeat periodically here if possible,
                // but for now, the UI will see the progress updating.

                $this->downloadManager->downloadFile(
                    $item['url'],
                    $item['filename'] ?? 'undefined',
                    $item['path'],
                    $item['download_id'],
                    $item['overwrite'] ?? false,
                    fn() => $storage->set('worker_heartbeat', time()) // Heartbeat callback
                );

                $log('Finished: ' . ($item['filename'] ?? 'unknown'));
            } catch (\Exception $e) {
                $log('Error: ' . $e->getMessage());
            }

            $this->queueManager->setActiveTask(null);
            usleep(100000);
        }

        return Command::SUCCESS;
    }
}
