<?php

namespace App\Actions\Databases;

use App\Actions\Contracts\DatabaseAction;

class RedisAction implements DatabaseAction
{
    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/redis/k8s-deployment.yaml.stub'));
        file_put_contents($k8sPath.'/base/redis-deployment.yaml', $content);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'redis:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/redis/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['redis-deployment.yaml'],
        ];
    }
}
