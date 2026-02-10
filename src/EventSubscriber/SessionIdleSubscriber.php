<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionIdleSubscriber implements EventSubscriberInterface
{
    private const READ_ONLY_ROUTES = [
        'notifications_poll',
        'queue_status',
        'queue_active_log',
        'progress',
        'check_files',
        'debug_storage',
        'debug_api',
        'debug_grouping'
    ];

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (in_array($route, self::READ_ONLY_ROUTES, true)) {
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $request->getSession()->save(); // Writes and closes the session lock
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
