<?php

namespace App\Service\Search;

interface SearchProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function icon(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit): array;
}
