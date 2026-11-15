<?php

namespace App\EventSubscriber;

use App\Service\Jellyfin\JellyfinAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class JellyfinAuthSubscriber implements EventSubscriberInterface
{
    private const PUBLIC_ROUTES = [
        'jellyfin_info_public',
        'jellyfin_authenticate',
        'jellyfin_image',
    ];

    private JellyfinAuthService $auth;

    public function __construct(JellyfinAuthService $auth)
    {
        $this->auth = $auth;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/jellyfin')) {
            return;
        }

        if (!$this->auth->isEnabled()) {
            $event->setResponse(new JsonResponse(['error' => 'Jellyfin API is disabled.'], 404));
            return;
        }

        $route = $request->attributes->get('_route');
        if ($route === null) {
            return;
        }

        if (in_array($route, self::PUBLIC_ROUTES, true)) {
            return;
        }

        if ($this->auth->validateToken($this->auth->tokenFromRequest($request)) === null) {
            $event->setResponse(new JsonResponse(['error' => 'Unauthorized.'], 401));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }
}
