<?php

namespace App\EventSubscriber;

use App\Service\CsrfTokenService;
use App\Service\JsonStorage;
use App\Service\NavigationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JsonStorage $storage,
        private Environment $twig,
        private CsrfTokenService $csrf,
        private NavigationService $navigation
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        $this->twig->addGlobal('storage', $this->storage);
        $this->twig->addGlobal('app_storage', $this->storage);

        if (!str_starts_with($request->getPathInfo(), '/jellyfin')) {
            $this->twig->addGlobal('navigation', $this->navigation->getNavigation(is_string($route) ? $route : null));

            if ($request->hasSession()) {
                $this->twig->addGlobal('csrf_token', $this->csrf->getToken($request));
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
