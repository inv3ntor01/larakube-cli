<?php

namespace App\Actions\Databases;

use App\Actions\Contracts\DatabaseAction;
use App\Actions\Contracts\FeatureAction;
use App\Traits\GeneratesProjectInfrastructure;

class MongoDbAction implements DatabaseAction, FeatureAction
{
    use GeneratesProjectInfrastructure;

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require mongodb/laravel-mongodb --with-all-dependencies',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->syncEnvFile($projectPath, [
            'DB_CONNECTION' => 'mongodb',
            'MONGODB_URI' => 'mongodb://root:secretpassword@mongodb:27017',
            'MONGODB_DATABASE' => 'laravel',
        ]);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/mongodb/k8s-statefulset.yaml.stub'));
        $content = str_replace('mongodb-data', $appName.'-mongodb-data', $content);
        file_put_contents($k8sPath.'/base/mongodb-statefulset.yaml', $content);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'mongodb:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/mongodb/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['mongodb-statefulset.yaml'],
        ];
    }
}
