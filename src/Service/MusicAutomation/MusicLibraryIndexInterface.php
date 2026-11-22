<?php

namespace App\Service\MusicAutomation;

interface MusicLibraryIndexInterface
{
    /**
     * Returns ['ids' => string[], 'keys' => string[], 'loose' => string[], 'generated_at' => int, 'cached' => bool].
     */
    public function getIndex(bool $refresh = false): array;
}
