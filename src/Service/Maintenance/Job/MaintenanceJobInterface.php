<?php

namespace App\Service\Maintenance\Job;

interface MaintenanceJobInterface
{
    public function key(): string;

    public function label(): string;

    public function isEnabled(array $settings): bool;

    /**
     * @param callable(string):void $log
     */
    public function run(callable $log): array;
}
