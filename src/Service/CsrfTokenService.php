<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class CsrfTokenService
{
    public const SESSION_KEY = 'automation_csrf_token';

    public function getToken(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(Request $request, ?string $token): bool
    {
        $expected = $request->getSession()->get(self::SESSION_KEY);

        return is_string($expected)
            && is_string($token)
            && $token !== ''
            && hash_equals($expected, $token);
    }

    public function fromRequest(Request $request): ?string
    {
        foreach (['X-CSRF-Token', 'X-Automation-Csrf'] as $header) {
            $value = $request->headers->get($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            $payload = json_decode((string) $request->getContent(), true);
            if (is_array($payload)) {
                foreach (['csrf', '_token'] as $key) {
                    if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                        return $payload[$key];
                    }
                }
            }
        }

        $value = $request->request->get('_token');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
