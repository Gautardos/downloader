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

            // Check if project root is writable (needed to update composer.lock)
            if (!is_writable($projectDir) || (file_exists($projectDir . '/composer.lock') && !is_writable($projectDir . '/composer.lock'))) {
                return $this->json([
                    'success' => false,
                    'message' => 'Project directory or composer.lock is not writable by the web server. Please fix permissions (e.g., chmod 777 composer.lock) to allow updates from the UI.'
                ]);
            }

            // Run composer update for the hash-db package
            // We use --no-interaction to avoid hanging
            $cmd = ['composer', 'update', 'gautardos/hash-db', '--no-interaction', '--no-scripts', '--no-plugins'];

            $process = new Process($cmd);
            $process->setWorkingDirectory($projectDir);
            $process->setEnv([
                'COMPOSER_HOME' => $composerHome,
                'HOME' => $composerHome
            ]);
            $process->setTimeout(600); // 10 minutes max for DB update
            $process->run();

            if (!$process->isSuccessful()) {
                return $this->json([
                    'success' => false,
                    'message' => 'Composer update failed.',
                    'details' => $process->getErrorOutput(),
                    'output' => $process->getOutput()
                ]);
            }

            return $this->json([
                'success' => true,
                'message' => 'Torrent database updated successfully!',
                'output' => $process->getOutput()
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
