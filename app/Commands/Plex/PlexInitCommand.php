<?php

namespace App\Commands\Plex;

use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class PlexInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'plex:init
        {--services= : Comma-separated services to provision non-interactively, e.g. postgres,redis,meilisearch (no prompt; nothing assumed)}
        {--context= : Target a specific kube-context non-interactively (else you are prompted)}
        {--from= : Rebuild the Commons from an exported spec file (see plex:export)}';

    protected $description = 'Provision the shared "Commons" (Postgres + Redis) on the current cluster';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Installer');

        // Target the cluster directly (no context switching) — every Commons op
        // below runs through plexKubectl() against it. An explicit --context wins;
        // otherwise we pick interactively, unless --services makes this run
        // non-interactive (then we stay on the current context).
        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($this->option('services') === null) {
            $target = $this->askForClusterContext();

            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }

            $this->plexContext = $target;
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable. Pick a running cluster and retry.');

            return 1;
        }

        $context = $this->plexContext ?: trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        // 1. Resolve the spec: imported file > existing cluster spec > defaults.
        $spec = $this->resolveSpec();

        if ($spec === null) {
            return 1;
        }

        $ns = $this->plexNamespace();
        $enabled = $this->enabledCommonsServices($spec);

        // 2. Namespace (idempotent).
        $kubectl = $this->plexKubectl();
        $this->withSpin("Ensuring namespace {$ns}...", fn () => shell_exec(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // 3. Admin credentials — generated once, reused on re-run (never rotated).
        $this->ensureCommonsSecret();

        // 4. Render + apply the Commons manifest (spec ConfigMap + services).
        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => $this->indentedSpecJson($spec),
        ])->render();

        $this->withSpin('Applying Commons manifests...', function () use ($manifest, $ns, $kubectl) {
            $tmp = sys_get_temp_dir().'/larakube-plex-commons.yaml';
            file_put_contents($tmp, $manifest);
            passthru("{$kubectl} apply -n {$ns} -f {$tmp}");
            @unlink($tmp);

            return true;
        });

        // 5. Tenant registry — create once, declaratively (so later `apply`s don't
        //    warn about a missing last-applied-configuration), never overwrite.
        if (trim((string) shell_exec("{$kubectl} get configmap plex-registry -n {$ns} -o name 2>/dev/null")) === '') {
            $this->saveRegistry([]);
        }

        // 6. Wait for each enabled service to roll out.
        foreach ($enabled as $service) {
            $this->withSpin("Waiting for {$service} to be ready...", fn () => passthru(
                "{$kubectl} rollout status deploy/{$service} -n {$ns} --timeout=120s",
            ));
        }

        $this->printCommonsReady($spec);

        return 0;
    }

    /**
     * Resolve the spec to apply: an imported file, else the existing cluster
     * spec (reconcile), else fresh defaults.
     */
    protected function resolveSpec(): ?array
    {
        $from = $this->option('from');

        if ($from) {
            if (! file_exists($from)) {
                $this->laraKubeError("Spec file not found: {$from}");

                return null;
            }

            $decoded = json_decode((string) file_get_contents($from), true);

            if (! is_array($decoded)) {
                $this->laraKubeError("Could not parse spec file as JSON: {$from}");

                return null;
            }

            $this->line("  <fg=gray>Rebuilding from spec:</> {$from}");

            return $this->normalizeCommonsSpec($decoded);
        }

        // The catalog (PlexProvisionable) is the source of truth for what can be
        // offered; only plex-ready services are selectable.
        $catalog = $this->commonsServiceCatalog();
        $ready = array_keys(array_filter($catalog, fn ($i) => $i['ready']));

        // An existing Commons re-runs as RECONCILE: pre-select its current
        // services so you can ADD more. A fresh one defaults to the project's
        // plex-ready services (project-aware), else Postgres + Redis.
        $existing = $this->getCommonsSpec();
        $current = $existing !== null ? $this->enabledCommonsServices($existing) : [];
        $default = $existing !== null ? $current : $this->projectDefaultServices($ready);

        // plex:init is ADDITIVE on an existing Commons — re-running never disables
        // a running service (removal isn't wired here): union(current, picked).
        $finalize = fn (array $picked): array => $this->specFromServices(
            array_values(array_unique(array_merge($current, array_intersect($picked, $ready)))),
            $ready,
            $existing,
        );

        // Explicit --services (plex:join's demand-driven bootstrap) is the
        // non-interactive path — exactly what's listed, nothing assumed.
        if ($this->option('services') !== null) {
            return $finalize(array_filter(array_map('trim', explode(',', (string) $this->option('services')))));
        }

        // Show the WHOLE catalog (databases, cache, search, storage), marking the
        // not-yet-wired ones; only ready picks take effect.
        $options = [];
        foreach ($catalog as $service => $info) {
            $options[$service] = $info['ready'] ? $info['label'] : $info['label'].' — not yet available';
        }

        $selected = multiselect(
            label: $existing !== null
                ? 'Which services should the Commons provide? (current ones stay)'
                : 'Which shared services should the Commons provide?',
            options: $options,
            default: array_values(array_intersect($default, array_keys($options))),
            hint: 'Re-running plex:init adds services; it never removes a running one.',
        );

        if ($skipped = array_diff($selected, $ready)) {
            $this->laraKubeWarn('Not available in the Commons yet, skipping: '.implode(', ', $skipped));
        }

        return $finalize($selected);
    }

    /**
     * Default service selection: the current project's plex-ready services when
     * run inside a project (project-aware), else Postgres + Redis.
     *
     * @param  array<int, string>  $ready
     * @return array<int, string>
     */
    protected function projectDefaultServices(array $ready): array
    {
        $config = $this->getProjectConfig(getcwd());

        if ($config !== null && ($services = $this->projectCommonsServices($config))) {
            return $services;
        }

        return array_values(array_intersect(['postgres', 'redis'], $ready));
    }

    /**
     * Build a normalized spec with exactly $selected enabled. Merges onto an
     * existing spec when given (preserving each service's customised
     * image/storage), flipping only the enabled flag for every ready service.
     *
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $ready
     * @param  array<string, mixed>|null  $existing
     */
    protected function specFromServices(array $selected, array $ready, ?array $existing = null): array
    {
        $services = $existing['services'] ?? [];

        foreach ($ready as $svc) {
            $services[$svc] = array_merge($services[$svc] ?? [], ['enabled' => in_array($svc, $selected, true)]);
        }

        return $this->normalizeCommonsSpec([
            'version' => $existing['version'] ?? 1,
            'services' => $services,
        ]);
    }

    /**
     * Ensure the Commons admin Secret (Postgres password + Meili master key)
     * exists. Generated once; left untouched on re-run so the password is stable.
     */
    protected function ensureCommonsSecret(): void
    {
        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();

        $exists = trim((string) shell_exec(
            "{$kubectl} get secret plex-admin -n {$ns} -o name 2>/dev/null",
        )) !== '';

        if ($exists) {
            return;
        }

        $postgresPassword = bin2hex(random_bytes(16));
        $meiliMasterKey = bin2hex(random_bytes(16));
        // Shared Commons S3 credentials (one key, bucket-per-tenant isolation).
        $s3SecretKey = bin2hex(random_bytes(16));

        shell_exec(
            "{$kubectl} create secret generic plex-admin -n {$ns} ".
            '--from-literal=POSTGRES_PASSWORD='.escapeshellarg($postgresPassword).' '.
            '--from-literal=MEILI_MASTER_KEY='.escapeshellarg($meiliMasterKey).' '.
            '--from-literal=S3_ACCESS_KEY='.escapeshellarg('larakube').' '.
            '--from-literal=S3_SECRET_KEY='.escapeshellarg($s3SecretKey).
            " --dry-run=client -o yaml | {$kubectl} apply -f -",
        );
    }

    /**
     * Pretty-print the spec as JSON, indented for a YAML block scalar.
     */
    protected function indentedSpecJson(array $spec): string
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace('/^/m', '    ', $json);
    }

    /**
     * Print the in-cluster hosts tenants will point at, plus next steps.
     */
    protected function printCommonsReady(array $spec): void
    {
        $ns = $this->plexNamespace();

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Commons is ready.');
        $this->line('  Tenants reach these in-cluster hosts:');

        foreach (['postgres' => 5432, 'redis' => 6379, 'meili' => 7700] as $service => $port) {
            if ($spec['services'][$service]['enabled'] ?? false) {
                $this->line("    <fg=cyan>{$service}.{$ns}.svc.cluster.local:{$port}</>");
            }
        }

        $this->newLine();
        $this->line('  Save the spec for disaster recovery: <fg=yellow>larakube plex:export</>');
    }
}
