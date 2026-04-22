<?php

namespace App\Commands;

use App\Actions\Contracts\FeatureAction;
use App\Actions\ObjectStorage\GarageAction;
use App\Actions\ObjectStorage\MinioAction;
use App\Actions\ObjectStorage\SeaweedFsAction;
use App\Enums\Blueprint;
use App\Enums\DatabaseEngine;
use App\Enums\LaravelFeature;
use App\Enums\ObjectStorage;
use App\Traits\CheckPrerequisites;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class AddCommand extends Command
{
    use CheckPrerequisites, GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'add {items?* : The database(s), feature(s), blueprint, or storage to add}
                            {--mysql : Add MySQL database}
                            {--postgres : Add PostgreSQL database}
                            {--mariadb : Add MariaDB database}
                            {--mongodb : Add MongoDB database}
                            {--redis : Add Redis cache}
                            {--horizon : Add Laravel Horizon}
                            {--reverb : Add Laravel Reverb}
                            {--meilisearch : Add Meilisearch search engine}
                            {--typesense : Add Typesense search engine}
                            {--monitoring : Add Prometheus and Grafana}
                            {--minio : Add MinIO storage}
                            {--seaweedfs : Add SeaweedFS storage}
                            {--dry-run : Show what will be done without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Add databases, Laravel features, blueprints, or storage to an existing project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->checkPrerequisites()) {
            return 1;
        }

        $projectPath = getcwd();
        if (! is_dir($projectPath.'/.infrastructure')) {
            $this->laraKubeError('Not a LaraKube project. Make sure you are in the root directory.');

            return 1;
        }

        $config = $this->getProjectConfig($projectPath);
        $appName = basename($projectPath);
        $k8sPath = $projectPath.'/.infrastructure/k8s';

        $selectedItems = $this->argument('items');

        // 1. Collect items from flags
        $flagMappings = [
            'mysql' => DatabaseEngine::MYSQL->value,
            'postgres' => DatabaseEngine::POSTGRESQL->value,
            'mariadb' => DatabaseEngine::MARIADB->value,
            'mongodb' => DatabaseEngine::MONGODB->value,
            'redis' => DatabaseEngine::REDIS->value,
            'horizon' => LaravelFeature::HORIZON->value,
            'reverb' => LaravelFeature::REVERB->value,
            'meilisearch' => LaravelFeature::SCOUT->value,
            'typesense' => LaravelFeature::SCOUT->value,
            'monitoring' => LaravelFeature::MONITORING->value,
            'minio' => 'minio',
            'seaweedfs' => 'seaweedfs',
        ];

        foreach ($flagMappings as $flag => $value) {
            if ($this->option($flag)) {
                $selectedItems[] = $value;
            }
        }

        if (empty($selectedItems)) {
            $currentBlueprint = $config['blueprint'] ?? Blueprint::LARAVEL->value;
            $currentDbs = $config['databases'] ?? [];
            $currentFeatures = $config['features'] ?? [];
            $currentStorage = $config['objectStorage'] ?? 'none';

            $this->laraKubeInfo('Welcome to the Architectural Evolution wizard.');

            $type = select(
                label: 'What would you like to add?',
                options: [
                    'database' => 'Database Engine',
                    'feature' => 'Laravel Feature (Lego Bricks)',
                    'storage' => 'Object Storage (S3-compatible)',
                    'blueprint' => 'Architectural Blueprint (Specialized Foundation)',
                ]
            );

            if ($type === 'database') {
                $availableDbs = collect(DatabaseEngine::cases())
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

                $storage = select(
                    label: 'Select object storage engine:',
                    options: [
                        ObjectStorage::MINIO->name => ObjectStorage::MINIO->value,
                        ObjectStorage::SEAWEEDFS->name => ObjectStorage::SEAWEEDFS->value,
                        ObjectStorage::GARAGE->name => ObjectStorage::GARAGE->value,
                    ]
                );

                $this->addStorage(ObjectStorage::from($storage), $projectPath, $k8sPath, $appName, $config);

                return 0;
            }

            if ($type === 'blueprint') {
                $blueprint = select(
                    label: 'Select specialized blueprint:',
                    options: [
                        Blueprint::FILAMENT->value => 'FilamentPHP (Admin Panels)',
                        Blueprint::STATAMIC->value => 'Statamic (Flat-file CMS)',
                    ]
                );

                $this->addBlueprint(Blueprint::from($blueprint), $projectPath, $k8sPath, $appName, $config);

                return 0;
            }
        }

        foreach ($selectedItems as $item) {
            $database = DatabaseEngine::tryFrom($item);
            if ($database) {
                if (in_array($database->value, $config['databases'] ?? [])) {
                    $this->laraKubeInfo("Database '{$database->value}' is already added to this project. Skipping...");

                    continue;
                }
                $this->addDatabase($database, $projectPath, $k8sPath, $appName, $config);

                continue;
            }

            $feature = LaravelFeature::tryFrom($item);
            if ($feature) {
                if (in_array($feature->value, $config['features'] ?? [])) {
                    $this->laraKubeInfo("Feature '{$feature->value}' is already added to this project. Skipping...");

                    continue;
                }
                $this->addFeature($feature, $projectPath, $k8sPath, $appName, $config);

                continue;
            }

            $storage = ObjectStorage::tryFrom($item) ?? ObjectStorage::from(strtoupper($item));
            if ($storage) {
                if (($config['objectStorage'] ?? 'none') === $storage->name) {
                    $this->laraKubeInfo("Storage '{$storage->value}' is already added. Skipping...");

                    continue;
                }
                $this->addStorage($storage, $projectPath, $k8sPath, $appName, $config);
            }
        }

        return 0;
    }

    protected function addBlueprint(Blueprint $blueprint, string $projectPath, string $k8sPath, string $appName, array $config): void
    {
        $blueprintAction = $blueprint->action();

        if ($blueprintAction) {
            $blueprintConfig = $blueprintAction->gatherConfig();
            $config = array_merge($config, $blueprintConfig);

            // 1. Always show preview
            $this->laraKubeInfo("Previewing Addition: Blueprint '{$blueprint->value}'");
            $this->line('  <fg=gray>[PHP]</> Would install required packages and extensions.');

            if ($this->option('dry-run')) {
                return;
            }

            if (! $this->option('no-interaction')) {
                if (! $this->confirm("Apply blueprint '{$blueprint->value}'?", true)) {
                    return;
                }
            }

            // 1. Merge and persist PHP extensions in config first
            $phpExtensions = $blueprintAction->getPhpExtensions();
            $config['additionalExtensions'] = array_unique(array_merge($config['additionalExtensions'] ?? [], $phpExtensions));
            $this->updateProjectConfig($projectPath, 'additionalExtensions', $config['additionalExtensions']);
            $this->updateProjectConfig($projectPath, 'blueprint', $blueprint->value);

            // 2. Apply manifests
            $blueprintAction->apply($projectPath, $k8sPath, $appName, $config);

            // 3. Update K8s structure
            $this->orchestrateProjectScaffolding($projectPath, $appName, $config, false, false);

            // 4. Update Dockerfile for extensions
            $this->generateDockerfiles($projectPath, $config['serverVariation'], $config['phpVersion'], $config['os'], $config['additionalExtensions']);

            // 5. Build local image
            $this->buildImage($projectPath, $appName);

            // 6. Install packages
            $this->installLaravelFeatures($projectPath, [], $config['packageManager'] ?? 'npm', array_merge($config, ['blueprint' => $blueprint->value]), (bool) $this->option('dry-run'));

            $this->logActivity('Project blueprint updated', ['blueprint' => $blueprint->value], $projectPath);
        }

        $this->laraKubeInfo("Blueprint '{$blueprint->value}' applied successfully!");

        if ($instructions = $blueprintAction->getPostInstallInstructions()) {
            $this->line('');
            $this->warning('Blueprint Next Steps:');
            foreach ($instructions as $line) {
                $this->line("  {$line}");
            }
        }
    }

    protected function addStorage(ObjectStorage $storage, string $projectPath, string $k8sPath, string $appName, array &$config): void
    {
        $action = match ($storage) {
            ObjectStorage::MINIO => new MinioAction,
            ObjectStorage::SEAWEEDFS => new SeaweedFsAction,
            ObjectStorage::GARAGE => new GarageAction,
        };

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

        $this->withSpin("Adding storage '{$storage->value}' to cluster manifests...", function () use ($action, $k8sPath, $appName, $projectPath, $storage) {
            $action->updateK8s($k8sPath, $appName, ['projectPath' => $projectPath]);
            $this->logActivity('Project storage added', ['storage' => $storage->value], $projectPath);
        });

        // Use shared trait for installation
        $this->installLaravelFeatures($projectPath, [], $config['packageManager'] ?? 'npm', $config);

        // Run onPostInstall to update .env
        $action->onPostInstall($projectPath);

        $this->updateProjectConfig($projectPath, 'objectStorage', $storage->name);

        $this->laraKubeInfo("Storage '{$storage->value}' added successfully!");
    }

    protected function addFeature(LaravelFeature $feature, string $projectPath, string $k8sPath, string $appName, array $config): void
    {
        $action = $feature->action();

        // 1. Always show preview
        $this->laraKubeInfo("Previewing Addition: Feature '{$feature->value}'");
        $this->line('  <fg=gray>[K8S]</> Would add feature manifests and patches to .infrastructure/k8s/');

        if ($this->option('dry-run')) {
            return;
        }

        if (! $this->option('no-interaction')) {
            if (! $this->confirm("Apply changes for '{$feature->value}'?", true)) {
                return;
            }
        }

        $this->withSpin("Adding feature '{$feature->value}' to cluster manifests...", function () use ($action, $k8sPath, $appName, $projectPath, $feature) {
            $action->updateK8s($k8sPath, $appName, [
                'projectPath' => $projectPath,
            ]);

            $action->updateDockerCompose($projectPath);
            $this->logActivity('Project feature added', ['feature' => $feature->value], $projectPath);
        });

        // Use shared trait for installation
        $this->installLaravelFeatures($projectPath, [$feature->value], $config['packageManager'] ?? 'npm', $config);

        $this->updateProjectConfig($projectPath, 'features', [$feature->value]);

        $this->laraKubeInfo("Feature '{$feature->value}' added successfully!");
    }

    protected function addDatabase(DatabaseEngine $engine, string $projectPath, string $k8sPath, string $appName, array $config): void
    {
        $action = $engine->action();

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

        $this->withSpin("Adding database '{$engine->value}' to cluster manifests...", function () use ($action, $k8sPath, $appName, $projectPath, $engine) {
            if ($action) {
                $action->updateK8s($k8sPath, $appName, ['projectPath' => $projectPath]);
                $action->updateDockerCompose($projectPath);
            }
            $this->logActivity('Project database added', ['database' => $engine->value], $projectPath);
        });

        // Use shared trait for installation if it's a feature database (like MongoDB)
        if ($action instanceof FeatureAction) {
            $this->installLaravelFeatures($projectPath, [], $config['packageManager'] ?? 'npm', array_merge($config, ['databases' => [$engine->value]]));
        }

        $this->updateProjectConfig($projectPath, 'databases', [$engine->value]);

        if ($engine !== DatabaseEngine::REDIS) {
            if (confirm("Would you like to make {$engine->value} your primary database connection? (Updates your .env)", true)) {
                $this->updateEnvironmentDatabase($projectPath, $engine);
            }
        }

        $this->laraKubeInfo("Database '{$engine->value}' added successfully!");
    }

    protected function updateEnvironmentDatabase(string $projectPath, DatabaseEngine $engine): void
    {
        $dbHost = $engine->dbHost();
        $dbUser = $engine->dbUsername();
        $dbConn = $engine->dbConnection();

        $this->syncEnvFile($projectPath, [
            'DB_CONNECTION' => $dbConn,
            'DB_HOST' => $dbHost,
            'DB_PORT' => $engine->dbPort(),
            'DB_USERNAME' => $dbUser,
        ]);
    }
}
