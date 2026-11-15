<?php

namespace App\EventSubscriber;

use App\Service\CsrfTokenService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CsrfSubscriber implements EventSubscriberInterface
{
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private CsrfTokenService $csrf)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->shouldEnforce($request)) {
            return;
        }

        $response = $this->validateRequest($request);
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function shouldEnforce(Request $request): bool
    {
        if (!in_array(strtoupper($request->getMethod()), self::STATE_CHANGING_METHODS, true)) {
            return false;
        }

        return !self::isExemptPath($request->getPathInfo());
    }

    public function validateRequest(Request $request): ?Response
    {
        if ($this->csrf->isValid($request, $this->csrf->fromRequest($request))) {
            return null;
        }

        $message = 'Invalid security token.';
        if ($this->expectsJson($request)) {
            return new JsonResponse(['success' => false, 'message' => $message], 403);
        }

        return new Response($message, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function isExemptPath(string $path): bool
    {
        return str_starts_with($path, '/jellyfin');
    }

    private function expectsJson(Request $request): bool
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json')
            || $request->isXmlHttpRequest();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }
}
