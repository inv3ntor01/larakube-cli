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

class CloudConfigureGitlabCommand extends Command
{
    use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithScopedRbac, LaraKubeOutput;

    protected $signature = 'cloud:configure:gitlab
        {environment? : The environment to configure}
        {--rotate : Re-upload secrets (use after a credential rotation)}';

    protected $description = 'Generate the GitLab CI pipeline + upload variables for an environment';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        return $this->configureGitlab($this->argument('environment'), (bool) $this->option('rotate'));
    }
}
