<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

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
        $name = Str::slug(basename($projectPath));
        $config = null;

        if (! $this->isLaraKubeProject(false)) {
            $this->laraKubeError('No .larakube.json found! LaraKube cannot heal a project without its DNA.');

            if (confirm('Would you like to try restoring the blueprint from the cluster?') && $config = ConfigData::restoreFromCluster(appName: $name)) {
                $this->laraKubeInfo('✅ Successfully restored blueprint from cluster metadata.');
                $config->saveToFile($projectPath);
            } else {
                $this->laraKubeError('Failed to find blueprint backup in the cluster.');

                return 1;
            }
        }

        if (! $config) {
            $config = $this->getProjectConfig($projectPath);
        }

        if (! $config->getPath() || $config->getPath() !== $projectPath) {
            $config->setPath($projectPath);
        }

        if (! $config->getName() || $config->getName() !== $name) {
            $config->setName($name);
        }

        $appName = $config->getName();
        $this->laraKubeInfo("Healing architectural masterpiece: {$appName}...");

        if (! $this->option('force')) {
            $this->laraKubeError('WARNING: This will OVERWRITE the following files:');
            $this->line('  - .infrastructure/k8s/ (All manifests)');
            $this->line('  - .infrastructure/traefik/certificates/');
            $this->line('  - Dockerfile.php');
            $this->line('  - Dockerfile.node');
            $this->line('  - .env (Syncs architectural keys)');
            $this->newLine();

            $confirm = text(
                label: "To confirm architectural regeneration, please type the project name '$appName':",
                required: true
            );

            if ($confirm !== $appName) {
                $this->laraKubeInfo('Confirmation failed. Heal cancelled.');

                return 0;
            }
        }

        $this->withSpin('Regenerating infrastructure manifests...', function () use ($config) {
            $this->orchestrateProjectScaffolding($config, false, false);
            $this->logActivity('Project healed and manifests regenerated');

            return true;
        });

        $this->laraKubeInfo('Architectural integrity restored! 🚀');
        info('Next steps: larakube up');

        return 0;
    }
}
