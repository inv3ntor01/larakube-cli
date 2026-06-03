<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PlexLeaveCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:leave
        {environment=production : The cloud environment to remove from the Commons}
        {--backup= : Path for the pre-drop pg_dump backup (default: ./<tenant>-commons-<env>.sql)}
        {--no-backup : Skip the safety backup before dropping (dangerous)}
        {--force : Skip the name confirmation}';

    protected $description = 'Remove this project from the shared Commons (drops its tenant database/role)';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Leave the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');
        if ($env === 'local') {
            $this->laraKubeError('Plex is a cloud topology — pick a cloud environment.');

            return 1;
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName);

        // Target the env's own Commons context (no switching); fall back to the
        // current context if no deploy target is recorded.
        $context = $this->environmentContextOrCurrent($config, $env);
        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        // Tenant must be registered in this Commons.
        $registry = $this->getRegistry();
        if (! isset($registry['tenants'][$tenant])) {
            $this->laraKubeInfo("'{$tenant}' is not a tenant of this Commons — nothing to do.");

            return 0;
        }

        $entry = $registry['tenants'][$tenant];
        $db = $entry['db'] ?? null;
        $redisIndex = $entry['redis_index'] ?? null;
        $s3Bucket = $entry['s3_bucket'] ?? null;
        $s3Service = $entry['s3_service'] ?? 'seaweedfs';
        // Which engine holds the tenant DB — legacy entries predate db_service,
        // so default to Postgres (the only Commons backend back then).
        $dbDriver = DatabaseDriver::tryFrom($entry['db_service'] ?? 'postgres') ?? DatabaseDriver::POSTGRESQL;
        $ns = $this->plexNamespace();

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>".($context ?: 'current').'</>');
        $this->laraKubeWarn('⚠ This will PERMANENTLY drop this tenant from the Commons:');
        if ($db) {
            $this->laraKubeLine("    • {$dbDriver->getLabel()} database \"{$db}\" and login \"{$tenant}\" (all data)");
        }
        if ($redisIndex !== null) {
            $this->laraKubeLine("    • Redis logical DB {$redisIndex} (flushed)");
        }
        if ($s3Bucket) {
            $this->laraKubeLine("    • Object-storage bucket \"{$s3Bucket}\" (all objects)");
        }
        $this->laraKubeLine('    • the tenant entry in the Commons registry');
        $this->laraKubeNewLine();

        // The app should be torn down first — its pods still point at this data.
        if ($this->appStillDeployed($config, $env)) {
            $this->laraKubeWarn("Heads up: '{$appName}' still appears deployed in '{$config->getNamespace($env)}'.");
            $this->laraKubeLine("  Tear it down first (larakube down {$env}) so nothing is mid-write while we drop the data.");
            $this->laraKubeNewLine();
        }

        if (! $this->option('force')) {
            $confirm = text(label: "To confirm, type the app name '{$appName}':", required: true);
            if ($confirm !== $appName) {
                $this->laraKubeError('Name mismatch. Leave aborted.');

                return 1;
            }
        }

        // 1. Backup the tenant database before dropping (default ON — irreversible).
        if ($db && ! $this->option('no-backup')) {
            $backupPath = $this->option('backup') ?: $projectPath."/{$tenant}-commons-{$env}.sql";
            if (! $this->backupTenantDatabase($ns, $dbDriver, $db, $backupPath)) {
                $this->laraKubeError('Backup failed — aborting before any destructive change. Re-run with --no-backup to skip (dangerous).');

                return 1;
            }
            $this->line("  <fg=gray>Backed up to</> {$backupPath}");
        }

        // 2. Drop the tenant's database + login from its Commons engine.
        if ($db && ! $this->dropTenantDatabase($ns, $dbDriver, $db, $tenant)) {
            return 1;
        }

        // 3. Flush the tenant's Redis logical DB (best-effort — index is freed by
        //    the registry removal regardless).
        if ($redisIndex !== null) {
            $this->withSpin("Flushing Redis db {$redisIndex}...", fn () => passthru(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns)." deploy/redis -- redis-cli -n {$redisIndex} FLUSHDB 2>/dev/null",
            ));
        }

        // 3b. Delete the tenant's S3 bucket (best-effort). The per-backend command
        //     comes from the StorageDriver enum, so this works for SeaweedFS/MinIO.
        $s3Driver = StorageDriver::tryFrom($s3Service);
        if ($s3Bucket && $s3Driver !== null) {
            $cmd = $s3Driver->commonsBucketDeleteCommand($s3Bucket);
            $this->withSpin("Deleting object-storage bucket '{$s3Bucket}'...", fn () => passthru(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$s3Service.' -- sh -c '.escapeshellarg($cmd).' 2>/dev/null',
            ));
        }

        // 4. Remove the tenant from the Commons registry (frees its redis index).
        $this->saveRegistry($this->registryRemove($registry, $tenant));
        $this->line("  <fg=gray>Removed</> {$tenant} <fg=gray>from the Commons registry.</>");

        // 5. Clear this env's plex + managed markers for the Commons services, so
        //    heal/regenerate treats the app as self-hosted again (migrate-off path).
        $this->clearPlexMarkers($projectPath, $config, $env);

        $this->printNext($env, $appName);

        return 0;
    }

    /**
     * Whether the app still has deployments in its env namespace (a hint to tear
     * the app down before dropping the data it points at).
     */
    protected function appStillDeployed(ConfigData $config, string $env): bool
    {
        return trim((string) shell_exec(
            $this->plexKubectl().' get deploy -n '.escapeshellarg($config->getNamespace($env)).' -o name 2>/dev/null',
        )) !== '';
    }

    /**
     * Dump the tenant database to a local file using the engine's own tool
     * (pg_dump / mysqldump, from the driver). Returns false (and writes no
     * destructive change) if the dump fails or is empty.
     */
    protected function backupTenantDatabase(string $ns, DatabaseDriver $driver, string $db, string $path): bool
    {
        $service = $driver->value;
        $cmd = $driver->commonsBackupCommand($db);
        $code = 0;
        $this->withSpin("Backing up database '{$db}'...", function () use ($ns, $service, $cmd, $path, &$code) {
            exec(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($cmd).' > '.escapeshellarg($path).' 2>/dev/null',
                $o,
                $code,
            );

            return $code === 0;
        });

        return $code === 0 && file_exists($path) && filesize($path) > 0;
    }

    /**
     * Run the engine's drop SQL (DROP DATABASE + DROP login) in the Commons via
     * kubectl exec. SQL + admin client come from the DatabaseDriver enum.
     */
    protected function dropTenantDatabase(string $ns, DatabaseDriver $driver, string $db, string $tenant): bool
    {
        $sql = $driver->commonsDropSql($db, $tenant);
        if ($sql === null) {
            return true; // non-relational engine — nothing to drop.
        }

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop');
        file_put_contents($tmp, $sql);

        $service = $driver->value;
        $client = $driver->commonsAdminClient();
        $output = [];
        $code = 0;
        $this->withSpin("Dropping database '{$db}' and login '{$tenant}'...", function () use ($ns, $service, $client, $tmp, &$output, &$code) {
            exec(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($client).' < '.escapeshellarg($tmp).' 2>&1',
                $output,
                $code,
            );

            return $code === 0;
        });

        @unlink($tmp);

        if ($code !== 0) {
            $this->laraKubeError('Could not drop the tenant database/login from the Commons.');
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        return true;
    }

    /**
     * Drop the env's plex marker and remove the Commons services from its managed
     * list, so a later heal/regenerate stops treating them as Commons-backed.
     */
    protected function clearPlexMarkers(string $projectPath, ConfigData $config, string $env): void
    {
        $plexServices = $config->getPlex($env);
        if (empty($plexServices)) {
            return;
        }

        $data = $config->toArray();
        $data['environments'][$env]['managed'] = array_values(array_diff(
            $data['environments'][$env]['managed'] ?? [],
            $plexServices,
        ));
        $data['environments'][$env]['plex'] = [];
        ConfigData::from($data)->saveToFile($projectPath);

        $this->line('  <fg=gray>Cleared plex/managed markers in .larakube.json for:</> '.implode(', ', $plexServices));
    }

    protected function printNext(string $env, string $appName): void
    {
        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ '{$appName}' has left the Commons.");
        $this->line('  Next:');
        $this->line('    • <fg=yellow>git add .larakube.json && git commit</> (managed/plex markers changed)');
        $this->line("    • Decommissioning? <fg=yellow>larakube down {$env}</> (if you haven't already)");
        $this->line('    • Staying? Re-add your own DB, then <fg=yellow>larakube heal</> + redeploy — the app is self-hosted again.');
    }
}
