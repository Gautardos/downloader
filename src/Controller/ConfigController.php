<?php

namespace App\Controller;

use App\Service\AiService;
use App\Service\JsonStorage;
use App\Service\Maintenance\TorrentDatabaseUpdater;
use App\Service\PasswordService;
use App\Service\Spotify\LibrespotAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class ConfigController extends AbstractController
{
    #[Route('/config', name: 'config')]
    public function index(Request $request, JsonStorage $storage, PasswordService $passwords): Response
    {
        $currentConfig = $storage->get('config');

        if (empty($currentConfig['ai_provider'])) {
            $currentConfig['ai_provider'] = !empty($currentConfig['openrouter_api_key'])
                ? 'openrouter'
                : (!empty($currentConfig['grok_api_key']) ? 'xai' : 'openrouter');
        }

        if ($request->isMethod('POST')) {
            $clear = $request->request->all('clear');
            if (!is_array($clear)) {
                $clear = [];
            }
            $secret = static function (string $key, string $current) use ($request, $clear): string {
                if (!empty($clear[$key])) {
                    return '';
                }
                $value = $request->request->get($key);
                if (is_string($value) && $value !== '') {
                    return $value;
                }

                return $current;
            };

            $apiKey = $secret('api_key', (string) ($currentConfig['api_key'] ?? ''));
            $tmdbApiKey = $request->request->has('tmdb_api_key')
                ? $secret('tmdb_api_key', (string) ($currentConfig['tmdb_api_key'] ?? ''))
                : (string) ($currentConfig['tmdb_api_key'] ?? '');
            $openrouterKey = $secret('openrouter_api_key', (string) ($currentConfig['openrouter_api_key'] ?? ''));
            $grokKey = $secret('grok_api_key', (string) ($currentConfig['grok_api_key'] ?? ''));
            $spotifySecret = $secret('spotify_client_secret', (string) ($currentConfig['spotify_client_secret'] ?? ''));
            $geniusToken = $secret('genius_api_token', (string) ($currentConfig['genius_api_token'] ?? ''));
            $lrclibToken = $secret('lrclib_token', (string) ($currentConfig['lrclib_token'] ?? ''));

            $adminPassword = (string) ($currentConfig['admin_password'] ?? 'admin');
            $postedAdminPassword = $request->request->get('admin_password');
            if (is_string($postedAdminPassword) && $postedAdminPassword !== '') {
                $adminPassword = $passwords->hashIfPlain($postedAdminPassword);
            }

            $jellyfinPassword = (string) ($currentConfig['jellyfin_password'] ?? '');
            $postedJellyfinPassword = $request->request->get('jellyfin_password');
            if (!empty($clear['jellyfin_password'])) {
                $jellyfinPassword = '';
            } elseif (is_string($postedJellyfinPassword) && $postedJellyfinPassword !== '') {
                $jellyfinPassword = $passwords->hashIfPlain($postedJellyfinPassword);
            }

            $defaultPath = $request->request->get('default_path');
            $aiProvider = $request->request->get('ai_provider', 'openrouter');
            if (!in_array($aiProvider, ['openrouter', 'xai'], true)) {
                $aiProvider = 'openrouter';
            }

            $musicQuality = (string) $request->request->get('music_quality', 'auto');
            if (!in_array($musicQuality, ['auto', 'normal', 'high', 'very_high'], true)) {
                $musicQuality = 'auto';
            }

            $spotifyAuthMode = strtolower(trim((string) $request->request->get('spotify_auth_mode', 'auto')));
            if (!in_array($spotifyAuthMode, ['auto', 'client_credentials', 'librespot'], true)) {
                $spotifyAuthMode = 'auto';
            }

            $storage->set('config', [
                'api_key' => $apiKey,
                'tmdb_api_key' => $tmdbApiKey,
                'tmdb_language' => $request->request->get('tmdb_language', $currentConfig['tmdb_language'] ?? 'fr-FR'),
                'ai_provider' => $aiProvider,
                'openrouter_api_key' => $openrouterKey,
                'ai_model_default' => trim((string) $request->request->get('ai_model_default', '')),
                'ai_model_renaming' => trim((string) $request->request->get('ai_model_renaming', '')),
                'ai_model_music_genre' => trim((string) $request->request->get('ai_model_music_genre', '')),
                'grok_api_key' => $grokKey,
                'grok_model' => $request->request->get('grok_model', $currentConfig['grok_model'] ?? 'grok-4-fast-non-reasoning'),
                'default_path' => $defaultPath,
                'spotify_client_id' => $request->request->get('spotify_client_id', ''),
                'spotify_client_secret' => $spotifySecret,
                'spotify_auth_mode' => $spotifyAuthMode,
                'music_output' => $request->request->get('music_output', '{artist} - {album} - {song_name}.{ext}'),
                'music_format' => $request->request->get('music_format', 'mp3'),
                'music_root_path' => $request->request->get('music_root_path', ''),
                'music_creds' => $request->request->get('music_creds', '/var/www/html/var/home/.local/credentials.json'),
                'music_archive' => $request->request->get('music_archive', '/var/www/html/var/home/log/archive.txt'),
                'music_quality' => $musicQuality,
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
                'music_spotify_genres' => $request->request->get('music_spotify_genres') === '1',
                'music_genre_grok_prompt' => $request->request->get('music_genre_grok_prompt', ''),
                'music_genre_grok_model' => $request->request->get('music_genre_grok_model', $currentConfig['music_genre_grok_model'] ?? 'grok-4-fast-non-reasoning'),
                'music_genres' => $request->request->get('music_genres') === '1',
                'genius_api_token' => $geniusToken,
                'lrclib_token' => $lrclibToken,
                'admin_user' => $request->request->get('admin_user', 'admin'),
                'admin_password' => $adminPassword,
                'jellyfin_enabled' => $request->request->get('jellyfin_enabled') === '1',
                'jellyfin_username' => trim((string) $request->request->get('jellyfin_username', 'jellyfin')),
                'jellyfin_password' => $jellyfinPassword,
                'jellyfin_server_name' => trim((string) $request->request->get('jellyfin_server_name', 'Downloader')) ?: 'Downloader',
                'history_retention_limit' => (int) $request->request->get('history_retention_limit', 500),
                'grok_renaming_prompt' => $request->request->get('grok_renaming_prompt', ''),
                'allow_xxx_search' => $request->request->get('allow_xxx_search') === '1',
                'music_librespot_auth_binary' => $request->request->get('music_librespot_auth_binary', '/var/www/html/var/librespot-auth/target/release/librespot-auth'),
                'music_librespot_auth_class' => LibrespotAuthService::normalizeDeviceClass($request->request->get('music_librespot_auth_class', LibrespotAuthService::DEFAULT_DEVICE_CLASS))
            ]);

            $this->addFlash('success', 'Configuration saved.');
            return $this->redirectToRoute('config');
        }

        $credsFile = $currentConfig['music_creds'] ?? '/var/www/html/var/home/.local/credentials.json';
        $credsExists = file_exists($credsFile);

        return $this->render('config/index.html.twig', [
            'config' => $currentConfig,
            'creds_exists' => $credsExists,
            'librespot_device_classes' => LibrespotAuthService::DEVICE_CLASSES,
            'librespot_default_class' => LibrespotAuthService::DEFAULT_DEVICE_CLASS,
        ]);
    }

    #[Route('/config/ai/models', name: 'config_ai_models', methods: ['POST'])]
    public function aiModels(Request $request, AiService $ai): Response
    {
        $data = json_decode($request->getContent(), true);
        $apiKey = is_array($data) && isset($data['api_key']) && trim((string) $data['api_key']) !== ''
            ? (string) $data['api_key']
            : null;

        $result = $ai->listOpenRouterModels($apiKey);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    #[Route('/config/ai/test', name: 'config_ai_test', methods: ['POST'])]
    public function aiTest(Request $request, AiService $ai): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }

        $usage = (string) ($data['usage'] ?? 'renaming');
        if (!in_array($usage, ['default', 'renaming', 'music_genre'], true)) {
            return $this->json(['success' => false, 'error' => 'Invalid AI usage.'], 400);
        }

        $overrides = [];
        foreach (['provider', 'api_key', 'model'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'api_key' && trim((string) $data[$key]) === '') {
                continue;
            }
            $overrides[$key] = $data[$key];
        }

        $result = $ai->testUsage($usage, $overrides);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    #[Route('/config/spotify-auth', name: 'config_spotify_auth', methods: ['POST'])]
    public function generateSpotifyAuth(JsonStorage $storage, LibrespotAuthService $auth): Response
    {
        $config = $storage->get('config', []);
        $binary = $config['music_librespot_auth_binary'] ?? '/var/www/html/var/librespot-auth/target/release/librespot-auth';

        $name = 'Speaker ' . time();
        $deviceClass = LibrespotAuthService::normalizeDeviceClass($config['music_librespot_auth_class'] ?? LibrespotAuthService::DEFAULT_DEVICE_CLASS);
        $homeDir = '/var/www/html/var';
        $targetPath = '/var/www/html/var/home/.local/credentials.json';

        return $this->json($auth->generate($binary, $homeDir, $targetPath, $name, $deviceClass));
    }

    #[Route('/config/update-hash-db', name: 'config_update_hash_db', methods: ['POST'])]
    public function updateHashDb(TorrentDatabaseUpdater $updater): Response
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $updater->update(true);

            if (($result['status'] ?? 'error') !== 'success') {
                return $this->json([
                    'success' => false,
                    'message' => (string) ($result['error'] ?? 'Torrent database update failed.'),
                    'diagnostics' => $result['diagnostics'] ?? [],
                ]);
            }

            $summary = sprintf(
                "\n--- Database Update ---\nImported: %d\nIgnored (already exists): %d",
                $result['inserted'] ?? 0,
                $result['ignored'] ?? 0
            );

            return $this->json([
                'success' => true,
                'message' => 'Torrent database updated successfully!' . $summary,
                'diagnostics' => $result['diagnostics'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    #[Route('/config/export', name: 'config_export', methods: ['GET'])]
    public function exportConfig(JsonStorage $storage, KernelInterface $kernel): \Symfony\Component\HttpFoundation\BinaryFileResponse|Response
    {
        $projectDir = $kernel->getProjectDir();
        $configFile = $storage->getStorageDir() . '/config.json';
        $mediaAddonsFile = $storage->getStorageDir() . '/media_addons.json';
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

        if (file_exists($mediaAddonsFile)) {
            $zip->addFile($mediaAddonsFile, 'media_addons.json');
            $added = true;
        }

        if (file_exists($credentialsFile)) {
            $zip->addFile($credentialsFile, 'credentials.json');
            $added = true;
        }

        $zip->close();
        @chmod($zipPath, 0600);

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
                    mkdir($configDir, 0700, true);
                }
                $content = $zip->getFromName('config.json');
                if ($content !== false) {
                    file_put_contents($configDir . '/config.json', $content);
                    @chmod($configDir . '/config.json', 0600);
                    $extracted[] = 'config.json';
                }
            }

            // Extract media_addons.json
            if ($zip->locateName('media_addons.json') !== false) {
                if (!is_dir($configDir)) {
                    mkdir($configDir, 0700, true);
                }
                $content = $zip->getFromName('media_addons.json');
                if ($content !== false) {
                    file_put_contents($configDir . '/media_addons.json', $content);
                    @chmod($configDir . '/media_addons.json', 0600);
                    $extracted[] = 'media_addons.json';
                }
            }

            // Extract credentials.json
            if ($zip->locateName('credentials.json') !== false) {
                if (!is_dir($credsDir)) {
                    mkdir($credsDir, 0700, true);
                }
                $content = $zip->getFromName('credentials.json');
                if ($content !== false) {
                    file_put_contents($credsDir . '/credentials.json', $content);
                    @chmod($credsDir . '/credentials.json', 0600);
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
