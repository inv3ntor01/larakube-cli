<?php

namespace App\Actions\Databases;

use App\Actions\Contracts\DatabaseAction;

class MySqlAction implements DatabaseAction
{
    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/mysql/k8s-deployment.yaml.stub'));
        $content = str_replace('mysql-pvc', $appName.'-mysql-pvc', $content);
        file_put_contents($k8sPath.'/base/mysql-deployment.yaml', $content);

        if (isset($context['projectPath'])) {
            // 1. Write the PVC Resource (Local & Production)
            $vols = file_get_contents(base_path('resources/stubs/blocks/mysql/k8s-volumes.yaml.stub'));
            $vols = str_replace('{{APP_NAME}}', $appName, $vols);
            file_put_contents($k8sPath.'/overlays/local/mysql-volumes.yaml', $vols);
            file_put_contents($k8sPath.'/overlays/production/mysql-volumes.yaml', $vols);

            // 2. Write the Deployment Patch (Local only)
            $patch = file_get_contents(base_path('resources/stubs/blocks/mysql/k8s-patch.yaml.stub'));
            $patch = str_replace('{{APP_NAME}}', $appName, $patch);
            file_put_contents($k8sPath.'/overlays/local/mysql-patch.yaml', $patch);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'mysql:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/mysql/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['mysql-deployment.yaml'],
            'local' => ['mysql-volumes.yaml'],
            'production' => ['mysql-volumes.yaml'],
            'patches' => ['mysql-patch.yaml'],
        ];
    }
}
