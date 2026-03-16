<?php

namespace App\Controller;

use App\Service\JsonStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    #[Route('/config', name: 'config')]
    public function index(Request $request, JsonStorage $storage): Response
    {
        $currentConfig = $storage->get('config');

        if ($request->isMethod('POST')) {
            $apiKey = $request->request->get('api_key');
            $grokKey = $request->request->get('grok_api_key');
            $defaultPath = $request->request->get('default_path');

            $storage->set('config', [
                'api_key' => $apiKey,
                'grok_api_key' => $grokKey,
                'grok_model' => $request->request->get('grok_model', 'grok-4-fast-non-reasoning'),
                'default_path' => $defaultPath,
                'spotify_client_id' => $request->request->get('spotify_client_id', ''),
                'spotify_client_secret' => $request->request->get('spotify_client_secret', ''),
                'music_output' => $request->request->get('music_output', '{artist} - {album} - {song_name}.{ext}'),
                'music_format' => $request->request->get('music_format', 'mp3'),
                'music_root_path' => $request->request->get('music_root_path', ''),
                'music_creds' => $request->request->get('music_creds', '/var/www/html/var/home/.local/credentials.json'),
                'music_archive' => $request->request->get('music_archive', '/var/www/html/var/home/log/archive.txt'),
                'music_quality' => $request->request->get('music_quality', 'very_high'),
                'music_skip_existing' => $request->request->get('music_skip_existing') === '1',
                'music_lyrics' => $request->request->get('music_lyrics') === '1',
                'music_progress' => $request->request->get('music_progress') === '1',
                'music_print_downloads' => $request->request->get('music_print_downloads') === '1',
                'music_progress_info' => $request->request->get('music_progress_info') === '1',
                'music_retries' => (int) $request->request->get('music_retries', 3),
                'music_venv_path' => $request->request->get('music_venv_path', 'venv'),
                'music_binary' => $request->request->get('music_binary', 'zotify'),
                'music_library_path' => $request->request->get('music_library_path', ''),
                'music_genre_mapping' => $request->request->get('music_genre_mapping', ''),
                'music_genre_tagging_mode' => $request->request->get('music_genre_tagging_mode', 'ai'),
                'music_genre_grok_prompt' => $request->request->get('music_genre_grok_prompt', ''),
                'music_genre_grok_model' => $request->request->get('music_genre_grok_model', 'grok-4-fast-non-reasoning'),
                'music_genres' => $request->request->get('music_genres') === '1',
                'genius_api_token' => $request->request->get('genius_api_token', ''),
                'lrclib_token' => $request->request->get('lrclib_token', ''),
                'admin_user' => $request->request->get('admin_user', 'admin'),
                'admin_password' => $request->request->get('admin_password', 'admin'),
                'history_retention_limit' => (int) $request->request->get('history_retention_limit', 500),
                'grok_renaming_prompt' => $request->request->get('grok_renaming_prompt', ''),
                'allow_xxx_search' => $request->request->get('allow_xxx_search') === '1',
                'music_librespot_auth_binary' => $request->request->get('music_librespot_auth_binary', '/var/www/html/var/librespot-auth/target/release/librespot-auth')
            ]);

            $this->addFlash('success', 'Configuration saved.');
            return $this->redirectToRoute('config');
        }

        $credsFile = $currentConfig['music_creds'] ?? '/var/www/html/var/home/.local/credentials.json';
        $credsExists = file_exists($credsFile);

        return $this->render('config/index.html.twig', [
            'config' => $currentConfig,
            'creds_exists' => $credsExists,
        ]);
    }

    #[Route('/config/spotify-auth', name: 'config_spotify_auth', methods: ['POST'])]
    public function generateSpotifyAuth(JsonStorage $storage): Response
    {
        $config = $storage->get('config', []);
        $binary = $config['music_librespot_auth_binary'] ?? '/var/www/html/var/librespot-auth/target/release/librespot-auth';

        $timestamp = time();
        $name = "Speaker " . $timestamp;

        // Final destination
        $targetPath = '/var/www/html/var/home/.local/credentials.json';
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Execution environment
        // librespot-auth generates files in $HOME/.local/share/zotify/credentials.json
        $fakeHome = '/var/www/html/var';
        $generatedFile = $fakeHome . '/credentials.json';

        // Remove existing generated file to avoid confusion
        if (file_exists($generatedFile)) {
            unlink($generatedFile);
        }
        // Diagnostics
        $whoami = trim(shell_exec('whoami') ?: 'unknown');
        $cwd = getcwd();
        $isWritable = is_writable($fakeHome) ? 'Yes' : 'No';
        $isExecutable = is_executable($binary) ? 'Yes' : 'No';

        // Force CD into fakeHome so librespot-auth saves credentials.json there
        $cmd = sprintf('export HOME=%s && cd %s && %s --name %s 2>&1', escapeshellarg($fakeHome), escapeshellarg($fakeHome), escapeshellarg($binary), escapeshellarg($name));

        // Capture output
        $output = shell_exec($cmd);

        if (!file_exists($generatedFile)) {
            return $this->json([
                'success' => false,
                'message' => 'Credentials file not generated.',
                'debug' => [
                    'user' => $whoami,
                    'php_cwd' => $cwd,
                    'home_writable' => $isWritable,
                    'binary_executable' => $isExecutable,
                    'target_dir' => $fakeHome,
                    'binary_path' => $binary,
                    'expected_file' => $generatedFile
                ],
                'command' => $cmd,
                'output' => $output
            ]);
        }

        $content = file_get_contents($generatedFile);
        $data = json_decode($content, true);

        if (!$data) {
            return $this->json(['success' => false, 'message' => 'Failed to parse generated JSON.']);
        }

        // Transformation
        // Replace "auth_type": 1 by "type":"AUTHENTICATION_STORED_SPOTIFY_CREDENTIALS"
        // Rename "auth_data" en "credentials"
        if (isset($data['auth_type'])) {
            // We'll assume the instruction applies if it's 1 or just force it as requested
            $data['type'] = 'AUTHENTICATION_STORED_SPOTIFY_CREDENTIALS';
            unset($data['auth_type']);
        }

        if (isset($data['auth_data'])) {
            $data['credentials'] = $data['auth_data'];
            unset($data['auth_data']);
        }

        file_put_contents($targetPath, json_encode($data, JSON_PRETTY_PRINT));

        return $this->json([
            'success' => true,
            'message' => 'Spotify credentials generated and stored successfully!',
            'path' => $targetPath,
            'deviceName' => $name
        ]);
    }

    #[Route('/config/update-hash-db', name: 'config_update_hash_db', methods: ['POST'])]
    public function updateHashDb(KernelInterface $kernel): Response
    {
        try {
            $projectDir = $kernel->getProjectDir();
            $composerHome = $projectDir . '/var/composer_home';

            // Ensure composer home directory exists
            if (!is_dir($composerHome)) {
                mkdir($composerHome, 0777, true);
            }

            // Diagnostics before
            $vendorPkgDir = $projectDir . '/vendor/gautardos/hash-db';

            // Step 1: Force remove the vendor package directory if it exists
            if (is_dir($vendorPkgDir)) {
                $processRemove = Process::fromShellCommandline(
                    strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? "rmdir /s /q " . escapeshellarg($vendorPkgDir)
                    : "rm -rf " . escapeshellarg($vendorPkgDir)
                );
                $processRemove->run();
            }

            // Step 2: Clear composer cache
            $processClear = new Process(['composer', 'clear-cache']);
            $processClear->setEnv(['COMPOSER_HOME' => $composerHome]);
            $processClear->run();

            // Step 3: Run composer update
            $cmd = ['composer', 'update', 'gautardos/hash-db', '--no-interaction', '--no-scripts', '--no-plugins', '--no-cache', '--prefer-dist'];
            $process = new Process($cmd);
            $process->setWorkingDirectory($projectDir);
            $process->setEnv(['COMPOSER_HOME' => $composerHome]);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Composer update failed.',
                    'details' => $process->getErrorOutput(),
                    'output' => $process->getOutput()
                ]);
            }

            $vendorDbPath = $projectDir . '/vendor/gautardos/hash-db/torrents.db';
            $vendorSplitPath = $projectDir . '/vendor/gautardos/hash-db/torrents.zip.001';
            $localDbDir = $projectDir . '/var/db';
            $localDbPath = $localDbDir . '/torrents.db';

            if (!is_dir($localDbDir)) {
                mkdir($localDbDir, 0777, true);
            }
            @chmod($localDbDir, 0777);

            $diagnostics = [
                'vendor_db_exists' => file_exists($vendorDbPath),
                'vendor_split_exists' => file_exists($vendorSplitPath),
                'local_db_exists_before' => file_exists($localDbPath),
            ];

            // Copy or Extract DB if it doesn't exist
            if (!file_exists($localDbPath)) {
                if (file_exists($vendorDbPath)) {
                    copy($vendorDbPath, $localDbPath);
                } elseif (file_exists($vendorSplitPath)) {
                    // Handle split 7zip archive
                    $tempZip = $localDbDir . '/torrents_temp.7z';

                    $cmd = "(" .
                        "cat " . $projectDir . "/vendor/gautardos/hash-db/torrents.zip.00* > " . escapeshellarg($tempZip) .
                        " && 7z e " . escapeshellarg($tempZip) . " -o" . escapeshellarg($localDbDir) . " -y" .
                        " && rm " . escapeshellarg($tempZip) .
                        ")";

                    $processExtract = Process::fromShellCommandline($cmd);
                    $processExtract->run();

                    $diagnostics['extract_success'] = $processExtract->isSuccessful();
                    $diagnostics['extract_error'] = $processExtract->getErrorOutput();
                    $diagnostics['extract_output'] = $processExtract->getOutput();

                    if (!$processExtract->isSuccessful() && !file_exists($localDbPath)) {
                        return $this->json([
                            'success' => false,
                            'message' => 'Failed to extract database archive. Please ensure 7zip is installed (did you rebuild the docker image?).',
                            'diagnostics' => $diagnostics
                        ]);
                    }
                } else {
                    return $this->json([
                        'success' => false,
                        'message' => 'Database source not found in vendor. Did you run composer update?',
                        'diagnostics' => $diagnostics
                    ]);
                }
            }

            if (file_exists($localDbPath)) {
                @chmod($localDbPath, 0666);
            }

            // Parse CSV and insert into SQLite
            $inserted = 0;
            $ignored = 0;
            $csvPath = $projectDir . '/vendor/gautardos/hash-db/tos_all_hash.txt';

            if (file_exists($localDbPath) && file_exists($csvPath)) {
                $pdo = new \PDO('sqlite:' . $localDbPath);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Ensure unique constraint
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_hash_info ON torrents(hash_info)');

                $stmt = $pdo->prepare('INSERT OR IGNORE INTO torrents (name, hash_info, parent_category, category) VALUES (:name, :hash_info, 2145, NULL)');

                $handle = fopen($csvPath, 'r');
                if ($handle) {
                    $pdo->beginTransaction();
                    try {
                        while (($line = fgets($handle)) !== false) {
                            $parts = explode('###', trim($line));
                            if (count($parts) >= 2) {
                                $name = $parts[0];
                                $hashInfo = $parts[1];

                                $stmt->execute([
                                    ':name' => $name,
                                    ':hash_info' => $hashInfo
                                ]);

                                if ($stmt->rowCount() > 0) {
                                    $inserted++;
                                } else {
                                    $ignored++;
                                }
                            }
                        }
                        $pdo->commit();
                    } catch (\Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                    fclose($handle);
                }
            }

            if (!file_exists($localDbPath)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Initialization completed but the database file is still missing in var/db/.',
                    'diagnostics' => $diagnostics
                ]);
            }

            $summary = sprintf("\n--- Database Update ---\nImported: %d\nIgnored (already exists): %d", $inserted, $ignored);

            return $this->json([
                'success' => true,
                'message' => 'Torrent database updated successfully!' . $summary,
                'diagnostics' => $diagnostics
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    #[Route('/config/export', name: 'config_export', methods: ['GET'])]
    public function exportConfig(JsonStorage $storage, KernelInterface $kernel): \Symfony\Component\HttpFoundation\BinaryFileResponse|Response
    {
        $projectDir = $kernel->getProjectDir();
        $configFile = $storage->getStorageDir() . '/config.json';
        $credentialsFile = $projectDir . '/var/home/.local/credentials.json';

        $zipPath = sys_get_temp_dir() . '/alldebrid_config_backup_' . time() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return new Response('Could not create zip file', 500);
        }

        $added = false;
        if (file_exists($configFile)) {
            $zip->addFile($configFile, 'config.json');
            $added = true;
        }

        if (file_exists($credentialsFile)) {
            $zip->addFile($credentialsFile, 'credentials.json');
            $added = true;
        }

        $zip->close();

        if (!$added) {
            return new Response('No configuration files found to export.', 404);
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($zipPath);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'alldebridDL-config-backup.zip'
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/config/import', name: 'config_import', methods: ['POST'])]
    public function importConfig(Request $request, JsonStorage $storage, KernelInterface $kernel): Response
    {
        $file = $request->files->get('config_zip');
        if (!$file) {
            return $this->json(['success' => false, 'message' => 'No file uploaded']);
        }

        if ($file->getClientOriginalExtension() !== 'zip' && $file->guessExtension() !== 'zip') {
            return $this->json(['success' => false, 'message' => 'Invalid file type. Please upload a ZIP file.']);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getPathname()) === true) {
            $projectDir = $kernel->getProjectDir();
            $configDir = $storage->getStorageDir();
            $credsDir = $projectDir . '/var/home/.local';

            $extracted = [];

            // Extract config.json
            if ($zip->locateName('config.json') !== false) {
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0777, true);
                }
                $content = $zip->getFromName('config.json');
                if ($content !== false) {
                    file_put_contents($configDir . '/config.json', $content);
                    $extracted[] = 'config.json';
                }
            }

            // Extract credentials.json
            if ($zip->locateName('credentials.json') !== false) {
                if (!is_dir($credsDir)) {
                    mkdir($credsDir, 0777, true);
                }
                $content = $zip->getFromName('credentials.json');
                if ($content !== false) {
                    file_put_contents($credsDir . '/credentials.json', $content);
                    $extracted[] = 'credentials.json';
                }
            }

            $zip->close();

            if (empty($extracted)) {
                return $this->json(['success' => false, 'message' => 'Valid configuration files (config.json, credentials.json) not found in ZIP.']);
            }

            return $this->json(['success' => true, 'message' => 'Configuration imported successfully! Extracted: ' . implode(', ', $extracted)]);
        }

        return $this->json(['success' => false, 'message' => 'Failed to open ZIP file.']);
    }
}
