<?php

namespace App\Service\Automation;

interface ListSourceClientInterface
{
    public function provider(): string;

    public function isConfigured(array $source): bool;

    public function canTest(array $source): bool;

    /**
     * Fetch and normalize list items.
     *
     * @return array{items: array<int, array<string, mixed>>, lists: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function fetch(array $source, int $maxItems = 500): array;

    /**
     * Test the source credentials and discover the available lists.
     *
     * @return array{success: bool, message: string, lists: array<int, array<string, mixed>>}
     */
    public function test(array $source): array;
}
