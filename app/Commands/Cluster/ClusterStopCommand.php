<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ClusterStopCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cluster:stop';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause the local LaraKube cluster (stops containers without deleting data)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Stopping LaraKube cluster...');

        passthru('k3d cluster stop larakube');

        $this->laraKubeInfo('✅ Cluster stopped. Run "larakube cluster:start" to resume.');

        return 0;
    }
}
