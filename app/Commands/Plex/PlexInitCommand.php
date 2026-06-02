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
        {--with-meili : Include Meilisearch in the Commons (RAM-heavy; off by default)}
        {--services= : Comma-separated services to provision, bypassing the prompt (used by plex:join)}
        {--from= : Rebuild the Commons from an exported spec file (see plex:export)}
        {--yes : Skip the confirmation prompt (for automation)}';

    protected $description = 'Provision the shared "Commons" (Postgres + Redis) on the current cluster';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Installer');

        // Pick the target cluster and TARGET it directly (no context switching) —
        // every Commons operation below runs through plexKubectl() against it.
        // With --yes (automation) we stay on the current context.
        if (! $this->option('yes')) {
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

        $existing = $this->getCommonsSpec();

        if ($existing !== null) {
            // Reconcile the live spec; --with-meili can light up Meili on a re-run.
            if ($this->option('with-meili')) {
                $existing['services']['meilisearch']['enabled'] = true;
            }

            return $this->normalizeCommonsSpec($existing);
        }

        // Fresh Commons. The catalog (PlexProvisionable) is the source of truth
        // for what can be offered; only plex-ready services are selectable.
        $catalog = $this->commonsServiceCatalog();
        $ready = array_keys(array_filter($catalog, fn ($i) => $i['ready']));

        // Project-aware default: inside a project, pre-select that project's
        // plex-ready services; otherwise Postgres + Redis.
        $default = $this->projectDefaultServices($ready);
        if ($this->option('with-meili')) {
            $default[] = 'meilisearch';
        }

        // Explicit --services (plex:join's demand-driven bootstrap), or --yes.
        if ($this->option('services') !== null) {
            $requested = array_filter(array_map('trim', explode(',', (string) $this->option('services'))));

            return $this->specFromServices(array_values(array_intersect($requested, $ready)), $ready);
        }

        if ($this->option('yes')) {
            return $this->specFromServices(array_values(array_intersect($default, $ready)), $ready);
        }

        // Show the WHOLE catalog (databases, cache, search, storage), marking the
        // not-yet-wired ones; only ready picks take effect.
        $options = [];
        foreach ($catalog as $service => $info) {
            $options[$service] = $info['ready'] ? $info['label'] : $info['label'].' — not yet available';
        }

        $selected = multiselect(
            label: 'Which shared services should the Commons provide?',
            options: $options,
            default: array_values(array_intersect($default, array_keys($options))),
            hint: 'Tenants use only what they declare; re-run plex:init to add more later.',
        );

        $effective = array_values(array_intersect($selected, $ready));
        if ($skipped = array_diff($selected, $effective)) {
            $this->laraKubeWarn('Not available in the Commons yet, skipping: '.implode(', ', $skipped));
        }

        return $this->specFromServices($effective, $ready);
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
     * Build a normalized spec with exactly $selected enabled (every other ready
     * service explicitly disabled, so no default silently turns one back on).
     *
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $ready
     */
    protected function specFromServices(array $selected, array $ready): array
    {
        $services = [];
        foreach ($ready as $svc) {
            $services[$svc] = ['enabled' => in_array($svc, $selected, true)];
        }

        return $this->normalizeCommonsSpec(['services' => $services]);
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

        shell_exec(
            "{$kubectl} create secret generic plex-admin -n {$ns} ".
            '--from-literal=POSTGRES_PASSWORD='.escapeshellarg($postgresPassword).' '.
            '--from-literal=MEILI_MASTER_KEY='.escapeshellarg($meiliMasterKey).
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
