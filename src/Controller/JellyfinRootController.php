<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class JellyfinRootController extends AbstractController
{
    #[Route('/System/Info/Public', name: 'jellyfin_root_info_public', methods: ['GET'])]
    public function infoPublic(): Response
    {
        $url = $this->generateUrl('jellyfin_info_public', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new RedirectResponse($url, 302);
    }
}
