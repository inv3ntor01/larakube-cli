<?php

namespace App\Commands;

use App\Contracts\HasHiddenComponents;
use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class AddCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'add {items?* : The database(s), feature(s), blueprint, or storage to add}
                            {--dry-run : Show what will be done without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Add databases, Laravel features, blueprints, or storage to an existing project';

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
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->checkPrerequisites()) {
            return 1;
        }

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

        $k8sPath = $config->getK8sPath();

        $selectedItems = $this->argument('items');

        // 1. Collect items from flags
        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->addDatabase($case);
            }
        }

        foreach (LaravelFeature::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->addFeature($case);
            }
        }

        foreach (CacheDriver::cases() as $case) {
            if ($this->option($case->value)) {
                $config->setCacheDriver($case);
            }
        }

        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setObjectStorage($case);
                break;
            }
        }

        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $config->setScoutDriver($case);
                break;
            }
        }

        if (empty($selectedItems)) {
            $currentBlueprint = $config->getBlueprint()?->value ?? Blueprint::LARAVEL->value;
            $currentDbs = array_map(fn ($db) => $db->value, $config->getDatabases());
            $currentFeatures = array_map(fn ($f) => $f->value, $config->getFeatures());
            $currentStorage = $config->getObjectStorage()?->value ?? 'none';

            $this->laraKubeInfo('Welcome to the Architectural Evolution wizard.');

            $type = select(
                label: 'What would you like to add?',
                options: [
                    'database' => 'Database Engine',
                    'cache' => 'Cache Driver (Redis/Memcached)',
                    'feature' => 'Laravel Feature (Lego Bricks)',
                    'storage' => 'Object Storage (S3-compatible)',
                    'blueprint' => 'Architectural Blueprint (Specialized Foundation)',
                ]
            );

            if ($type === 'database') {
                $availableDbs = collect(DatabaseDriver::cases())
                    ->filter(fn ($db) => ! in_array($db->value, $currentDbs))
                    ->mapWithKeys(fn ($db) => [$db->value => $db->value])
                    ->toArray();

                if (empty($availableDbs)) {
                    $this->laraKubeInfo('All supported databases are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(
                    label: 'Select databases to add:',
                    options: $availableDbs,
                    required: true
                );
            }

            if ($type === 'cache') {
                if ($config->hasCacheDriver()) {
                    $this->laraKubeInfo("Cache driver '{$config->getCacheDriver()->value}' is already configured.");

                    return 0;
                }

                $cacheName = select(
                    label: 'Select cache driver:',
                    options: collect(CacheDriver::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
                        ->all()
                );

                $this->addCacheDriver(CacheDriver::from($cacheName), $config);

                return 0;
            }

            if ($type === 'feature') {
                $availableFeatures = collect(LaravelFeature::cases())
                    ->filter(fn ($f) => ! in_array($f->value, $currentFeatures))
                    ->mapWithKeys(fn ($f) => [$f->value => $f->value])
                    ->toArray();

                if (empty($availableFeatures)) {
                    $this->laraKubeInfo('All supported features are already installed.');

                    return 0;
                }

                $selectedItems = multiselect(
                    label: 'Select features to add:',
                    options: $availableFeatures,
                    required: true
                );
            }

            if ($type === 'storage') {
                if ($currentStorage !== 'none') {
                    $this->laraKubeInfo("Object storage '{$currentStorage}' is already configured.");

                    return 0;
                }

                $storageName = select(
                    label: 'Select object storage engine:',
                    options: collect(StorageDriver::cases())->mapWithKeys(fn ($s) => [$s->name => $s->getLabel()])->all()
                );

                $this->addStorage(StorageDriver::from($storageName), $config);

                return 0;
            }

            if ($type === 'blueprint') {
                $blueprintValue = select(
                    label: 'Select specialized blueprint:',
                    options: [
                        Blueprint::FILAMENT->value => Blueprint::FILAMENT->getLabel(),
                        Blueprint::STATAMIC->value => Blueprint::STATAMIC->getLabel(),
                    ]
                );

                $this->addBlueprint(Blueprint::from($blueprintValue), $config);

                return 0;
            }
        }

        foreach ($selectedItems as $item) {
            $database = DatabaseDriver::tryFrom($item);
            if ($database) {
                if (in_array($database, $config->getDatabases())) {
                    $this->laraKubeInfo("Database '{$database->value}' is already added to this project. Skipping...");

                    continue;
                }
                $this->addDatabase($database, $config);

                continue;
            }

            $cache = CacheDriver::tryFrom($item);
            if ($cache) {
                if ($config->getCacheDriver() === $cache) {
                    $this->laraKubeInfo("Cache driver '{$cache->value}' is already added. Skipping...");

                    continue;
                }
                $this->addCacheDriver($cache, $config);

                continue;
            }

            $feature = LaravelFeature::tryFrom($item);
            if ($feature) {
                if (in_array($feature, $config->getFeatures())) {
                    $this->laraKubeInfo("Feature '{$feature->value}' is already added to this project. Skipping...");

                    continue;
                }
                $this->addFeature($feature, $config);

                continue;
            }

            $scout = ScoutDriver::tryFrom($item);
            if ($scout) {
                if ($config->getScoutDriver() === $scout) {
                    $this->laraKubeInfo("Scout driver '{$scout->value}' is already added. Skipping...");

                    continue;
                }
                $config->setScoutDriver($scout);
                $this->saveProjectConfig($projectPath, $config);
                $this->addFeature(LaravelFeature::SCOUT, $config);

                continue;
            }

            $storage = StorageDriver::tryFrom($item);
            if ($storage) {
                if ($config->getObjectStorage() === $storage) {
                    $this->laraKubeInfo("Storage '{$storage->value}' is already added. Skipping...");

                    continue;
                }
                $this->addStorage($storage, $config);
            }
        }

        return 0;
    }

    /**
     * @throws RandomException
     */
    protected function addBlueprint(Blueprint $blueprint, ConfigData $config): void
    {
        $projectPath = $config->getPath();

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Blueprint '{$blueprint->value}'");
        $this->line('  <fg=gray>[PHP]</> Would install required packages and extensions.');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply blueprint '$blueprint->value'?", true)) {
                return;
            }
        }

        // 1. Merge and persist PHP extensions in config first
        $config->setBlueprint($blueprint);
        $this->saveProjectConfig($projectPath, $config);

        // 3. Update K8s structure
        $this->orchestrateProjectScaffolding($config, false, false);

        // 4. Update Dockerfile for extensions
        $this->generateDockerfiles($config);

        // 5. Build local image
        $this->buildImage($config);

        // 6. Install packages
        $config->installComponents();

        $this->logActivity('Project blueprint updated', ['blueprint' => $blueprint->value], $projectPath);

        $this->laraKubeInfo("Blueprint '{$blueprint->value}' applied successfully!");

        if ($instructions = $blueprint->getPostInstallInstructions()) {
            $this->line('');
            $this->warn('Blueprint Next Steps:');
            foreach ($instructions as $line) {
                $this->line("  $line");
            }
        }
    }

    protected function addStorage(StorageDriver $storage, ConfigData $config): void
    {
        $projectPath = $config->getPath();

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Storage '{$storage->value}'");
        $this->line('  <fg=gray>[K8S]</> Would add storage manifests to .infrastructure/k8s/');
        $this->line('  <fg=gray>[PHP]</> Would install league/flysystem-aws-s3-v3');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply changes for '{$storage->value}'?", true)) {
                return;
            }
        }

        $this->withSpin("Adding storage '{$storage->value}' to cluster manifests...", function () use ($projectPath, $storage, $config) {
            $storage->updateK8s($config);
            $this->logActivity('Project storage added', ['storage' => $storage->value], $projectPath);
        });

        $config->setObjectStorage($storage);
        $this->saveProjectConfig($projectPath, $config);

        // Use shared trait for installation
        $config->installComponents();

        // Run onPostInstall to update .env
        if ($storage instanceof HasLifecycleHooks) {
            $storage->onPostInstall($projectPath, $config);
        }

        $this->laraKubeInfo("Storage '{$storage->value}' added successfully!");

        if ($storage instanceof HasLifecycleHooks) {
            $this->displayInstructions($storage->getPostInstallInstructions());
        }
    }

    protected function addFeature(LaravelFeature $feature, ConfigData $config): void
    {
        $projectPath = $config->getPath();

        if ($feature === LaravelFeature::REVERB && ! $config->getFrontend()) {
            $frontend = select(
                label: 'Which frontend stack are you using?',
                options: FrontendStack::getSelectOptions(),
                default: FrontendStack::LIVEWIRE->value
            );
            $config->setFrontend(FrontendStack::from($frontend));
        }

        if ($feature === LaravelFeature::SCOUT && ! $config->getScoutDriver()) {
            $driver = select(
                label: 'Which search driver would you like to use for Scout?',
                options: ScoutDriver::getSelectOptions(),
                default: ScoutDriver::MEILISEARCH->value
            );

            $config->setScoutDriver(ScoutDriver::from($driver));
        }

        // Save changes
        $config->saveToFile($projectPath);

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Feature '$feature->value'");
        $this->line('  <fg=gray>[K8S]</> Would add feature manifests and patches to .infrastructure/k8s/');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply changes for '$feature->value'?", true)) {
                return;
            }
        }

        $this->withSpin("Adding feature '$feature->value' to cluster manifests...", function () use ($feature, $projectPath, $config) {
            $feature->updateK8s($config);
            $this->logActivity('Project feature added', ['feature' => $feature->value], $projectPath);
        });

        $config->addFeature($feature);

        // Use shared trait for installation
        $config->installComponents();

        $this->updateProjectConfig($projectPath, 'features', [$feature->value]);

        $this->laraKubeInfo("Feature '{$feature->value}' added successfully!");

        if ($feature instanceof HasLifecycleHooks) {
            $this->displayInstructions($feature->getPostInstallInstructions());
        }
    }

    protected function addCacheDriver(CacheDriver $driver, ConfigData $config): void
    {
        $projectPath = $config->getPath();

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Cache Driver '{$driver->value}'");
        $this->line('  <fg=gray>[K8S]</> Would add cache driver manifests to .infrastructure/k8s/');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply changes for '{$driver->value}'?", true)) {
                return;
            }
        }

        $this->withSpin("Adding cache driver '{$driver->value}' to cluster manifests...", function () use ($projectPath, $driver, $config) {
            $driver->updateK8s($config);
            $this->logActivity('Project cache driver added', ['driver' => $driver->value], $projectPath);
        });

        $config->setCacheDriver($driver);
        $this->saveProjectConfig($projectPath, $config);

        // Run onPostInstall to update .env
        if ($driver instanceof HasLifecycleHooks) {
            $driver->onPostInstall($projectPath, $config);
        }

        $this->laraKubeInfo("Cache driver '{$driver->value}' added successfully!");

        if ($driver instanceof HasLifecycleHooks) {
            $this->displayInstructions($driver->getPostInstallInstructions());
        }
    }

    protected function addDatabase(DatabaseDriver $engine, ConfigData $config): void
    {
        $projectPath = $config->getPath();

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Database '{$engine->value}'");
        $this->line('  <fg=gray>[K8S]</> Would add database deployment and volumes to .infrastructure/k8s/');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply changes for '{$engine->value}'?", true)) {
                return;
            }
        }

        $this->withSpin("Adding database '$engine->value' to cluster manifests...", function () use ($engine, $projectPath, $config) {
            $engine->updateK8s($config);
            $this->logActivity('Project database added', ['database' => $engine->value], $projectPath);
        });

        $config->addDatabase($engine);
        $this->saveProjectConfig($projectPath, $config);

        // Use shared trait for installation if it's a feature database (like MongoDB)
        if (! empty($engine->getComposerDependencies($config))) {
            $config->installComponents();
        }

        if (confirm("Would you like to make {$engine->value} your primary database connection? (Updates your .env)", true)) {
            $this->updateEnvironmentDatabase($projectPath, $engine);
        }

        $this->laraKubeInfo("Database '{$engine->value}' added successfully!");

        if ($engine instanceof HasLifecycleHooks) {
            $this->displayInstructions($engine->getPostInstallInstructions());
        }
    }

    protected function displayInstructions(array $instructions): void
    {
        if (empty($instructions)) {
            return;
        }

        $this->newLine();
        $this->warn('Next Steps:');
        foreach ($instructions as $line) {
            $this->line("  $line");
        }
        $this->newLine();
    }

    protected function updateEnvironmentDatabase(string $projectPath, DatabaseDriver $engine): void
    {
        $this->syncEnvFile($projectPath, [
            'DB_CONNECTION' => $engine->dbConnection(),
            'DB_HOST' => $engine->dbHost(),
            'DB_PORT' => $engine->dbPort(),
            'DB_USERNAME' => $engine->dbUsername(),
        ]);
    }
}
