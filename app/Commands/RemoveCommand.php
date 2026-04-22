<?php

namespace App\Commands;

use App\Enums\DatabaseEngine;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;

class RemoveCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'remove {items?* : The database(s), feature(s), or storage to remove}
                            {--mysql : Remove MySQL database}
                            {--postgres : Remove PostgreSQL database}
                            {--mariadb : Remove MariaDB database}
                            {--mongodb : Remove MongoDB database}
                            {--redis : Remove Redis cache}
                            {--horizon : Remove Laravel Horizon}
                            {--reverb : Remove Laravel Reverb}
                            {--meilisearch : Remove Meilisearch}
                            {--typesense : Remove Typesense}
                            {--monitoring : Remove Prometheus and Grafana}
                            {--minio : Remove MinIO storage}
                            {--seaweedfs : Remove SeaweedFS storage}
                            {--frankenphp : Remove FrankenPHP (Falls back to Nginx)}
                            {--dry-run : Show what will be removed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Remove specific databases, features, or storage from your project';

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
        $toRemove = $this->argument('items');

        // 1. Collect from flags
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
                $toRemove[] = $value;
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

        // 3. Dependency Check & Fallbacks
        $newDatabases = array_diff($config['databases'] ?? [], $toRemove);
        $newFeatures = array_diff($config['features'] ?? [], $toRemove);
        $newServer = $config['serverVariation'];

        // --- 🛡 ARCHITECTURAL GUARDS ---

        // Guard: Horizon requires Redis
        if (in_array(LaravelFeature::HORIZON->value, $newFeatures) && ! in_array(DatabaseEngine::REDIS->value, $newDatabases)) {
            $this->line('  <fg=red>[CONFLICT]</> Horizon (active) requires Redis (being removed).');
            if (confirm('Would you like to remove Horizon as well?', true)) {
                $toRemove[] = LaravelFeature::HORIZON->value;
                $newFeatures = array_diff($newFeatures, [LaravelFeature::HORIZON->value]);
            } else {
                $this->laraKubeError('Removal aborted. Horizon cannot run without Redis.');

                return 1;
            }
        }

        // Guard: Octane requires FrankenPHP
        if (in_array(LaravelFeature::OCTANE->value, $newFeatures) && $this->option('frankenphp')) {
            $this->line('  <fg=yellow>[AUTO-REMOVE]</> Octane ➔ Removed (Requires FrankenPHP)');
            $newFeatures = array_diff($newFeatures, [LaravelFeature::OCTANE->value]);
        }

        // Server Floor
        if ($this->option('frankenphp')) {
            $this->line('  <fg=yellow>[FALLBACK]</> Server: FrankenPHP ➔ Nginx/FPM (App must have a runtime)');
            $newServer = ServerVariation::FPM_NGINX->value;
        }

        // Database Floor: No SQLite allowed
        $persistentDbs = [
            DatabaseEngine::MYSQL->value,
            DatabaseEngine::MARIADB->value,
            DatabaseEngine::POSTGRESQL->value,
            DatabaseEngine::MONGODB->value,
        ];

        $hasPersistentDb = collect($newDatabases)->contains(fn ($db) => in_array($db, $persistentDbs));

        if (! $hasPersistentDb) {
            $this->line('');
            $this->line('  <fg=red;options=bold>✖ ARCHITECTURAL ERROR: Database Floor Reached</>');
            $this->line('  LaraKube requires at least one server-based database to ensure');
            $this->line('  data consistency across your cluster pods.');
            $this->line('');
            $this->warning("Recommendation: Run 'larakube add --mysql' (or postgres) FIRST, then remove the current one.");
            $this->line('');

            return 1;
        }

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
        $config['databases'] = array_values($newDatabases);
        $config['features'] = array_values($newFeatures);
        $config['serverVariation'] = $newServer;

        if (in_array('minio', $toRemove) || in_array('seaweedfs', $toRemove)) {
            $config['objectStorage'] = 'none';
        }

        $this->withSpin('Updating infrastructure DNA...', function () use ($projectPath, $config) {
            // Re-run orchestration without building image (unless server changed)
            $this->orchestrateProjectScaffolding($projectPath, basename($projectPath), $config, false, false);

            return true;
        });

        $this->laraKubeInfo('Removal complete. Please run larakube up to sync the cluster.');

        return 0;
    }
}
