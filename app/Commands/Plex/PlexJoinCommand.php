<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class PlexJoinCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:join
        {environment=production : The cloud environment to join to the Commons}
        {--rotate : Reset this tenant\'s Commons credentials}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Join this project to the shared Commons as a Tenant';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Join the Commons');

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
            $this->laraKubeError('Plex is a cloud topology — local stays per-project. Pick a cloud environment.');

            return 1;
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName);

        // 1. Which of this app's services are Commons-eligible?
        $services = $this->resolveTenantServices($config);

        if (empty($services)) {
            $this->laraKubeError('No Commons-eligible services found. Plex shares Postgres and/or Redis;');
            $this->laraKubeLine('  this app declares neither (SQLite / Memcached / database-cache are not shared).');

            return 1;
        }

        // 2. Target the environment's OWN context (never the current/global one) —
        //    recording the deploy target once if it isn't saved yet. No switching.
        [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);

        if (! $context) {
            $this->laraKubeError("No deploy target for '{$env}'. Run `larakube cloud:provision` (or set environments.{$env}.cloud).");

            return 1;
        }

        $this->plexContext = $context;

        if (! $this->environmentContextReachable($context)) {
            $this->laraKubeError("Cluster context '{$context}' is unreachable. Check the server / re-run cloud:provision.");

            return 1;
        }

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>{$context}</>");
        $this->line('  <fg=gray>Services:</> '.implode(', ', $services));
        $this->laraKubeNewLine();

        if (! $this->option('yes') && ! confirm("Join '{$tenant}' to the Commons?", true)) {
            $this->laraKubeInfo('Aborted.');

            return 0;
        }

        // 3. Existing-data guard: never silently strand a self-hosted DB.
        if (! $this->guardExistingData($config, $env, $services)) {
            return 1;
        }

        // 4. Ensure the Commons exists and offers what we need.
        if (! $this->ensureCommons($services)) {
            return 1;
        }

        // 5. Allocate this tenant in the Commons.
        $registry = $this->getRegistry();

        if (isset($registry['tenants'][$tenant]) && ! $this->option('rotate')) {
            $this->laraKubeInfo("'{$tenant}' is already a tenant. Use --rotate to reset its credentials.");

            return 0;
        }

        $redisIndex = null;
        if (in_array('redis', $services, true)) {
            $redisIndex = $registry['tenants'][$tenant]['redis_index']
                ?? $this->allocateRedisDbIndex($this->registryUsedRedisIndexes($registry));

            if ($redisIndex === null) {
                $this->laraKubeError('The Commons Redis is full (16 logical DBs). Add a tenant Redis ACL or a bigger plan.');

                return 1;
            }
        }

        $password = bin2hex(random_bytes(16));

        if (in_array('postgres', $services, true) && ! $this->allocatePostgres($tenant, $password)) {
            return 1;
        }

        // 6. Record the allocation (db + redis index; never the password).
        $registry = $this->registryAdd($registry, $tenant, ['db' => $tenant, 'redis_index' => $redisIndex]);
        $this->saveRegistry($registry);

        // 7. Write tenant config (.env + managed).
        $this->writeTenantConfig($projectPath, $config, $env, $tenant, $password, $redisIndex, $services);

        $this->printNext($env);

        return 0;
    }

    /**
     * Map the app's declared drivers to Commons service names. MVP: Postgres +
     * Redis (their enum values are 'postgres' / 'redis', which match both the
     * Commons service name and the `managed` value the deploy-skip checks).
     *
     * @return array<int, string>
     */
    protected function resolveTenantServices(ConfigData $config): array
    {
        $services = [];

        $db = $config->getDatabase();
        if ($db?->value === 'postgres') {
            $services[] = 'postgres';
        } elseif ($db && in_array($db->value, ['mysql', 'mariadb'], true)) {
            $this->laraKubeWarn("Commons join for {$db->value} is not implemented yet (Postgres only in this phase) — skipping the database.");
        }

        if ($config->getCacheDriver()->value === 'redis') {
            $services[] = 'redis';
        }

        return $services;
    }

    /**
     * Refuse to cut over an app that still self-hosts a service holding data —
     * joining would point it at an empty Commons DB. (Migration is plex:migrate.)
     */
    protected function guardExistingData(ConfigData $config, string $env, array $services): bool
    {
        if (! in_array('postgres', $services, true) || in_array('postgres', $config->getManaged($env), true)) {
            return true; // not managing postgres here, or already managed → nothing to strand.
        }

        $namespace = $config->getNamespace($env);
        $pvc = $config->getName().'-postgres-pvc';
        $exists = trim((string) shell_exec(
            $this->plexKubectl().' get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';

        if ($exists) {
            $this->laraKubeError("This app still runs its own Postgres in '{$namespace}' (PVC {$pvc}).");
            $this->laraKubeLine('  Joining now would point it at an EMPTY Commons database and strand that data.');
            $this->laraKubeLine('  Migrate the data first (plex:migrate — see the guide §1e), or keep it on its own');
            $this->laraKubeLine('  Postgres (mixed mode) and only join Redis.');

            return false;
        }

        return true;
    }

    /**
     * Ensure a Commons exists (offer to bootstrap) and that it offers every
     * service this tenant needs.
     */
    protected function ensureCommons(array $services): bool
    {
        $spec = $this->getCommonsSpec();

        if ($spec === null) {
            if (! $this->option('yes') && ! confirm('No Commons on this cluster yet. Create one now?', true)) {
                $this->laraKubeError('A Commons is required to join. Run `larakube plex:init` first.');

                return false;
            }

            $this->call('plex:init', ['--yes' => true]);
            $spec = $this->getCommonsSpec();

            if ($spec === null) {
                $this->laraKubeError('Commons bootstrap failed. Run `larakube plex:init` and retry.');

                return false;
            }
        }

        $offered = $this->enabledCommonsServices($spec);
        $missing = array_diff($services, $offered);

        if (! empty($missing)) {
            $this->laraKubeError('The Commons does not offer: '.implode(', ', $missing).'.');
            $this->laraKubeLine('  Re-run `larakube plex:init` to add it, then join again.');

            return false;
        }

        return true;
    }

    /**
     * Create/refresh this tenant's database + role in the Commons Postgres via
     * `kubectl exec` (peer auth inside the pod — no password needed).
     */
    protected function allocatePostgres(string $tenant, string $password): bool
    {
        $ns = $this->plexNamespace();
        $sql = $this->buildPostgresTenantSql($tenant, $tenant, $password);

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_sql');
        file_put_contents($tmp, $sql);

        $output = [];
        $code = 0;
        $this->withSpin("Allocating database '{$tenant}' in the Commons...", function () use ($ns, $tmp, &$output, &$code) {
            exec(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/postgres -- '.
                'psql -U postgres -v ON_ERROR_STOP=1 < '.escapeshellarg($tmp).' 2>&1',
                $output,
                $code,
            );

            return $code === 0;
        });

        @unlink($tmp);

        if ($code !== 0) {
            $this->laraKubeError('Could not allocate the tenant database in the Commons Postgres.');
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        return true;
    }

    /**
     * Write the Commons connection values into .env.{env} (lock-aware) and add
     * the services to environments.{env}.managed so the app stops deploying its
     * own pods.
     */
    protected function writeTenantConfig(string $projectPath, ConfigData $config, string $env, string $tenant, string $password, ?int $redisIndex, array $services): void
    {
        $values = $this->commonsEnvValues($tenant, $password, $redisIndex, $services);
        $envFile = $env === 'production' ? '.env.production' : ".env.{$env}";

        if ($config->isLocked($envFile)) {
            $this->laraKubeWarn("{$envFile} is locked — add these manually:");
            foreach ($values as $key => $value) {
                $this->laraKubeLine("    {$key}={$value}");
            }
        } else {
            $envPath = $projectPath.'/'.$envFile;
            $content = file_exists($envPath)
                ? (string) file_get_contents($envPath)
                : (file_exists($projectPath.'/.env') ? (string) file_get_contents($projectPath.'/.env') : '');
            file_put_contents($envPath, $this->applyEnvValues($content, $values));
            $this->line("  <fg=gray>Wrote Commons connection to</> {$envFile}");
        }

        // environments.{env}.managed += services (so the deploy skips their pods),
        // and .plex += services so env-sync (heal/regenerate) never recomputes
        // their connection and clobbers the Commons values we just wrote to .env.
        $data = $config->toArray();
        $data['environments'][$env]['managed'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['managed'] ?? [],
            $services,
        )));
        $data['environments'][$env]['plex'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['plex'] ?? [],
            $services,
        )));
        ConfigData::from($data)->saveToFile($projectPath);
        $this->line('  <fg=gray>Marked managed + plex in .larakube.json:</> '.implode(', ', $services));
    }

    protected function printNext(string $env): void
    {
        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Joined the Commons.');
        $this->line('  Next:');
        $this->line('    1. <fg=yellow>git add .larakube.json && git commit</> (the managed change is config)');
        $this->line("    2. <fg=yellow>larakube gha:configure {$env}</> (re-upload the .env.{$env} secret)");
        $this->line('    3. Deploy as usual — the app now uses the Commons, not its own pods.');
    }
}
