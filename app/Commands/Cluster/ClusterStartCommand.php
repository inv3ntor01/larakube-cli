<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ClusterStartCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cluster:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resume the local LaraKube cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $this->laraKubeInfo('Starting LaraKube cluster...');

        passthru('k3d cluster start larakube');

        $this->laraKubeInfo('✅ Cluster is online! Run "larakube up" to resume development.');

        return 0;
    }
}
