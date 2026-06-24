<?php

namespace App\Commands\Plex;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class PlexMigrateCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:migrate
        {environment=production : The environment whose database to migrate to the Commons}
        {--keep-pvc : Keep the self-hosted PVC after migration (don\'t delete it)}
        {--yes : Skip confirmation prompts}';

    protected $description = 'Copy this project\'s self-hosted database into the shared Commons, then join';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube CLI Plex — Migrate data to the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');

        // Only relational drivers have data to migrate
        $driver = $config->getDatabase();

        if ($driver === null || $driver === DatabaseDriver::SQLITE) {
            $this->laraKubeError('plex:migrate only applies to relational databases (MySQL, MariaDB, PostgreSQL).');

            return 1;
        }

        $dbService = $driver->commonsServiceName();

        if ($dbService === null) {
            $this->laraKubeError("'{$driver->getLabel()}' cannot be shared via the Commons.");

            return 1;
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName, $env);
        $namespace = $config->getNamespace($env);
        $pvc = $appName.'-'.$dbService.'-pvc';

        // ── Resolve context ───────────────────────────────────────────────────

        if ($env === 'local') {
            $context = null;
        } else {
            [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);

            if (! $context) {
                $this->laraKubeError("No deploy target for '{$env}'. Run `larakube cloud:provision` first.");

                return 1;
            }
        }

        $this->plexContext = $context;

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('Cluster is unreachable. Check the server or re-run cloud:provision.');

            return 1;
        }

        // ── Verify source pod is running ──────────────────────────────────────

        $kubectl = $this->plexContext !== null
            ? 'kubectl --context '.escapeshellarg($this->plexContext)
            : 'kubectl';

        $selfHostedKubectl = ($env === 'local')
            ? 'kubectl'
            : 'kubectl --context '.escapeshellarg((string) $context);

        $podExists = trim((string) shell_exec(
            $selfHostedKubectl.' get deploy '.escapeshellarg($driver->value).
            ' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';

        if (! $podExists) {
            $this->laraKubeError("No '{$driver->value}' deployment found in '{$namespace}'.");
            $this->laraKubeLine('  Run `larakube up` (or deploy) first so the source database is running.');

            return 1;
        }

        $pvcExists = trim((string) shell_exec(
            $selfHostedKubectl.' get pvc '.escapeshellarg($pvc).
            ' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';

        // ── Confirm ───────────────────────────────────────────────────────────

        $this->newLine();
        $this->laraKubeLine("  <fg=gray>Source:</> <fg=cyan>{$driver->getLabel()}</> in <fg=cyan>{$namespace}</>");
        $this->laraKubeLine("  <fg=gray>Target:</> Commons {$driver->getLabel()} in <fg=cyan>larakube-shared</> (tenant: <fg=cyan>{$tenant}</>)");
        $this->newLine();
        $this->laraKubeWarn('This will COPY data from the self-hosted pod to the Commons. The original pod stays running until you approve deletion.');

        if (! $this->option('yes') && ! confirm('Proceed with migration?', false)) {
            return 0;
        }

        // ── Step 1: Ensure Commons ─────────────────────────────────────────────

        if (! $this->ensureCommons([$dbService])) {
            return 1;
        }

        // ── Step 2: Allocate tenant DB in Commons ─────────────────────────────
        // Use a temporary password; plex:join --yes (called at the end) will
        // re-run the idempotent SQL with a fresh password and save it to .env.

        $tmpPassword = bin2hex(random_bytes(16));

        if (! $this->allocateDatabase($driver, $tenant, $tmpPassword)) {
            return 1;
        }

        // ── Step 3: Dump from self-hosted pod ─────────────────────────────────

        $dumpFile = tempnam(sys_get_temp_dir(), 'larakube_plex_dump');
        $dumpCmd = $driver->selfHostedDumpCommand();
        $dumpOutput = [];
        $dumpCode = 0;

        $this->withSpin('Dumping data from self-hosted database...', function () use ($selfHostedKubectl, $namespace, $driver, $dumpCmd, $dumpFile, &$dumpOutput, &$dumpCode) {
            exec(
                $selfHostedKubectl.' exec -n '.escapeshellarg($namespace).' deploy/'.escapeshellarg($driver->value).
                ' -- sh -c '.escapeshellarg($dumpCmd).
                ' > '.escapeshellarg($dumpFile).' 2>/dev/null',
                $dumpOutput,
                $dumpCode,
            );

            return $dumpCode === 0;
        });

        if ($dumpCode !== 0 || ! file_exists($dumpFile) || filesize($dumpFile) === 0) {
            @unlink($dumpFile);
            $this->laraKubeError("Dump from self-hosted {$driver->getLabel()} failed.");

            return 1;
        }

        $dumpSize = number_format(filesize($dumpFile) / 1024, 1).' KB';
        $this->line("  <fg=gray>Dump size:</> {$dumpSize}");

        // ── Step 4: Restore to Commons ────────────────────────────────────────

        $restoreCmd = $driver->commonsRestoreCommand($tenant);
        $restoreOutput = [];
        $restoreCode = 0;
        $ns = $this->plexNamespace();

        $this->withSpin("Restoring data into Commons tenant '{$tenant}'...", function () use ($ns, $driver, $restoreCmd, $dumpFile, &$restoreOutput, &$restoreCode) {
            exec(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$driver->value.
                ' -- sh -c '.escapeshellarg($restoreCmd).
                ' < '.escapeshellarg($dumpFile).' 2>&1',
                $restoreOutput,
                $restoreCode,
            );

            return $restoreCode === 0;
        });

        @unlink($dumpFile);

        if ($restoreCode !== 0) {
            $this->laraKubeError("Restore into Commons {$driver->getLabel()} failed.");
            foreach (array_slice($restoreOutput, -6) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return 1;
        }

        $this->laraKubeInfo('Data migrated successfully.');

        // ── Step 5: Mark managed so plex:join guard passes ────────────────────

        $this->markServiceMigrated($projectPath, $config, $env, $dbService);

        // ── Step 6: Optional PVC deletion ─────────────────────────────────────

        if ($pvcExists && ! $this->option('keep-pvc')) {
            $this->newLine();
            $this->laraKubeWarn("PVC '{$pvc}' in '{$namespace}' still holds the old data.");

            if ($this->option('yes') || confirm('Delete the self-hosted PVC now? (data is safely in the Commons)', false)) {
                shell_exec($selfHostedKubectl.' delete pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' 2>/dev/null');
                $this->line('  <fg=gray>PVC deleted.</>');
            } else {
                $this->line("  <fg=gray>PVC kept. Delete it manually when you've verified the Commons data:</>  <fg=yellow>kubectl delete pvc {$pvc} -n {$namespace}</>");
            }
        }

        // ── Step 7: Complete the join (allocation + .env config + heal) ───────

        $this->newLine();
        $this->laraKubeInfo('Running plex:join to finalise credentials and manifests…');
        $this->newLine();

        $joinCode = $this->call('plex:join', [
            'environment' => $env,
            '--yes' => true,
        ]);

        if ($joinCode !== 0) {
            $this->laraKubeWarn('plex:join did not complete cleanly. Run `larakube plex:join '.$env.' --yes` manually.');
        }

        return $joinCode;
    }
}
