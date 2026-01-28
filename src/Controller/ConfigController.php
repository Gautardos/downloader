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
                'default_path' => $defaultPath,
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
                'music_binary' => $request->request->get('music_binary', 'musicdownload')
            ]);

            $this->addFlash('success', 'Configuration saved.');
            return $this->redirectToRoute('config');
        }

        return $this->render('config/index.html.twig', [
            'config' => $currentConfig,
        ]);
    }
}
