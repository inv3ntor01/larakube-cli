<?php

namespace App\Actions\Search;

use App\Actions\Contracts\FeatureAction;
use App\Traits\GeneratesProjectInfrastructure;

class ScoutDatabaseAction implements FeatureAction
{
    use GeneratesProjectInfrastructure;

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require laravel/scout',
            'php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->syncEnvFile($projectPath, [
            'SCOUT_DRIVER' => 'database',
        ]);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        // No extra Kubernetes infrastructure needed for database driver
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // No extra Docker Compose infrastructure needed
    }
}
