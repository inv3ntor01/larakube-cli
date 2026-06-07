<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureBaseCommand extends Command
{
    use ConfiguresCloudEnvironment, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'cloud:configure:base {environment? : The environment to configure}';

    protected $description = 'Set the deploy target (managed cluster or VPS) + web host for an environment';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        return $this->configureBase($this->argument('environment'));
    }
}
