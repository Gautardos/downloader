<?php

namespace App\Service\Automation;

interface SeriesNumberingProviderInterface
{
    public function fetch(array $identity, bool $refresh = false): array;
}
