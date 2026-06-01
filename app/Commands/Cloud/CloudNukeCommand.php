<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudNukeCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:nuke {environment? : The environment to nuke (production, staging)}
                                     {--force : Skip name confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Wipes all project resources from the remote cluster (Namespace, PVCs, etc.)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->askForCloudEnvironment(
            label: 'Which environment would you like to NUKE from the cluster?',
        );

        if (! $this->validateContextForEnvironment($environment)) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName();
        $namespace = "{$appName}-{$environment}";

        $this->laraKubeInfo("Cloud Nuke: Environment '{$environment}' in namespace '{$namespace}'");
        $this->warn('⚠ WARNING: This will permanently delete all deployments, services, and PERSISTENT DATA on the remote cluster.');
        $this->newLine();

        if (! $this->option('force')) {
            $confirmName = text(
                label: "To confirm the NUKE, please type the app name '{$appName}':",
                required: true,
            );

            if ($confirmName !== $appName) {
                $this->laraKubeError('Project name mismatch. Nuke aborted.');

                return 1;
            }
        }

        if (! confirm("Are you absolutely sure you want to WIPE '{$namespace}'? This cannot be undone.", false)) {
            $this->laraKubeInfo('Nuke cancelled.');

            return 0;
        }

        $this->withSpin('Purging project resources from cluster...', function () use ($namespace, $appName) {
            // 1. Delete the Namespace (Aggressive)
            exec("kubectl delete namespace {$namespace} --wait=false 2>/dev/null");

            // 2. Delete Cluster-Scoped Volumes (if any labeled)
            exec("kubectl delete pv -l larakube.io/project={$appName} 2>/dev/null");

            // 3. Delete the Blueprint backup secret (if it exists)
            exec("kubectl delete secret larakube-blueprint -n {$namespace} 2>/dev/null");

            return true;
        });

        $this->laraKubeInfo("✅ Nuke command issued for '{$namespace}'.");
        $this->info('Kubernetes is now tearing down the resources in the background.');
        $this->line('You can monitor the status with: <fg=cyan>kubectl get namespaces</>');

        return 0;
    }
}
