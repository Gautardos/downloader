<?php

namespace App\Service\Automation;

interface SeriesIdentityResolverInterface
{
    public function resolve(array $intent): array;
}
