<?php

namespace App\Controller;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\ReleasePreferenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AutomationConfigurationController extends AbstractController
{
    #[Route('/automation/config', name: 'automation_config', methods: ['GET'])]
    public function index(
        Request $request,
        AutomationSettings $settings,
        ReleasePreferenceService $preferences
    ): Response {
        $allSettings = $settings->all();
        $profile = $preferences->getProfile();

        return $this->render('automation/config.html.twig', [
            'settings' => $allSettings,
            'roots' => $settings->getRoots(),
            'scanner' => $settings->getScanner(),
            'profile' => $profile,
            'profile_previews' => $this->profilePreviews($preferences, $profile),
            'backfill' => $allSettings['backfill'] ?? [],
            'intent_settings' => $allSettings['intents'] ?? [],
            'csrf_token' => $this->getAutomationCsrfToken($request),
        ]);
    }

    #[Route('/automation/config/metadata', name: 'automation_config_metadata', methods: ['POST'])]
    public function saveMetadata(Request $request, AutomationSettings $settings): Response
    {
        $payload = $request->request->all();
        $token = $payload['_token'] ?? $request->headers->get('X-Automation-Csrf');
        if (!$this->isAutomationCsrfValid($request, is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('automation_config');
        }

        $language = trim((string) ($payload['title_language'] ?? 'en-US'));
        if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/', $language)) {
            $language = 'en-US';
        }
        $settings->save([
            'metadata' => [
                'title_language' => $language,
            ],
        ]);
        $this->addFlash('success', 'Metadata title language saved.');

        return $this->redirectToRoute('automation_config');
    }

    private function profilePreviews(ReleasePreferenceService $preferences, array $profile): array
    {
        $fixtures = [
            ['name' => '[Lumio] Movie.2024.2160p.HDR10.MULTI.x265-GROUP', 'title' => 'Movie 2024 2160p HDR10 MULTI x265', 'filename' => 'Movie.2024.2160p.HDR10.MULTI.x265-GROUP.mkv', 'size' => '18.4 GB', 'seeders' => 52, 'addon' => 'Lumio', 'priority' => 10],
            ['name' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264', 'title' => 'Movie 2024 1080p WEB-DL VOSTFR h264', 'filename' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264.mkv', 'size' => '8.2 GB', 'seeders' => 18, 'addon' => 'Torrentio', 'priority' => 20],
            ['name' => 'Movie.2024.720p.HDTV.VF.x264', 'title' => 'Movie 2024 720p HDTV VF x264', 'filename' => 'Movie.2024.720p.HDTV.VF.x264.mkv', 'size' => '1.8 GB', 'seeders' => 3, 'addon' => 'Torrentio', 'priority' => 20],
            ['name' => 'Movie.2024.CAM.x265', 'title' => 'Movie 2024 CAM x265', 'filename' => 'Movie.2024.CAM.x265.mkv', 'size' => '2.1 GB', 'seeders' => 10, 'addon' => 'Other', 'priority' => 80],
        ];

        $previews = [];
        foreach ($fixtures as $fixture) {
            $previews[] = $preferences->evaluate($fixture, $profile);
        }

        return $previews;
    }

    private function isAutomationCsrfValid(Request $request, ?string $token): bool
    {
        $expected = $request->getSession()->get('automation_csrf_token');

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    private function getAutomationCsrfToken(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get('automation_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('automation_csrf_token', $token);
        }

        return $token;
    }
}
