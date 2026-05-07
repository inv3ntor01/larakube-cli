<?php

namespace App\Commands;

use App\Contracts\HasHiddenComponents;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithDynamicOptions;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class SwapCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithDynamicOptions, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'swap {--db= : The database engine to switch to}
                                 {--cache-driver= : The cache driver to switch to}
                                 {--storage-driver= : The storage driver to switch to}
                                 {--scout-driver= : The scout driver to switch to}
                                 {--server-variation= : The server variation to switch to}
                                 {--dry-run : Show what will be changed without applying}
                                 {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Seamlessly swap architectural components (DB, Storage, Server, Search)';

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

        $swaps = [];

        // 1. Process Database Swap
        if ($db = $this->option('db')) {
            $swaps['db'] = DatabaseDriver::from($db);
        }

        // 2. Process Cache Swap
        if ($cache = $this->option('cache-driver')) {
            $swaps['cache'] = CacheDriver::from($cache);
        }

        // 3. Process Storage Swap
        if ($storage = $this->option('storage-driver')) {
            $swaps['storage'] = StorageDriver::from($storage);
        }

        // 4. Process Scout Swap
        if ($scout = $this->option('scout-driver')) {
            $swaps['scout'] = ScoutDriver::from($scout);
        }

        // 5. Process Server Swap
        if ($server = $this->option('server-variation')) {
            $swaps['server'] = ServerVariation::from($server);
        }

        // 1.1 Process Enum Flags (Swap behavior)
        foreach (DatabaseDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $swaps['db'] = $case;
            }
        }
        foreach (CacheDriver::cases() as $case) {
            if ($this->option($case->value)) {
                $swaps['cache'] = $case;
            }
        }
        foreach (StorageDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $swaps['storage'] = $case;
            }
        }
        foreach (ScoutDriver::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $swaps['scout'] = $case;
            }
        }
        foreach (ServerVariation::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden()) {
                continue;
            }

            if ($this->option($case->value)) {
                $swaps['server'] = $case;
            }
        }

        // 5. Interactive Mode (Option C)
        if (empty($swaps) && ! $this->option('no-interaction')) {
            $category = select(
                label: 'What would you like to swap?',
                options: [
                    'db' => 'Database Engine',
                    'cache' => 'Cache Driver',
                    'storage' => 'Object Storage',
                    'scout' => 'Search Engine (Scout)',
                    'server' => 'Server Variation',
                ]
            );

            switch ($category) {
                case 'db':
                    $current = implode(', ', array_map(fn ($d) => $d->value, $config->getDatabases()));
                    $this->info("Current Databases: {$current}");
                    $newDb = select('Switch primary database to:', collect(DatabaseDriver::cases())->mapWithKeys(fn ($e) => [$e->value => $e->value])->all());
                    $swaps['db'] = DatabaseDriver::from($newDb);
                    break;
                case 'cache':
                    $current = $config->getCacheDriver()?->value ?? 'none';
                    $this->info("Current Cache Driver: {$current}");
                    $newCache = select('Switch cache driver to:', collect(CacheDriver::cases())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()])->all());
                    $swaps['cache'] = CacheDriver::from($newCache);
                    break;
                case 'storage':
                    $current = $config->getObjectStorage()?->value ?? 'none';
                    $this->info("Current Storage: {$current}");
                    $newStorage = select('Switch storage to:', collect(StorageDriver::cases())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()])->all());
                    $swaps['storage'] = StorageDriver::from($newStorage);
                    break;
                case 'scout':
                    $current = $config->getScoutDriver()->value;
                    $this->info("Current Scout Driver: {$current}");
                    $newScout = select('Switch search to:', collect(ScoutDriver::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all());
                    $swaps['scout'] = ScoutDriver::from($newScout);
                    break;
                case 'server':
                    $current = $config->getServerVariation()->value;
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
            $primary = collect($config->getDatabases())->first(fn ($db) => $db->isPersistent());
            $primaryValue = $primary ? $primary->value : 'None';
            $this->line("  <fg=blue>[SWAP]</> Database: {$primaryValue} ➔ {$swaps['db']->value}");

            $databases = $config->getDatabases();
            if ($primary) {
                $databases = array_filter($databases, fn ($d) => $d !== $primary);
            }
            $databases[] = $swaps['db'];
            $config->setDatabases(array_unique($databases));
            $logDetails['db'] = "{$primaryValue} -> {$swaps['db']->value}";
        }

        if (isset($swaps['cache'])) {
            $current = $config->getCacheDriver()?->value ?? 'none';
            $this->line("  <fg=blue>[SWAP]</> Cache: {$current} ➔ {$swaps['cache']->value}");
            $config->setCacheDriver($swaps['cache']);
            $logDetails['cache'] = "{$current} -> {$swaps['cache']->value}";
        }

        if (isset($swaps['storage'])) {
            $current = $config->getObjectStorage()?->value ?? 'none';
            $this->line("  <fg=blue>[SWAP]</> Storage: {$current} ➔ {$swaps['storage']->value}");
            $config->setObjectStorage($swaps['storage']);
            $logDetails['storage'] = "{$current} -> {$swaps['storage']->value}";
        }

        if (isset($swaps['scout'])) {
            $current = $config->getScoutDriver()->value;
            $this->line("  <fg=blue>[SWAP]</> Search: {$current} ➔ {$swaps['scout']->value}");
            $config->setScoutDriver($swaps['scout']);
            $logDetails['scout'] = "{$current} -> {$swaps['scout']->value}";
        }

        if (isset($swaps['server'])) {
            $current = $config->getServerVariation()->value;
            $this->line("  <fg=blue>[SWAP]</> Server: {$current} ➔ {$swaps['server']->value}");
            $config->setServerVariation($swaps['server']);
            $logDetails['server'] = "{$current} -> {$swaps['server']->value}";
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
        $this->withSpin('Updating architectural DNA...', function () use ($config, $logDetails) {
            $this->saveProjectConfig($config->getPath(), $config);
            $this->orchestrateProjectScaffolding($config);
            $this->logActivity('Architectural components swapped', $logDetails, $config->getPath());

            return true;
        });

        $this->laraKubeInfo('Swap complete. Please run larakube up to sync your cluster.');

        return 0;
    }
}
