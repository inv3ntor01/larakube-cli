<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudNukeCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:nuke {environment? : The environment to nuke (production, staging)}
                                     {--force : Skip name confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Wipes all project resources from a remote environment (Namespace, PVCs, etc.)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment') ?: $this->askForCloudEnvironment(
            label: 'Which environment would you like to NUKE from the cluster?',
        );

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName();
        $namespace = $this->getNamespace($environment, $appName);

        // Target the environment's OWN cluster (no context switching). Showing the
        // resolved context makes it explicit which remote cluster gets wiped.
        $context = $this->environmentContextOrCurrent($config, $environment);
        $where = $context ? "context '{$context}'" : 'the current context';

        $this->laraKubeInfo("Cloud Nuke: '{$namespace}' on {$where}");
        $this->warn('⚠ WARNING: This permanently deletes all deployments, services, and PERSISTENT DATA for this environment.');

        // Plex tenants keep their data in the shared Commons, which this does NOT
        // touch — point at plex:leave to reclaim the tenant database/role.
        if (! empty($config->getPlex($environment))) {
            $this->line("  <fg=gray>Note:</> the app's data in the shared Commons is left intact — run <fg=yellow>larakube plex:leave {$environment}</> to drop it.");
        }
        $this->newLine();

        if (! $this->option('force')) {
            $confirmName = text(
                label: "To confirm the NUKE, type the app name '{$appName}':",
                required: true,
            );

            if ($confirmName !== $appName) {
                $this->laraKubeError('Project name mismatch. Nuke aborted.');

                return 1;
            }

            if (! confirm("Are you absolutely sure you want to WIPE '{$namespace}'? This cannot be undone.", false)) {
                $this->laraKubeInfo('Nuke cancelled.');

                return 0;
            }
        }

        // Delegate the teardown to `down`, which auto-targets the environment's own
        // context (no switching) and removes the namespace + project PVs. --force
        // because we've already confirmed above — one teardown path for both.
        return (int) $this->call('down', [
            'environment' => $environment,
            '--force' => true,
        ]);
    }
}
