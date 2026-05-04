<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

class DownCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'down {environment=local : The environment to remove}
                            {--force : Skip confirmation}
                            {--dry-run : Show what would be deleted without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Remove application resources and internal volumes from the cluster (Cleanup)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');
        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName() ?? basename($projectPath);
        $namespace = $this->getNamespace($environment, $appName);

        if ($this->option('dry-run')) {
            $this->laraKubeInfo("DRY RUN: Project '$appName' cleanup preview:");
            $this->line("  <fg=gray>[K8S]</> Would delete namespace '$namespace' and ALL internal volumes.");
            $this->laraKubeInfo('DRY RUN COMPLETE: No resources were modified.');

            return 0;
        }

        if (! $this->option('force')) {
            $this->laraKubeError('WARNING: This will delete the namespace and all cluster volumes.');
            $confirm = text(
                label: "To confirm, please type the project name '$appName':",
                required: true
            );

            if ($confirm !== $appName) {
                $this->laraKubeInfo('Confirmation failed. Cleanup cancelled.');

                return 0;
            }
        }

        $this->laraKubeInfo("Removing namespace '$namespace'...");
        passthru("kubectl delete namespace $namespace");

        $this->laraKubeInfo('Cleaning up cluster-scoped PersistentVolumes...');
        passthru("kubectl delete pv -l larakube-project=$appName");

        // Give the local storage provisioner a moment to actually wipe the host files
        $this->withSpin('Ensuring cluster-native volumes are wiped...', function () {
            sleep(5);

            return true;
        });

        $this->laraKubeInfo('Cleanup complete. Your local Docker image and project files remain intact.');
        $this->info('Next steps: larakube up');

        return 0;
    }
}
