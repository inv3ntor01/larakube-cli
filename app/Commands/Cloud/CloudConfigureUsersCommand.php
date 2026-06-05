<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureUsersCommand extends Command
{
    use ConfiguresCloudEnvironment, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'cloud:configure:users {environment? : The environment to sync teammates to}';

    protected $description = 'Add/sync SSH teammates for an environment\'s server';

    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        return $this->configureUsers($this->argument('environment'));
    }
}
