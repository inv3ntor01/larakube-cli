<?php

namespace App\Actions\Features;

use App\Actions\Contracts\FeatureAction;

class MonitoringAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return []; // Infrastructure-only
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        // 1. Write Prometheus
        $prom = file_get_contents(base_path('resources/stubs/blocks/monitoring/k8s-prometheus.yaml.stub'));
        file_put_contents($k8sPath.'/base/monitoring-prometheus.yaml', $prom);

        // 2. Write Grafana
        $grafana = file_get_contents(base_path('resources/stubs/blocks/monitoring/k8s-grafana.yaml.stub'));
        $host = basename(getcwd()).'.dev.test'; // Default host calculation
        $grafana = str_replace('{{HOST}}', $host, $grafana);
        file_put_contents($k8sPath.'/base/monitoring-grafana.yaml', $grafana);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // Infrastructure-only
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['monitoring-prometheus.yaml', 'monitoring-grafana.yaml'],
        ];
    }
}
