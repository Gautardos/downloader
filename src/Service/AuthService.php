<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class AuthService
{
    public function __construct(
        private JsonStorage $storage,
        private RequestStack $requestStack
    ) {
    }

    public function authenticate(string $user, string $password): bool
    {
        $config = $this->storage->get('config', []);

        $adminUser = $config['admin_user'] ?? 'admin';
        $adminPass = $config['admin_password'] ?? 'admin';

        if ($user === $adminUser && $password === $adminPass) {
            $session = $this->requestStack->getSession();
            $session->set('authenticated', true);
            $session->set('username', $user);
            return true;
        }

        return false;
    }

    public function isAuthenticated(): bool
    {
        $session = $this->requestStack->getSession();
        return $session->get('authenticated', false) === true;
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('authenticated');
        $session->remove('username');
    }
}
