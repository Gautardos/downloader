<?php

namespace App\Service\Maintenance;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class TorrentDatabaseUpdater
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    /**
     * @param callable(string):void|null $log
     */
    public function update(bool $withComposer = false, ?callable $log = null): array
    {
        $started = microtime(true);
        $log = $log ?? static function (string $message): void {
        };

        $diagnostics = [
            'composer' => $withComposer,
            'vendor_db_exists' => false,
            'vendor_split_exists' => false,
            'csv_exists' => false,
            'local_db_exists_before' => false,
            'local_db_exists_after' => false,
        ];

        $vendorDir = $this->projectDir . '/vendor/gautardos/hash-db';
        $vendorDbPath = $vendorDir . '/torrents.db';
        $vendorSplitPath = $vendorDir . '/torrents.zip.001';
        $csvPath = $vendorDir . '/tos_all_hash.txt';
        $localDbDir = $this->projectDir . '/var/db';
        $localDbPath = $localDbDir . '/torrents.db';

        try {
            if ($withComposer) {
                $log('Refreshing gautardos/hash-db with Composer.');
                $this->refreshComposerPackage($diagnostics, $log);
            }

            if (!is_dir($localDbDir) && !mkdir($localDbDir, 0777, true) && !is_dir($localDbDir)) {
                throw new RuntimeException('Unable to create the local database directory: ' . $localDbDir);
            }

            $diagnostics['vendor_db_exists'] = is_file($vendorDbPath);
            $diagnostics['vendor_split_exists'] = is_file($vendorSplitPath);
            $diagnostics['csv_exists'] = is_file($csvPath);
            $diagnostics['local_db_exists_before'] = is_file($localDbPath);

            if (!is_file($localDbPath)) {
                if (is_file($vendorDbPath)) {
                    $log('Copying torrents.db from the installed hash-db package.');
                    if (!copy($vendorDbPath, $localDbPath)) {
                        throw new RuntimeException('Failed to copy torrents.db into var/db.');
                    }
                } elseif (is_file($vendorSplitPath)) {
                    $log('Extracting the split torrents archive from the installed hash-db package.');
                    if (!$this->extractSplitArchive($vendorDir, $localDbDir, $diagnostics, $log)) {
                        return $this->errorResult(
                            'Failed to extract the torrent database archive. Ensure 7zip is installed in the image.',
                            $diagnostics,
                            $started,
                            $log
                        );
                    }
                } else {
                    return $this->errorResult(
                        'Torrent database source not found in vendor/gautardos/hash-db. Install the hash-db package or update the image; Composer is not run automatically by maintenance.',
                        $diagnostics,
                        $started,
                        $log
                    );
                }
            }

            if (is_file($localDbPath)) {
                @chmod($localDbPath, 0666);
            }

            if (!is_file($localDbPath)) {
                return $this->errorResult(
                    'The torrent database file is still missing in var/db after initialization.',
                    $diagnostics,
                    $started,
                    $log
                );
            }

            if (!is_file($csvPath)) {
                return $this->errorResult(
                    'Hash CSV not found: ' . $csvPath . '. The maintenance import needs tos_all_hash.txt from the installed package.',
                    $diagnostics,
                    $started,
                    $log
                );
            }

            $log('Importing torrent hashes from ' . $csvPath . '.');
            $imported = $this->importCsv($localDbPath, $csvPath, $log);

            $diagnostics['local_db_exists_after'] = is_file($localDbPath);
            $duration = microtime(true) - $started;
            $log(sprintf(
                'Torrent database update finished: %d inserted, %d ignored in %.2fs.',
                $imported['inserted'],
                $imported['ignored'],
                $duration
            ));

            return [
                'status' => 'success',
                'inserted' => $imported['inserted'],
                'ignored' => $imported['ignored'],
                'duration' => $duration,
                'diagnostics' => $diagnostics,
            ];
        } catch (\Throwable $e) {
            $duration = microtime(true) - $started;
            $log('Torrent database update failed: ' . $e->getMessage());

            return [
                'status' => 'error',
                'inserted' => 0,
                'ignored' => 0,
                'duration' => $duration,
                'error' => $e->getMessage(),
                'diagnostics' => $diagnostics,
            ];
        }
    }

    /**
     * @param callable(string):void $log
     */
    private function refreshComposerPackage(array &$diagnostics, callable $log): void
    {
        $composerHome = $this->projectDir . '/var/composer_home';
        if (!is_dir($composerHome) && !mkdir($composerHome, 0777, true) && !is_dir($composerHome)) {
            throw new RuntimeException('Unable to create COMPOSER_HOME.');
        }

        $vendorPkgDir = $this->projectDir . '/vendor/gautardos/hash-db';
        if (is_dir($vendorPkgDir)) {
            $remove = new Process(['rm', '-rf', $vendorPkgDir]);
            $remove->run();
        }

        $clear = new Process(['composer', 'clear-cache']);
        $clear->setEnv(['COMPOSER_HOME' => $composerHome]);
        $clear->setTimeout(120);
        $clear->run();

        $update = new Process([
            'composer',
            'update',
            'gautardos/hash-db',
            '--no-interaction',
            '--no-scripts',
            '--no-plugins',
            '--no-cache',
            '--prefer-dist',
        ]);
        $update->setWorkingDirectory($this->projectDir);
        $update->setEnv(['COMPOSER_HOME' => $composerHome]);
        $update->setTimeout(600);
        $update->run();

        $diagnostics['composer_output'] = $update->getOutput();
        $diagnostics['composer_error'] = $update->getErrorOutput();

        if (!$update->isSuccessful()) {
            throw new RuntimeException('Composer update failed: ' . trim($update->getErrorOutput()));
        }

        $log('Composer update completed.');
    }

    /**
     * @param callable(string):void $log
     */
    private function extractSplitArchive(
        string $vendorDir,
        string $localDbDir,
        array &$diagnostics,
        callable $log
    ): bool {
        $parts = glob($vendorDir . '/torrents.zip.00*') ?: [];
        sort($parts, SORT_STRING);
        if ($parts === []) {
            return false;
        }

        $tempArchive = $localDbDir . '/torrents_temp.7z';
        $output = @fopen($tempArchive, 'wb');
        if (!$output) {
            return false;
        }

        foreach ($parts as $part) {
            $input = @fopen($part, 'rb');
            if (!$input) {
                fclose($output);
                @unlink($tempArchive);
                return false;
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
        }
        fclose($output);

        $process = new Process(['7z', 'e', $tempArchive, '-o' . $localDbDir, '-y']);
        $process->setTimeout(1800);
        $process->run();
        @unlink($tempArchive);

        $diagnostics['extract_success'] = $process->isSuccessful();
        $diagnostics['extract_output'] = $process->getOutput();
        $diagnostics['extract_error'] = $process->getErrorOutput();

        if (!$process->isSuccessful()) {
            $log('7zip extraction failed: ' . trim($process->getErrorOutput()));
        }

        return $process->isSuccessful();
    }

    /**
     * @param callable(string):void $log
     */
    private function importCsv(string $localDbPath, string $csvPath, callable $log): array
    {
        $pdo = new PDO('sqlite:' . $localDbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 15000');

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_hash_info ON torrents(hash_info)');

        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO torrents (name, hash_info, parent_category, category) VALUES (:name, :hash_info, 2145, NULL)'
        );

        $handle = fopen($csvPath, 'rb');
        if (!$handle) {
            throw new RuntimeException('Unable to open the hash CSV: ' . $csvPath);
        }

        $inserted = 0;
        $ignored = 0;
        $lineNumber = 0;

        $pdo->beginTransaction();
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $parts = explode('###', $line);
                if (count($parts) < 2) {
                    throw new RuntimeException('Invalid CSV line ' . $lineNumber . ': expected name###hash.');
                }

                $name = trim($parts[0]);
                $hash = trim($parts[1]);
                if ($name === '' || $hash === '') {
                    throw new RuntimeException('Invalid CSV line ' . $lineNumber . ': empty name or hash.');
                }

                $stmt->execute([':name' => $name, ':hash_info' => $hash]);
                if ($stmt->rowCount() > 0) {
                    $inserted++;
                } else {
                    $ignored++;
                }

                if ($lineNumber % 500000 === 0) {
                    $log(sprintf('Imported %d lines from the hash CSV.', $lineNumber));
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        return ['inserted' => $inserted, 'ignored' => $ignored];
    }

    /**
     * @param callable(string):void $log
     */
    private function errorResult(string $message, array $diagnostics, float $started, callable $log): array
    {
        $duration = microtime(true) - $started;
        $log('Torrent database update failed: ' . $message);

        return [
            'status' => 'error',
            'inserted' => 0,
            'ignored' => 0,
            'duration' => $duration,
            'error' => $message,
            'diagnostics' => $diagnostics,
        ];
    }
}
