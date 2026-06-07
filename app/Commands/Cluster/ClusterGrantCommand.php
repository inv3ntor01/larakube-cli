<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\InteractsWithTeammateRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesNamespaceArg;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ClusterGrantCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithScopedRbac, InteractsWithTeammateRbac, LaraKubeOutput, ResolvesNamespaceArg;

    protected $signature = 'cluster:grant
        {namespace : The <app>-<env> namespace (or an env name in-project) to grant access to}
        {--name= : The teammate (their identity — reused across apps)}
        {--read : Read-only (view): logs + status, no exec/secrets}
        {--edit : Operate the app (edit) — the DEFAULT}
        {--admin : Namespace-admin (edit + manage access within the namespace)}
        {--context= : Target a specific kube-context (defaults to your current one)}';

    protected $description = 'Grant a teammate scoped access to a namespace (re-run to upgrade/downgrade their role or add another app)';

    public function handle(): int
    {
        $this->renderHeader();

        $appNs = $this->resolveNamespaceArg((string) $this->argument('namespace'));
        $name = (string) ($this->option('name') ?: text(label: 'Teammate name', placeholder: 'lloyd', required: true));
        $sa = $this->teammateSaName($name);

        if ($sa === '') {
            $this->laraKubeError('Could not derive a valid identity from that name.');

            return 1;
        }

        $adminContext = $this->option('context') ?: trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        if ($adminContext === '') {
            $this->laraKubeError('No kube-context. Pass --context or set a current context.');

            return 1;
        }

        if (! $this->kubectlSupportsTokens()) {
            $this->laraKubeError('kubectl >= 1.24 is required to mint a token. Please upgrade kubectl.');

            return 1;
        }

        $role = $this->resolveAccessRole();
        $accessNs = $this->accessNamespace();
        $ctx = escapeshellarg($adminContext);

        $this->laraKubeInfo("Granting '{$name}' [{$role}] on '{$appNs}'...");

        // 1. Identity — namespace + SA + bound-token Secret (idempotent; an
        //    existing teammate keeps the same token).
        if (! $this->applyManifest($adminContext, $this->teammateIdentityManifest($accessNs, $sa, $name))) {
            $this->laraKubeError('Failed to create the teammate identity.');

            return 1;
        }

        // 2. RoleBinding in the app namespace. roleRef is immutable, so to support
        //    upgrade/downgrade we delete any existing binding for this user first,
        //    then recreate with the chosen role.
        shell_exec("kubectl --context {$ctx} -n ".escapeshellarg($appNs).' delete rolebinding '.escapeshellarg($this->teammateBindingName($sa)).' --ignore-not-found 2>/dev/null');
        if (! $this->applyManifest($adminContext, $this->teammateBindingManifest($appNs, $accessNs, $sa, $role))) {
            $this->laraKubeError("Failed to bind access in '{$appNs}'.");

            return 1;
        }

        // 3. Token + CA + server → a teammate kubeconfig.
        $token = $this->pollSecretToken($adminContext, $accessNs, $sa.'-token');
        $server = trim((string) shell_exec($this->clusterServerCommand($adminContext).' 2>/dev/null'));
        $ca = $this->readSecretCaData($adminContext, $accessNs, $sa.'-token');

        if ($token === null || $token === '' || $server === '' || $ca === '') {
            $this->laraKubeError('Could not mint the teammate token (Secret never populated, or server/CA unreadable).');

            return 1;
        }

        $contextName = $this->teammateContextName($server);
        $kubeconfig = $this->assembleTeammateKubeconfig($contextName, $server, $ca, $appNs, $token, $sa);

        $file = getcwd().'/'.$sa.'.kubeconfig';
        file_put_contents($file, $kubeconfig);
        @chmod($file, 0600);

        $this->laraKubeInfo("✅ Granted '{$name}' [{$role}] on '{$appNs}'.");
        $this->line("  <fg=gray>Identity:</> {$accessNs}/{$sa}  <fg=gray>· context they'll see:</> <fg=cyan>{$contextName}</>");
        $this->line('  <fg=gray>Kubeconfig:</> <fg=cyan>'.$file.'</> <fg=gray>(0600)</>');
        $this->laraKubeWarn('Deliver this file SECURELY — not committed, not pasted in chat.');
        $this->line('  They run: <fg=yellow>larakube context:import '.basename($file).'</>');
        $this->laraKubeNewLine();
        $this->line("  <fg=gray>To add another app later:</> <fg=yellow>larakube cluster:grant <other-ns> --name {$name}</> <fg=gray>(same identity — no new file).</>");

        return 0;
    }

    /**
     * Resolve the access level. An explicit --read/--edit/--admin flag always
     * wins. Otherwise ask (rather than silently granting write access) — falling
     * back to the documented `edit` default when non-interactive (e.g. CI).
     */
    protected function resolveAccessRole(): string
    {
        if ($this->option('read') || $this->option('edit') || $this->option('admin')) {
            return $this->presetClusterRole((bool) $this->option('read'), (bool) $this->option('edit'), (bool) $this->option('admin'));
        }

        if ($this->option('no-interaction')) {
            return 'edit';
        }

        return select(
            label: 'Access level',
            options: [
                'view' => 'Read-only — logs + status (no exec, no secrets)',
                'edit' => 'Operate the app — edit (default)',
                'admin' => 'Namespace-admin — edit + manage access within the namespace',
            ],
            default: 'edit',
        );
    }

    protected function applyManifest(string $adminContext, string $manifest): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'lk_grant_');
        file_put_contents($file, $manifest);
        exec('kubectl --context '.escapeshellarg($adminContext).' apply -f '.escapeshellarg($file).' 2>&1', $out, $code);
        @unlink($file);

        return $code === 0;
    }
}
