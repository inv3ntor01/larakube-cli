<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\LaravelFeature;

use function Laravel\Prompts\confirm;

/**
 * Preflight guard for the multi-node shared-storage trap.
 *
 * LaraKube's app pods (web + horizon/queues/scheduler/reverb) share ONE
 * ReadWriteOnce app-storage PVC, and SQLite keeps its database on a PVC too.
 * That's fine on a single node — every pod co-locates — but on a multi-node
 * cluster the pods spread across nodes and the second one can't mount the RWO
 * volume, so it hangs in Pending. Block storage (do-block-storage, EBS, GCE PD,
 * Azure Disk) is RWO-only, so this is architectural, not a storageClass tweak.
 *
 * Until the full "stateless app pods" solution lands, we FAIL LOUD here rather
 * than generate manifests that silently break. The using class must also use
 * LaraKubeOutput, and (for the override prompt) be a Console command.
 */
trait GuardsSharedStorage
{
    /**
     * Returns true to proceed, false to abort.
     *
     * @param  string|null  $context  Cluster context to probe node count (deploy
     *                                time). Null (e.g. heal) → judge by strategy.
     */
    protected function guardSharedStorage(ConfigData $config, string $environment, ?string $context = null): bool
    {
        if (! $this->isMultiNode($config, $environment, $context)) {
            return true;
        }

        // SQLite can't span nodes — its DB file lives on an RWO volume. Hard case.
        if ($config->hasDatabase(DatabaseDriver::SQLITE)) {
            $this->laraKubeWarn("⚠ Multi-node + SQLite — '{$environment}'");
            $this->line('  SQLite stores its database in a file on a ReadWriteOnce volume, which cannot be');
            $this->line('  shared across nodes. This environment will not work multi-node as-is.');
            $this->newLine();
            $this->line('  <fg=green>Fix:</> use a networked database (Postgres / MySQL / MariaDB) for multi-node,');
            $this->line('  or set this env to <fg=yellow>strategy: single-node</> on a 1-node pool.');
            $this->newLine();

            return $this->allowStorageOverride();
        }

        // Multiple app pods sharing the RWO app-storage PVC (web + workers).
        $workers = $this->sharedStorageWorkers($config, $environment);
        if ($workers === []) {
            return true; // a lone web pod mounts the RWO volume on its one node — fine
        }

        $this->laraKubeWarn("⚠ Multi-node + shared storage — '{$environment}'");
        $this->line('  web + '.implode(', ', $workers).' share one ReadWriteOnce volume (storage/,');
        $this->line('  bootstrap/cache). Across nodes only one node can mount it; the others hang in');
        $this->line('  Pending — block storage is ReadWriteOnce-only.');
        $this->newLine();
        $this->line('  <fg=green>Pick one:</>');
        $this->line('   • <fg=yellow>Externalize state</> — uploads→S3, cache/sessions→the database or Redis.');
        $this->line('     `larakube plex:join` provides S3 (+Redis); a future release stops mounting the');
        $this->line('     shared volume on multi-node so pods spread freely. (No Redis required — the DB');
        $this->line('     session/cache driver works across nodes.)');
        $this->line('   • <fg=yellow>Stay single-node</> for now — set this env to `strategy: single-node` + a 1-node pool.');
        $this->newLine();

        return $this->allowStorageOverride();
    }

    /** Multi-node when the strategy says so, or a probed cluster has more than one node. */
    protected function isMultiNode(ConfigData $config, string $environment, ?string $context): bool
    {
        if ($config->getStrategy($environment) === DeploymentStrategy::MULTI_NODE_HA) {
            return true;
        }

        return $context !== null && $context !== '' && $this->clusterNodeCount($context) > 1;
    }

    /**
     * Worker features enabled in this env that mount the shared app-storage PVC.
     *
     * @return array<int, string>
     */
    protected function sharedStorageWorkers(ConfigData $config, string $environment): array
    {
        $sharePvc = [LaravelFeature::HORIZON, LaravelFeature::QUEUES, LaravelFeature::TASK_SCHEDULING, LaravelFeature::REVERB];
        $enabled = $config->getFeatures($environment);

        return array_values(array_map(
            fn (LaravelFeature $f): string => $f->value,
            array_filter($sharePvc, fn (LaravelFeature $f): bool => in_array($f, $enabled, true)),
        ));
    }

    /** Node count for a context (0 when unreachable / unknown). */
    protected function clusterNodeCount(string $context): int
    {
        $out = trim((string) shell_exec(
            'kubectl --context '.escapeshellarg($context).' get nodes -o jsonpath='.escapeshellarg('{.items[*].metadata.name}').' 2>/dev/null',
        ));

        return $out === '' ? 0 : count(preg_split('/\s+/', $out) ?: []);
    }

    /**
     * Honor --force / --no-interaction, else ask. The using command must define a
     * `--force` option (cloud:deploy, heal do).
     */
    protected function allowStorageOverride(): bool
    {
        if ($this->option('force')) {
            $this->laraKubeWarn('Proceeding anyway (--force) — expect Pending pods until state is externalized.');

            return true;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Aborting (multi-node + shared RWO storage). Re-run with --force to override.');

            return false;
        }

        return confirm('Proceed anyway? Pods may hang in Pending.', default: false);
    }
}
