<?php

namespace App\Commands\Cloud;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CloudConfigureCommand extends Command
{
    use ConfiguresCloudEnvironment, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command. The bare command runs the
     * full guided setup; the individual steps live in the discoverable
     * `cloud:configure:*` commands (base / gha / registry / users).
     */
    protected $signature = 'cloud:configure';

    /**
     * The console command description.
     */
    protected $description = 'Guided cloud setup for an environment — server/context, web host, optional Commons, and CI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        return $this->configureAll();
    }
}
