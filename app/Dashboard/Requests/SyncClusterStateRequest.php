<?php

namespace App\Dashboard\Requests;

use App\Dashboard\DashboardRequest;

class SyncClusterStateRequest extends DashboardRequest
{
    public function __construct(
        protected array $projects = [],
        protected array $traefik = [],
        protected array $system = [],
    ) {}

    public function getEndpoint(): string
    {
        return 'sync/cluster-state';
    }

    public function getData(): array
    {
        return [
            'projects' => $this->projects,
            'traefik' => $this->traefik,
            'system' => $this->system,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
