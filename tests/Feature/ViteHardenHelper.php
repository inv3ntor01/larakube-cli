<?php

namespace Tests\Feature;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\LaraKubeOutput;

class ViteHardenHelper
{
    use GeneratesProjectInfrastructure, LaraKubeOutput;

    public function laraKubeInfo(string $message): void {}

    public function alignEnv(string $projectPath, string $environment, ?string $webHost): void
    {
        $this->alignEnvironmentAssetUrl($projectPath, $environment, $webHost);
    }
}
