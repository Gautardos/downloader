<?php

namespace App\Service;

class PasswordService
{
    public function isHash(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $info = password_get_info($value);

        return (int) ($info['algo'] ?? 0) !== 0;
    }

    public function verify(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        return $this->isHash($stored)
            ? password_verify($plain, $stored)
            : hash_equals($stored, $plain);
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public function hashIfPlain(string $value): string
    {
        return $this->isHash($value) ? $value : $this->hash($value);
    }
}
