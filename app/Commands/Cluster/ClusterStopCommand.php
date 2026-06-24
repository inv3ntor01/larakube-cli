<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ClusterStopCommand extends Command
{
    use InteractsWithOs, LaraKubeOutput;

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

        if (shell_exec('which k3d')) {
            passthru('k3d cluster stop larakube');
        } elseif (shell_exec('which k3s') && $this->isLinux()) {
            $this->info('  Detected native k3s. Using systemctl...');
            passthru('sudo systemctl stop k3s');
        } else {
            $this->laraKubeError('No supported cluster engine (k3d or k3s) found.');

            return 1;
        }

        $this->laraKubeInfo('✅ Cluster stopped. Run "larakube cluster:start" to resume.');

        return 0;
    }
}
