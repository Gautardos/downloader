<?php

namespace App\EventSubscriber;

use App\Service\JsonStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JsonStorage $storage,
        private Environment $twig
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $this->twig->addGlobal('storage', $this->storage);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
