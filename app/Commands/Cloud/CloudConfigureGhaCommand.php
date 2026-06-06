<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureGhaCommand extends Command
{
    use ConfiguresCloudEnvironment, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

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
