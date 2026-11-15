<?php

namespace App\EventSubscriber;

use App\Service\CsrfTokenService;
use App\Service\JsonStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JsonStorage $storage,
        private Environment $twig,
        private CsrfTokenService $csrf
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $this->twig->addGlobal('storage', $this->storage);

        $request = $event->getRequest();
        if ($request->hasSession() && !str_starts_with($request->getPathInfo(), '/jellyfin')) {
            $this->twig->addGlobal('csrf_token', $this->csrf->getToken($request));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
