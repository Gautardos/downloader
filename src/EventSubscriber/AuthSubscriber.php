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
    /**
     * Routes that should NOT trigger a session/auth check.
     * These are polling/API endpoints that fire every few seconds.
     * Checking auth on them causes session lock contention under Apache prefork.
     */
    private const STATELESS_ROUTES = [
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

        // Allow login route and static assets
        if ($route === 'login' || str_starts_with($route ?? '', '_')) {
            return;
        }

        // Skip auth check for high-frequency polling routes to avoid session lock storms
        if (in_array($route, self::STATELESS_ROUTES, true)) {
            return;
        }

        if (!$this->auth->isAuthenticated()) {
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
