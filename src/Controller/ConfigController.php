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
                'default_path' => $defaultPath
            ]);

            $this->addFlash('success', 'Configuration saved.');
            return $this->redirectToRoute('config');
        }

        return $this->render('config/index.html.twig', [
            'config' => $currentConfig,
        ]);
    }
}
