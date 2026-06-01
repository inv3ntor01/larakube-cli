<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithClusterContext
{
    /**
     * Decide whether a single `k3d cluster list --no-headers` line represents a
     * running cluster. Pure (no I/O) so the SERVERS-column parsing can be tested.
     *
     * The columns are NAME, SERVERS, AGENTS, LOADBALANCER, where SERVERS is
     * "running/total" — e.g. "1/1" when up, "0/1" when stopped. An empty line
     * means the cluster doesn't exist (or k3d isn't installed).
     */
    public function k3dClusterListLineIsRunning(string $line): bool
    {
        $line = trim($line);

        if ($line === '') {
            return false;
        }

        $columns = preg_split('/\s+/', $line);
        $serversRunning = (int) explode('/', $columns[1] ?? '0/0')[0];

        return $serversRunning > 0;
    }

    /**
     * Determine if there is an active and reachable Kubernetes cluster.
     */
    protected function hasActiveCluster(): bool
    {
        $context = trim(shell_exec('kubectl config current-context 2>/dev/null') ?? '');

        if (! $context) {
            return false;
        }

        // We use a short timeout to prevent the CLI from hanging if the cluster is unreachable
        $output = [];
        $resultCode = 0;
        exec('kubectl cluster-info --request-timeout=2s 2>&1', $output, $resultCode);

        return $resultCode === 0;
    }

    /**
     * Get the standard LaraKube k3d context name.
     */
    protected function getLaraKubeContext(): string
    {
        return 'k3d-larakube';
    }

    /**
     * Check if the LaraKube k3d context exists in the kubeconfig.
     */
    protected function laraKubeContextExists(): bool
    {
        $output = shell_exec('kubectl config get-contexts -o name 2>/dev/null');

        return str_contains($output ?? '', $this->getLaraKubeContext());
    }

    /**
     * Determine whether the local k3d cluster is currently running.
     *
     * `k3d cluster list <name> --no-headers` prints the SERVERS column as
     * "running/total" (e.g. "1/1" up, "0/1" stopped) — there is no literal
     * "running"/"stopped" word to match on, so we parse that column instead.
     */
    protected function isK3dClusterRunning(string $name = 'larakube'): bool
    {
        $line = (string) shell_exec('k3d cluster list '.escapeshellarg($name).' --no-headers 2>/dev/null');

        return $this->k3dClusterListLineIsRunning($line);
    }

    /**
     * Check if ANY Kubernetes context exists on the system.
     */
    protected function hasAnyContext(): bool
    {
        $output = shell_exec('kubectl config get-contexts -o name 2>/dev/null');

        return ! empty(trim($output ?? ''));
    }

    /**
     * Determine if the current Kubernetes context is likely a local cluster.
     */
    protected function isLocalContext(): bool
    {
        $context = shell_exec('kubectl config current-context 2>/dev/null');

        if (! $context) {
            return false;
        }

        $context = trim($context);

        // 'k3s-larakube' is the context name cluster:setup gives a *local* native
        // k3s install. Remote k3s (cloud:provision) is named "larakube-<ip>", so
        // it stays correctly classified as non-local.
        $localKeywords = ['k3d', 'minikube', 'docker-desktop', 'orbstack', 'kind', 'colima', 'k3s-larakube'];

        foreach ($localKeywords as $keyword) {
            if (str_contains(strtolower($context), $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt the user to select a Kubernetes context.
     */
    protected function askForClusterContext(): ?string
    {
        $contextsOutput = shell_exec('kubectl config get-contexts -o name 2>/dev/null');
        $currentContext = trim(shell_exec('kubectl config current-context 2>/dev/null') ?? '');

        if (! $contextsOutput) {
            return null;
        }

        $contexts = array_filter(explode("\n", trim($contextsOutput)));

        if (empty($contexts)) {
            return null;
        }

        // --- 🔍 ENHANCED STATUS DETECTION ---
        $options = [];
        $k3dRunning = $this->isK3dClusterRunning();

        foreach ($contexts as $context) {
            $label = $context;
            if ($context === $this->getLaraKubeContext() && ! $k3dRunning) {
                $label .= ' <fg=yellow>(stopped)</>';
            }
            $options[$context] = $label;
        }

        return \Laravel\Prompts\select(
            label: 'Which Kubernetes context would you like to use?',
            options: $options,
            default: $currentContext ?: null,
        );
    }

    /**
     * Switch to a specific Kubernetes context.
     */
    protected function switchClusterContext(string $name): bool
    {
        exec('kubectl config use-context '.escapeshellarg($name), $output, $resultCode);

        return $resultCode === 0;
    }

    /**
     * Validate that the current context matches the intended environment.
     */
    protected function validateContextForEnvironment(string $environment): bool
    {
        $isLocal = $this->isLocalContext();
        $context = trim(shell_exec('kubectl config current-context 2>/dev/null') ?? 'Unknown');

        // 1. WARNING: Local code on Remote Cluster
        if ($environment === 'local' && ! $isLocal) {
            $this->renderHeader();
            $this->laraKubeWarn('🚨 SECURITY ALERT: Remote Cluster Detected!');
            $this->line('   You are targeting the <fg=yellow;options=bold>local</> environment, but your current');
            $this->line("   Kubernetes context is set to: <fg=cyan;options=bold>{$context}</>");
            $this->newLine();
            $this->line('   Deploying a "local" configuration to a remote cluster will fail because');
            $this->line('   it attempts to mount your computer\'s folders into the remote VPS.');
            $this->newLine();

            if (! confirm('Are you ABSOLUTELY sure you want to proceed with this remote deployment?', false)) {
                $this->laraKubeInfo('Deployment cancelled. Please switch context or target "production".');

                return false;
            }
        }

        // 2. WARNING: Production on Local Cluster (Safety check, less critical)
        if ($environment === 'production' && $isLocal) {
            $this->laraKubeWarn('💡 NOTE: You are deploying "production" to a local cluster.');
            $this->line("   Current context: <fg=cyan>{$context}</>");

            if (! confirm('Proceed with local production deployment?', true)) {
                return false;
            }
        }

        return true;
    }
}
