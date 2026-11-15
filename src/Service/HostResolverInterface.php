<?php

namespace App\Service;

interface HostResolverInterface
{
    public function resolve(string $host): array;
}
