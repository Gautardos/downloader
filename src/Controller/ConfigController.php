<?php

namespace App\Controller;

use App\Service\JsonStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
                'music_creds' => $request->request->get('music_creds', ''),
                'music_archive' => $request->request->get('music_archive', ''),
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
                'grok_renaming_prompt' => $request->request->get('grok_renaming_prompt', '')
            ]);

            $this->addFlash('success', 'Configuration saved.');
            return $this->redirectToRoute('config');
        }

        return $this->render('config/index.html.twig', [
            'config' => $currentConfig,
        ]);
    }
}
