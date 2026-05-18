<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithClusterContext
{
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
