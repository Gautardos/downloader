<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class AuthService
{
    private const IDLE_TIMEOUT = 7200;

    private JsonStorage $storage;
    private RequestStack $requestStack;
    private PasswordService $passwords;

    public function __construct(
        JsonStorage $storage,
        RequestStack $requestStack,
        ?PasswordService $passwords = null
    ) {
        $this->storage = $storage;
        $this->requestStack = $requestStack;
        $this->passwords = $passwords ?? new PasswordService();
    }

    public function authenticate(string $user, string $password): bool
    {
        $config = $this->storage->get('config', []);
        $adminUser = (string) ($config['admin_user'] ?? 'admin');
        $storedPassword = (string) ($config['admin_password'] ?? 'admin');

        if (!hash_equals($adminUser, $user) || !$this->passwords->verify($password, $storedPassword)) {
            return false;
        }

        if (!$this->passwords->isHash($storedPassword)) {
            $config['admin_password'] = $this->passwords->hash($password);
            $this->storage->set('config', $config);
        }

        $session = $this->requestStack->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $session->migrate(true);
        $session->set('authenticated', true);
        $session->set('username', $user);
        $session->set('last_activity', time());

        return true;
    }

    public function isAuthenticated(bool $touch = true): bool
    {
        $session = $this->requestStack->getSession();
        if ($session->get('authenticated', false) !== true) {
            return false;
        }

        $now = time();
        $lastActivity = (int) $session->get('last_activity', 0);
        if ($lastActivity > 0 && ($now - $lastActivity) > self::IDLE_TIMEOUT) {
            $this->logout();

            return false;
        }

        if ($touch) {
            $session->set('last_activity', $now);
        }

        return true;
    }

    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        if ($session->isStarted()) {
            $session->invalidate();
            return;
        }

        $session->remove('authenticated');
        $session->remove('username');
        $session->remove('last_activity');
    }
}
