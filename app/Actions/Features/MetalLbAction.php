<?php

namespace App\Actions\Features;

use App\Actions\Contracts\FeatureAction;

class MetalLbAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return []; // System infrastructure only
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        // 1. Install MetalLB manifests from official source
        passthru('kubectl apply -f https://raw.githubusercontent.com/metallb/metallb/v0.14.9/config/manifests/metallb-native.yaml');

        // 2. Wait for MetalLB controller to be ready
        passthru('kubectl wait --for=condition=ready pod -l app=metallb -n metallb-system --timeout=120s');

        // 3. Detect Docker network range for k3d/kind
        $ipRange = $this->detectIpRange();

        // 4. Apply configuration
        $config = file_get_contents(base_path('resources/stubs/blocks/metallb/k8s-config.yaml.stub'));
        $config = str_replace('{{IP_RANGE}}', $ipRange, $config);

        $tmpPath = sys_get_temp_dir().'/metallb-config.yaml';
        file_put_contents($tmpPath, $config);
        passthru("kubectl apply -f {$tmpPath}");
        @unlink($tmpPath);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // Not applicable
    }

    public function getManifestFiles(): array
    {
        return []; // Handled via direct apply since it's a cluster-wide service
    }

    protected function detectIpRange(): string
    {
        // Default range for k3d/docker bridge
        $output = shell_exec("docker network inspect k3d-larakube -f '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null");

        if (! $output) {
            $output = shell_exec("docker network inspect bridge -f '{{(index .IPAM.Config 0).Subnet}}'");
        }

        // Logic to pick a small range from the subnet (e.g. .200 to .250)
        $subnet = trim($output);
        $base = substr($subnet, 0, strrpos($subnet, '.'));

        return "{$base}.200-{$base}.250";
    }
}
