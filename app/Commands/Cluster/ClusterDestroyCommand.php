<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class ClusterDestroyCommand extends Command
{
    use LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:destroy';

    /**
     * The console command description.
     */
    protected $description = 'Completely remove the local Kubernetes cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Destroyer');

        $engine = select(
            label: 'Which cluster would you like to destroy?',
            options: [
                'k3d' => 'k3d cluster (larakube)',
                'k3s' => 'Native k3s service (Linux)',
            ],
            default: 'k3d'
        );

        if (! confirm("Are you absolutely sure? This will delete ALL namespaces and data in the '{$engine}' cluster.", false)) {
            $this->laraKubeInfo('Action cancelled.');

            return 0;
        }

        if ($engine === 'k3d') {
            return $this->destroyK3d();
        }

        return $this->destroyK3s();
    }

    protected function destroyK3d(): int
    {
        $this->withSpin('Deleting k3d cluster...', fn () => passthru('k3d cluster delete larakube'));
        $this->laraKubeInfo('✅ k3d cluster destroyed.');

        return 0;
    }

    protected function destroyK3s(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->laraKubeError('Native k3s is only on Linux.');

            return 1;
        }

        $this->withSpin('Uninstalling k3s...', fn () => passthru('/usr/local/bin/k3s-uninstall.sh'));
        $this->laraKubeInfo('✅ k3s service uninstalled.');

        return 0;
    }
}
