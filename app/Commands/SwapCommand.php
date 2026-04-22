<?php

namespace App\Commands;

use App\Enums\DatabaseEngine;
use App\Enums\ObjectStorage;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class SwapCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'swap {--db= : The new database engine}
                                 {--storage= : The new object storage engine}
                                 {--scout= : The new scout driver}
                                 {--server= : The new server variation}
                                 {--dry-run : Show what will be changed without applying}
                                 {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Seamlessly swap architectural components (DB, Storage, Server, Search)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        if (! file_exists($projectPath.'/.larakube.json')) {
            $this->laraKubeError('Not a LaraKube project.');

            return 1;
        }

        $config = $this->getProjectConfig($projectPath);
        $newConfig = $config;

        $swaps = [];

        // 1. Process Database Swap
        if ($db = $this->option('db')) {
            $swaps['db'] = DatabaseEngine::from($db);
        }

        // 2. Process Storage Swap
        if ($storage = $this->option('storage')) {
            $swaps['storage'] = ObjectStorage::from($storage);
        }

        // 3. Process Scout Swap
        if ($scout = $this->option('scout')) {
            $swaps['scout'] = ScoutDriver::from($scout);
        }

        // 4. Process Server Swap
        if ($server = $this->option('server')) {
            $swaps['server'] = ServerVariation::from($server);
        }

        // 5. Interactive Mode (Option C)
        if (empty($swaps) && ! $this->option('no-interaction')) {
            $category = select(
                label: 'What would you like to swap?',
                options: [
                    'db' => 'Database Engine',
                    'storage' => 'Object Storage',
                    'scout' => 'Search Engine (Scout)',
                    'server' => 'Server Variation',
                ]
            );

            switch ($category) {
                case 'db':
                    $current = implode(', ', $config['databases']);
                    $this->info("Current Databases: {$current}");
                    $newDb = select('Switch primary database to:', collect(DatabaseEngine::cases())->mapWithKeys(fn ($e) => [$e->value => $e->value])->all());
                    $swaps['db'] = DatabaseEngine::from($newDb);
                    break;
                case 'storage':
                    $current = $config['objectStorage'] ?? 'none';
                    $this->info("Current Storage: {$current}");
                    $newStorage = select('Switch storage to:', collect(ObjectStorage::cases())->mapWithKeys(fn ($e) => [$e->name => $e->value])->all());
                    $swaps['storage'] = ObjectStorage::from($newStorage);
                    break;
                case 'scout':
                    $current = $config['scoutDriver'] ?? 'none';
                    $this->info("Current Scout Driver: {$current}");
                    $newScout = select('Switch search to:', collect(ScoutDriver::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all());
                    $swaps['scout'] = ScoutDriver::from($newScout);
                    break;
                case 'server':
                    $current = $config['serverVariation'];
                    $this->info("Current Server: {$current}");
                    $newServer = select('Switch server to:', collect(ServerVariation::cases())->mapWithKeys(fn ($e) => [$e->value => $e->value])->all());
                    $swaps['server'] = ServerVariation::from($newServer);
                    break;
            }
        }

        if (empty($swaps)) {
            $this->laraKubeInfo('No components selected for swapping.');

            return 0;
        }

        // 6. Architectural Preview
        $this->laraKubeInfo('Architectural Preview: Component Swap');

        $logDetails = [];

        if (isset($swaps['db'])) {
            $primary = collect($config['databases'])->first(fn ($db) => DatabaseEngine::from($db)->isPersistent());
            $this->line("  <fg=blue>[SWAP]</> Database: {$primary} ➔ {$swaps['db']->value}");
            $newConfig['databases'] = array_values(array_unique(array_merge(
                array_diff($config['databases'], [$primary]),
                [$swaps['db']->value]
            )));
            $logDetails['db'] = "{$primary} -> {$swaps['db']->value}";
        }

        if (isset($swaps['storage'])) {
            $current = $config['objectStorage'] ?? 'none';
            $this->line("  <fg=blue>[SWAP]</> Storage: {$current} ➔ {$swaps['storage']->name}");
            $newConfig['objectStorage'] = $swaps['storage']->name;
            $logDetails['storage'] = "{$current} -> {$swaps['storage']->name}";
        }

        if (isset($swaps['scout'])) {
            $current = $config['scoutDriver'] ?? 'none';
            $this->line("  <fg=blue>[SWAP]</> Search: {$current} ➔ {$swaps['scout']->value}");
            $newConfig['scoutDriver'] = $swaps['scout']->value;
            $logDetails['scout'] = "{$current} -> {$swaps['scout']->value}";
        }

        if (isset($swaps['server'])) {
            $this->line("  <fg=blue>[SWAP]</> Server: {$config['serverVariation']} ➔ {$swaps['server']->value}");
            $newConfig['serverVariation'] = $swaps['server']->value;
            $logDetails['server'] = "{$config['serverVariation']} -> {$swaps['server']->value}";
        }

        $this->line('  <fg=red;options=bold>‼ DATA WARNING:</> LaraKube swaps infrastructure only. You MUST migrate your data manually.');

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');

            return 0;
        }

        if (! $this->option('force')) {
            if (! confirm('Apply these architectural swaps?', true)) {
                $this->laraKubeInfo('Swap cancelled.');

                return 0;
            }
        }

        // 7. Execute!
        $this->withSpin('Updating architectural DNA...', function () use ($projectPath, $newConfig, $logDetails) {
            $this->orchestrateProjectScaffolding($projectPath, basename($projectPath), $newConfig, true, true);
            $this->logActivity('Architectural components swapped', $logDetails, $projectPath);

            return true;
        });

        $this->laraKubeInfo('Swap complete. Please run larakube up to sync your cluster.');

        return 0;
    }
}
