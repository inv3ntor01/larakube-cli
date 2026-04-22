<?php

namespace App\Actions\Databases;

use App\Actions\Contracts\DatabaseAction;

class PostgresAction implements DatabaseAction
{
    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/postgres/k8s-deployment.yaml.stub'));
        $content = str_replace('postgres-pvc', $appName.'-postgres-pvc', $content);
        file_put_contents($k8sPath.'/base/postgres-deployment.yaml', $content);

        if (isset($context['projectPath'])) {
            // 1. Write the PVC Resource (Local & Production)
            $vols = file_get_contents(base_path('resources/stubs/blocks/postgres/k8s-volumes.yaml.stub'));
            $vols = str_replace('{{APP_NAME}}', $appName, $vols);
            file_put_contents($k8sPath.'/overlays/local/postgres-volumes.yaml', $vols);
            file_put_contents($k8sPath.'/overlays/production/postgres-volumes.yaml', $vols);

            // 2. Write the Deployment Patch (Local only)
            $patch = file_get_contents(base_path('resources/stubs/blocks/postgres/k8s-patch.yaml.stub'));
            $patch = str_replace('{{APP_NAME}}', $appName, $patch);
            file_put_contents($k8sPath.'/overlays/local/postgres-patch.yaml', $patch);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'postgres:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/postgres/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['postgres-deployment.yaml'],
            'local' => ['postgres-volumes.yaml'],
            'production' => ['postgres-volumes.yaml'],
            'patches' => ['postgres-patch.yaml'],
        ];
    }
}
