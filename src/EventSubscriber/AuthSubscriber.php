<?php

namespace App\EventSubscriber;

use App\Service\AuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class AuthSubscriber implements EventSubscriberInterface
{
    private const POLLING_ROUTES = [
        'notifications_poll',
        'queue_status',
        'queue_active_log',
        'progress',
    ];

    public function __construct(
        private AuthService $auth,
        private RouterInterface $router
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Jellyfin clients authenticate with their own token, not the web session.
        if (str_starts_with($request->getPathInfo(), '/jellyfin')) {
            return;
        }

        // Allow login route, Jellyfin API routes and static assets
        if ($route === 'login' || str_starts_with($route ?? '', '_') || str_starts_with((string) $route, 'jellyfin_')) {
            return;
        }

        $touchActivity = !in_array($route, self::POLLING_ROUTES, true);
        if (!$this->auth->isAuthenticated($touchActivity)) {
            $response = new RedirectResponse($this->router->generate('login'));
            $event->setResponse($response);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
