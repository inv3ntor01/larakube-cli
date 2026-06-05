<?php

namespace App\Dashboard\Data;

use Spatie\LaravelData\Data;

class ProjectSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status, // UP, DOWN, SYNCING, ERROR
        public string $namespace,
        public string $url,
        public array $features = [], // mysql, redis, etc.
        public ?string $cpuUsage = null,
        public ?string $memoryUsage = null,
    ) {}
}
