<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureGhaCommand extends Command
{
    use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithScopedRbac, LaraKubeOutput;

    protected $signature = 'cloud:configure:gha
        {environment? : The environment to configure}
        {--rotate : Revoke the current deploy token and mint a fresh one (use after a leak)}';

    protected $description = 'Generate the GitHub Actions deploy workflow + upload secrets for an environment';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        return $this->configureGha($this->argument('environment'), (bool) $this->option('rotate'));
    }
}
