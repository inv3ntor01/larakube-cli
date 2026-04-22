<?php

namespace App\Actions\Features;

use App\Actions\Contracts\FeatureAction;

class OctaneAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require laravel/octane --with-all-dependencies',
            'php artisan octane:install --server=frankenphp',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        // Octane configuration is handled via the start command in deployment.yaml
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // Octane configuration is handled via the start command
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => [],
            'local' => [],
            'production' => [],
            'patches' => [],
        ];
    }
}
