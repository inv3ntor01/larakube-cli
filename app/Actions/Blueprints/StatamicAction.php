<?php

namespace App\Actions\Blueprints;

use App\Actions\Contracts\BlueprintAction;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;

class StatamicAction implements BlueprintAction
{
    use GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithProjectConfig;

    public function gatherConfig(): array
    {
        return [];
    }

    public function apply(string $projectPath, string $k8sPath, string $appName, array $context = []): void
    {
        // Statamic setup is handled via Artisan/Composer
    }

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require statamic/cms --with-all-dependencies',
            'php artisan statamic:install --no-interaction',
        ];
    }

    public function getPhpExtensions(): array
    {
        return ['gd', 'exif'];
    }

    public function getPostInstallInstructions(): array
    {
        return [
            'To create your first super user, run:',
            'larakube art make:statamic-user',
        ];
    }

    public function getManifestFiles(): array
    {
        return [];
    }
}
