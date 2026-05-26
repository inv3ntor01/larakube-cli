<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithClusterContext
{
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

        $localKeywords = ['k3d', 'minikube', 'docker-desktop', 'orbstack', 'kind', 'colima'];

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
        $k3dStatus = shell_exec('k3d cluster list larakube --no-headers 2>/dev/null') ?: '';
        $isStopped = str_contains($k3dStatus, 'stopped') || ! str_contains($k3dStatus, 'running');

        foreach ($contexts as $context) {
            $label = $context;
            if ($context === 'k3d-larakube' && $isStopped) {
                $label .= ' <fg=yellow>(stopped)</>';
            }
            $options[$context] = $label;
        }

        return \Laravel\Prompts\select(
            label: 'Which Kubernetes context would you like to use?',
            options: $options,
            default: $currentContext ?: null
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
