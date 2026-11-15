<?php

namespace App\Service\Automation;

interface MovieIdentityResolverInterface
{
    public function resolve(array $intent): array;
}
