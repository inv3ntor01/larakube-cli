<?php

namespace App\Traits;

use function Laravel\Prompts\select;

trait InteractsWithEnvironments
{
    /**
     * Get the available Kubernetes environment overlays.
     */
    protected function getAvailableEnvironments(): array
    {
        $projectPath = getcwd();
        $overlayPath = $projectPath.'/.infrastructure/k8s/overlays';
        $environments = [];

        // 1. Filesystem is source of truth for local manifests
        if (is_dir($overlayPath)) {
            $environments = array_merge($environments, array_diff(scandir($overlayPath), ['.', '..']));
        }

        // 2. Query the cluster for active environments using labels
        $config = method_exists($this, 'getProjectConfigObject') ? $this->getProjectConfigObject($projectPath) : null;
        $appName = $config?->getName() ?? basename($projectPath);

        $json = shell_exec("kubectl get namespaces -l larakube.io/project={$appName} -o json 2>/dev/null");
        if ($json) {
            $data = json_decode($json, true);
            foreach ($data['items'] ?? [] as $item) {
                $ns = $item['metadata']['name'];
                // Extract environment suffix (e.g. app-staging -> staging)
                if (str_starts_with($ns, "{$appName}-")) {
                    $environments[] = str_replace("{$appName}-", '', $ns);
                }
            }
        }

        // 3. Fallback to Config DNA
        if ($config) {
            $environments = array_merge($environments, $config->getEnvironments());
        }

        $environments = array_unique($environments);

        return ! empty($environments) ? array_values($environments) : ['local', 'production'];
    }

    /**
     * Prompt the user to select an environment.
     */
    protected function askForEnvironment(string $label = 'Which environment would you like to target?', string $default = 'local'): string
    {
        return select(
            label: $label,
            options: $this->getAvailableEnvironments(),
            default: $default
        );
    }

    /**
     * Get the Kubernetes namespace for a given environment.
     */
    protected function getNamespace(string $environment, ?string $appName = null): string
    {
        $appName = $appName ?? basename(getcwd());

        return "$appName-$environment";
    }
}
