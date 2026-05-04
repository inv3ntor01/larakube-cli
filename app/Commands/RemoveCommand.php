<?php

namespace App\Commands;

use App\Contracts\HasHiddenComponents;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class RemoveCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithDynamicOptions, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'remove {items?* : The database(s), feature(s), or storage to remove}
                            {--dry-run : Show what will be removed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Remove specific databases, features, or storage from your project';

    /**
     * Configure the command to ignore validation errors so we can forward arbitrary flags.
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->addArchitecturalOptions();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $name = Str::slug(basename($projectPath));
        $config = $this->getProjectConfig($projectPath);

        if (! $config->getPath() || $config->getPath() !== $projectPath) {
            $config->setPath($projectPath);
        }

        if (! $config->getName() || $config->getName() !== $name) {
            $config->setName($name);
        }

        $toRemove = $this->argument('items');

        // Laravel Features

        $features = [];

        foreach (LaravelFeature::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $features[] = $case;
            }
        }

        $config->removeFeature(...$features);

        // Databases

        $databases = [];

        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $databases[] = $case;
            }
        }

        if ($config->hasFeature(LaravelFeature::HORIZON) && in_array(DatabaseDriver::REDIS, $databases)) {
            $this->line('  <fg=red>[CONFLICT]</> Horizon (active) requires Redis (being removed).');
            if (confirm('Would you like to remove Horizon as well?')) {
                $config->removeFeature(LaravelFeature::HORIZON);
            } else {
                $this->laraKubeError('Removal aborted. Horizon cannot run without Redis.');

                return 1;
            }
        }

        $config->removeDatabase(...$databases);

        if (! $config->hasPersistentDatabase()) {
            $this->newLine();
            $this->line('  <fg=red;options=bold>✖ ARCHITECTURAL ERROR: Database Floor Reached</>');
            $this->line('  LaraKube requires at least one server-based database to ensure');
            $this->line('  data consistency across your cluster pods.');
            $this->newLine();
            warning("Recommendation: Run 'larakube add --mysql' (or postgres) FIRST, then remove the current one.");
            $this->newLine();

            return 1;
        }

        // Server Variation

        if ($this->option(ServerVariation::FRANKENPHP->value)) {
            if ($config->hasFeature(LaravelFeature::OCTANE)) {
                $this->line('  <fg=yellow>[AUTO-REMOVE]</> Octane ➔ Removed (Requires FrankenPHP)');
                $config->removeFeature(LaravelFeature::OCTANE);
            }

            $this->line('  <fg=yellow>[FALLBACK]</> Server: FrankenPHP ➔ Nginx/FPM (App must have a runtime)');
            $config->setServerVariation(ServerVariation::FPM_NGINX);
        }

        // Storage

        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->removeObjectStorage($case);
            }
        }

        // Scout

        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->removeScoutDriver($case);
            }
        }

        if (empty($toRemove)) {
            $this->laraKubeInfo('No items specified for removal. Use flags like --mysql or provide names as arguments.');

            return 0;
        }

        $toRemove = array_unique($toRemove);

        // 2. Preview Removal
        $this->laraKubeInfo('Architectural Preview: Service Removal');
        foreach ($toRemove as $item) {
            $this->line("  <fg=red>[REMOVE]</> {$item}");
        }

        // --- 🛡 ARCHITECTURAL GUARDS ---

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');

            return 0;
        }

        if (! confirm('Apply these architectural removals?', true)) {
            $this->laraKubeInfo('Removal cancelled.');

            return 0;
        }

        // 4. Update Config & Re-scaffold (Self-Healing pattern)

        $this->withSpin('Updating infrastructure DNA...', function () use ($config) {
            $this->saveProjectConfig($config->getPath(), $config);
            $this->orchestrateProjectScaffolding($config, false, false);

            return true;
        });

        $this->laraKubeInfo('Removal complete. Please run larakube up to sync the cluster.');

        return 0;
    }
}
