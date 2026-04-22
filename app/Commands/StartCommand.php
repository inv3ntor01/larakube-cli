<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class StartCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'start {environment=local : The environment to start}';

    /**
     * The console command description.
     */
    protected $description = 'Resume application services by scaling pods to their original state';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment');
        $namespace = $this->getNamespace($environment);

        $this->laraKubeInfo("Resuming services in '{$environment}'...");

        // We scale all deployments to at least 1 (Default LaraKube state)
        // A more advanced version would read the blueprint to find exact replica counts.
        $this->withSpin('Scaling up application pods...', function () use ($namespace) {
            exec("kubectl scale deployment --all --replicas=1 -n {$namespace}");

            return true;
        });

        $this->logActivity('Services resumed (scaled up)', ['environment' => $environment]);

        $this->laraKubeInfo('All services are resuming. Use larakube status to monitor progress.');

        return 0;
    }
}
