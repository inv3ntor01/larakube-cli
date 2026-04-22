<?php

namespace App\Commands;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class HealCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'heal {--force : Skip confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate infrastructure manifests from your project blueprint';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (empty($config)) {
            $this->laraKubeError('No .larakube.json found! LaraKube cannot heal a project without its DNA.');

            if (confirm('Would you like to try restoring the blueprint from the cluster?', true)) {
                $config = $this->restoreBlueprintFromCluster();
                if (! $config) {
                    return 1;
                }
            } else {
                return 1;
            }
        }

        $this->laraKubeInfo('Healing architectural masterpiece: '.basename($projectPath));

        if (! $this->option('force')) {
            if (! confirm('This will regenerate all manifests in .infrastructure/k8s/. Proceed?', true)) {
                $this->laraKubeInfo('Heal cancelled.');

                return 0;
            }
        }

        $this->withSpin('Regenerating infrastructure manifests...', function () use ($projectPath, $config) {
            $this->orchestrateProjectScaffolding($projectPath, basename($projectPath), $config, false, false);
            $this->logActivity('Project healed and manifests regenerated');

            return true;
        });

        $this->laraKubeInfo('Architectural integrity restored! 🚀');
        info('Next steps: larakube up');

        return 0;
    }

    protected function restoreBlueprintFromCluster(): ?array
    {
        $this->laraKubeInfo('Attempting to restore blueprint from cluster...');

        $appName = basename(getcwd());
        // Check local first, then production
        foreach (['local', 'production'] as $env) {
            $namespace = "{$appName}-{$env}";
            $json = shell_exec("kubectl get secret larakube-blueprint -n {$namespace} -o jsonpath='{.data.\.larakube\.json}' 2>/dev/null");

            if ($json) {
                $config = json_decode(base64_decode($json), true);
                if ($config) {
                    $this->laraKubeInfo("✅ Successfully restored blueprint from '{$namespace}' namespace.");
                    file_put_contents(getcwd().'/.larakube.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return $config;
                }
            }
        }

        $this->laraKubeError('Failed to find blueprint backup in the cluster.');

        return null;
    }
}
