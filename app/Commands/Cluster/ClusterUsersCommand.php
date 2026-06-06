<?php

namespace App\Commands\Cluster;

use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class ClusterUsersCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'cluster:users
        {namespace? : Audit one namespace\'s live deployer scope (omit to list every LaraKube deploy SA)}
        {--context= : Target a specific kube-context (defaults to your current one)}';

    protected $description = 'List the namespace-scoped deploy ServiceAccounts LaraKube created, or audit one\'s live RBAC scope';

    /** Deterministic name of the scoped deploy ServiceAccount/Role/RoleBinding. */
    private string $sa = 'deployer';

    public function handle(): int
    {
        $this->renderHeader();

        $context = $this->option('context') ?: trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $this->line('  <fg=gray>Context:</> <fg=cyan>'.($context !== '' ? $context : 'current').'</>');
        $this->laraKubeNewLine();

        $kubectl = 'kubectl'.($this->option('context') ? ' --context '.escapeshellarg((string) $this->option('context')) : '');

        $namespace = $this->argument('namespace');

        return $namespace ? $this->showScope($kubectl, $namespace) : $this->listUsers($kubectl);
    }

    /** List larakube-managed deploy SAs and teammate identities across the cluster. */
    protected function listUsers(string $kubectl): int
    {
        $items = $this->kubectlItems($kubectl.' get sa -A -l app.kubernetes.io/managed-by=larakube -o json 2>/dev/null');

        // Teammates carry an access-user label; everything else is a deploy SA.
        $deployers = [];
        $teammates = [];
        foreach ($items as $sa) {
            if (isset($sa['metadata']['labels']['larakube.dev/access-user'])) {
                $teammates[] = $sa;
            } else {
                $deployers[] = $sa;
            }
        }

        if (empty($deployers) && empty($teammates)) {
            $this->laraKubeInfo('No LaraKube ServiceAccounts on this cluster yet.');
            $this->line('  Deploy SAs come from `cloud:deploy` / `cloud:configure:gha`; teammates from `cluster:grant`.');

            return 0;
        }

        if (! empty($deployers)) {
            $rows = [];
            foreach ($deployers as $sa) {
                $ns = $sa['metadata']['namespace'] ?? '';
                $name = $sa['metadata']['name'] ?? '';
                $labels = $sa['metadata']['labels'] ?? [];
                $rows[] = [$ns, $name, $labels['larakube.dev/app'] ?? '—', $labels['larakube.dev/env'] ?? '—', $this->tokenStatus($kubectl, $ns, $name)];
            }
            $this->line('  <fg=green>Deploy credentials</>');
            table(['Namespace', 'ServiceAccount', 'App', 'Env', 'CI token'], $rows);
            $this->line('  <fg=gray>Audit one:</> <fg=yellow>larakube cluster:users '.$rows[0][0].'</>');
        }

        if (! empty($teammates)) {
            $rows = [];
            foreach ($teammates as $sa) {
                $name = $sa['metadata']['name'] ?? '';
                $person = $sa['metadata']['annotations']['larakube.dev/person'] ?? $name;
                $rows[] = [$person, $name, $this->teammateAccess($kubectl, $name)];
            }
            $this->laraKubeNewLine();
            $this->line('  <fg=green>Teammates</>');
            table(['Person', 'Identity', 'Access (namespace:role)'], $rows);
        }

        return 0;
    }

    /** A teammate's bindings as a "namespace:role" summary, read live. */
    protected function teammateAccess(string $kubectl, string $sa): string
    {
        $out = trim((string) shell_exec(
            "{$kubectl} get rolebinding -A -l larakube.dev/access-user=".escapeshellarg($sa)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.namespace}{":"}{.roleRef.name}{"  "}{end}').' 2>/dev/null',
        ));

        return $out !== '' ? $out : '— (no apps)';
    }

    /** Show the LIVE Role rules + binding/token state for one namespace's deployer. */
    protected function showScope(string $kubectl, string $namespace): int
    {
        $role = json_decode((string) shell_exec($kubectl.' get role '.escapeshellarg($this->sa).' -n '.escapeshellarg($namespace).' -o json 2>/dev/null'), true);

        if (! is_array($role) || ($role['kind'] ?? '') !== 'Role') {
            $this->laraKubeError("No '{$this->sa}' Role in namespace '{$namespace}'.");
            $this->line('  Run `larakube cluster:users` to see which namespaces have one.');

            return 1;
        }

        $this->laraKubeInfo("RBAC scope — {$this->sa} @ {$namespace}");
        $this->line('  <fg=gray>(read live from the cluster, so any drift shows here)</>');
        $this->laraKubeNewLine();

        $rows = [];
        foreach ($role['rules'] ?? [] as $rule) {
            $rows[] = [
                implode(', ', array_map(fn ($g) => $g === '' ? '(core)' : $g, $rule['apiGroups'] ?? [])),
                implode(', ', $rule['resources'] ?? []),
                implode(',', $rule['verbs'] ?? []),
            ];
        }
        table(['API group', 'Resources', 'Verbs'], $rows);

        // Binding — an SA with no RoleBinding has no scope, so call it out.
        $rb = json_decode((string) shell_exec($kubectl.' get rolebinding '.escapeshellarg($this->sa).' -n '.escapeshellarg($namespace).' -o json 2>/dev/null'), true);
        $bound = is_array($rb)
            && ($rb['roleRef']['name'] ?? '') === $this->sa
            && collect($rb['subjects'] ?? [])->contains(fn ($s) => ($s['kind'] ?? '') === 'ServiceAccount' && ($s['name'] ?? '') === $this->sa);

        $this->line('  <fg=gray>Binding:</> '.($bound
            ? '<fg=green>✅ RoleBinding "'.$this->sa.'" → ServiceAccount "'.$this->sa.'"</>'
            : '<fg=red>⚠ not bound — the ServiceAccount has no scope!</>'));

        // CI token state (the long-lived bound-token Secret, if minted).
        $token = $this->tokenStatus($kubectl, $namespace, $this->sa);
        $minted = trim((string) shell_exec($kubectl.' -n '.escapeshellarg($namespace).' get secret '.escapeshellarg($this->sa.'-token').' -o jsonpath='.escapeshellarg('{.metadata.creationTimestamp}').' 2>/dev/null'));
        $this->line('  <fg=gray>CI token:</> '.$token.($minted !== '' ? '  <fg=gray>minted:</> '.$minted : ''));

        return 0;
    }

    /** Decode a kubectl `-o json` list into its items array. */
    protected function kubectlItems(string $command): array
    {
        $data = json_decode((string) shell_exec($command), true);

        return is_array($data) ? ($data['items'] ?? []) : [];
    }

    /** Is a long-lived bound-token Secret present + populated for this SA? */
    protected function tokenStatus(string $kubectl, string $namespace, string $sa): string
    {
        $b64 = trim((string) shell_exec($kubectl.' -n '.escapeshellarg($namespace).' get secret '.escapeshellarg($sa.'-token').' -o jsonpath='.escapeshellarg('{.data.token}').' 2>/dev/null'));

        return $b64 !== '' ? '✅ active' : '—';
    }
}
