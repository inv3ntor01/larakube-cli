<?php

namespace App\Commands;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class HealCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'heal {environment=local : The environment to restore from}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automated self-healing: Regenerate project infrastructure from .larakube.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $environment = $this->argument('environment');
        $namespace = $this->getNamespace($environment);

        // 1. Resilience Check: If local config is missing, try to restore from cluster
        if (empty($config)) {
            $this->laraKubeInfo('Local .larakube.json not found. Checking cluster for master blueprint...');

            $blueprint = shell_exec("kubectl get secret larakube-blueprint -n {$namespace} -o jsonpath='{.data.\.larakube\.json}' 2>/dev/null | base64 --decode");

            if ($blueprint && ($decoded = json_decode($blueprint, true))) {
                if (confirm("Master blueprint found in cluster namespace '{$namespace}'. Restore it locally?", true)) {
                    file_put_contents($projectPath.'/.larakube.json', $blueprint);
                    $config = $decoded;
                    $this->laraKubeInfo('Master blueprint restored successfully!');
                }
            }
        }

        if (empty($config)) {
            $this->laraKubeError('No LaraKube configuration found locally or in cluster!');
            $this->info('Make sure you are in the root of a LaraKube project.');

            return 1;
        }

        $appName = basename($projectPath);
        $this->laraKubeInfo("Healing infrastructure for masterpiece: {$appName}...");

        $this->withSpin('Regenerating Kubernetes manifests and patches...', function () use ($projectPath, $appName, $config) {
            // We pass false for both installFeatures and buildImage because we only want to fix the K8s files
            $this->orchestrateProjectScaffolding($projectPath, $appName, $config, false, false);
        });

        $this->laraKubeInfo('ARCHITECTURAL INTEGRITY RESTORED! ✅');
        $this->info('Next steps: larakube up --dry-run');

        $this->renderStarPrompt();

        return 0;
    }
}
