<?php

namespace Tests\Feature;

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\LaraKubeOutput;

class EnvSyncHelper
{
    use GeneratesProjectInfrastructure, LaraKubeOutput;

    public function __construct(private ?ConfigData $fakeConfig = null) {}

    public function laraKubeInfo(string $message): void {}

    public function sync(string $projectPath, array $values, bool $commented = false, string $environment = 'local'): void
    {
        $this->syncEnvFile($projectPath, $values, $commented, $environment);
    }

    // Bypass disk so getCloudEnvironments()/isLocked() come from an injected config.
    protected function getProjectConfig(?string $projectPath = null): ?ConfigData
    {
        return $this->fakeConfig;
    }
}
