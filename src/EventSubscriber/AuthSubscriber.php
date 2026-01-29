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

        // Allow login route and static assets (if any)
        if ($route === 'login' || str_starts_with($route, '_')) {
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
