<?php

namespace App\Commands\Cluster;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTeammateRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesNamespaceArg;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ClusterRevokeCommand extends Command
{
    use InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithTeammateRbac, LaraKubeOutput, ResolvesNamespaceArg;

    protected $signature = 'cluster:revoke
        {namespace? : The namespace — a deploy credential, or one app to drop a teammate from}
        {--name= : Revoke a TEAMMATE (omit the namespace to off-board them entirely)}
        {--context= : Target a specific kube-context}
        {--with-secret : Also delete the GitHub <ENV>_KUBECONFIG secret (deploy revoke only; best-effort)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke a deploy credential or a teammate\'s access — the kill-switch for offboarding or a leak';

    private string $sa = 'deployer';

    public function handle(): int
    {
        $this->renderHeader();

        $kubectl = 'kubectl'.($this->option('context') ? ' --context '.escapeshellarg((string) $this->option('context')) : '');

        // Teammate path (--name) vs deploy-credential path (the default).
        if ($this->option('name')) {
            return $this->revokeTeammate($kubectl, (string) $this->option('name'));
        }

        $namespace = (string) $this->argument('namespace');
        if ($namespace === '') {
            $this->laraKubeError('Provide a namespace (deploy credential) or --name <teammate>.');

            return 1;
        }
        $namespace = $this->resolveNamespaceArg($namespace);   // accept an env name in-project
        $ns = escapeshellarg($namespace);

        $this->laraKubeWarn("This removes deploy access to '{$namespace}' — the '{$this->sa}' ServiceAccount, Role, RoleBinding, and token.");
        $this->line('  <fg=gray>Running workloads are untouched — use `cloud:nuke` to remove the app itself.</>');
        $this->laraKubeNewLine();

        if (! $this->option('force') && ! confirm("Revoke the deploy credential for '{$namespace}'?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        // Delete each piece by its deterministic name (idempotent).
        foreach (["rolebinding/{$this->sa}", "role/{$this->sa}", "serviceaccount/{$this->sa}", "secret/{$this->sa}-token"] as $resource) {
            shell_exec("{$kubectl} -n {$ns} delete {$resource} --ignore-not-found 2>/dev/null");
        }

        $this->laraKubeInfo("✅ Revoked the '{$this->sa}' deploy credential in '{$namespace}'. Any kubeconfig using its token is now dead.");

        if ($this->option('with-secret')) {
            $this->deleteGithubSecret($namespace);
        } else {
            $this->line('  <fg=gray>The GitHub {ENV}_KUBECONFIG secret (if any) now points at a dead token — harmless. Re-grant with `cloud:configure:gha`, or pass --with-secret to delete it.</>');
        }

        return 0;
    }

    /**
     * Revoke a teammate: from ONE app (delete that RoleBinding) when a namespace is
     * given, or off-board entirely (every RoleBinding cluster-wide + the SA + token)
     * when it isn't. Their kubeconfig is unchanged but its token stops working.
     */
    protected function revokeTeammate(string $kubectl, string $name): int
    {
        $sa = $this->teammateSaName($name);
        if ($sa === '') {
            $this->laraKubeError('Could not derive an identity from that name.');

            return 1;
        }

        $namespace = (string) $this->argument('namespace');
        if ($namespace !== '') {
            $namespace = $this->resolveNamespaceArg($namespace);   // accept an env name in-project
        }

        // Remove from a single app — drop just that RoleBinding.
        if ($namespace !== '') {
            $this->laraKubeWarn("This removes '{$name}'s access to '{$namespace}' (their other apps, if any, keep working).");
            if (! $this->option('force') && ! confirm("Revoke {$name}'s access to '{$namespace}'?", false)) {
                $this->laraKubeInfo('Cancelled.');

                return 0;
            }
            shell_exec("{$kubectl} -n ".escapeshellarg($namespace).' delete rolebinding '.escapeshellarg($this->teammateBindingName($sa)).' --ignore-not-found 2>/dev/null');
            $this->laraKubeInfo("✅ Removed {$name}'s access to '{$namespace}'.");

            return 0;
        }

        // Full off-board — every binding (by label, across namespaces) + identity.
        $this->laraKubeWarn("This OFF-BOARDS '{$name}' entirely — all RoleBindings, the ServiceAccount, and token. Their kubeconfig becomes inert.");
        if (! $this->option('force') && ! confirm("Off-board '{$name}' completely?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $list = shell_exec("{$kubectl} get rolebinding -A -l larakube.dev/access-user=".escapeshellarg($sa).' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.namespace}{" "}{.metadata.name}{"\n"}{end}').' 2>/dev/null');
        foreach (array_filter(array_map('trim', explode("\n", (string) $list))) as $line) {
            [$bns, $bname] = array_pad(explode(' ', $line, 2), 2, '');
            if ($bns !== '' && $bname !== '') {
                shell_exec("{$kubectl} -n ".escapeshellarg($bns).' delete rolebinding '.escapeshellarg($bname).' --ignore-not-found 2>/dev/null');
            }
        }

        $accessNs = escapeshellarg($this->accessNamespace());
        shell_exec("{$kubectl} -n {$accessNs} delete serviceaccount ".escapeshellarg($sa).' secret '.escapeshellarg($sa.'-token').' --ignore-not-found 2>/dev/null');

        $this->laraKubeInfo("✅ Off-boarded '{$name}'.");

        return 0;
    }

    /**
     * Best-effort deletion of the env's GitHub kubeconfig secret. Derives the env
     * from the namespace's last segment ({app}-{env}; env names don't contain
     * hyphens) and the repo from the local git remote, so it must run inside the
     * project. A failure is non-fatal — the secret only points at a dead token.
     */
    protected function deleteGithubSecret(string $namespace): void
    {
        $env = str_contains($namespace, '-') ? substr((string) strrchr($namespace, '-'), 1) : $namespace;
        $secret = strtoupper($env).'_KUBECONFIG';

        $repoFlag = '';
        $repo = trim((string) shell_exec('git remote get-url origin 2>/dev/null'));
        if ($repo !== '' && preg_match('/(?:github\.com|github)[:\/](.*?)(?:\.git)?$/', $repo, $m)) {
            $repoFlag = '-R '.escapeshellarg($m[1]);
        }

        $gh = $this->getGhCommand();
        exec("{$gh} secret delete ".escapeshellarg($secret)." {$repoFlag} 2>&1", $out, $code);

        if ($code === 0) {
            $this->laraKubeInfo("✅ Deleted GitHub secret {$secret}.");
        } else {
            $this->laraKubeWarn("Could not delete GitHub secret {$secret} — run inside the repo with `gh` authenticated. (It only points at a now-dead token, so it's harmless.)");
        }
    }
}
