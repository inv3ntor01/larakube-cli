<?php

namespace App\Actions\Blueprints;

use App\Actions\Contracts\BlueprintAction;

class FilamentAction implements BlueprintAction
{
    public function gatherConfig(): array
    {
        return []; // Filament is mostly standard Laravel
    }

    public function apply(string $projectPath, string $k8sPath, string $appName, array $context = []): void
    {
        // Filament setup is handled via Artisan commands
    }

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require filament/filament:"^3.2" -W',
            'php artisan filament:install --panels --ansi --quiet',
        ];
    }

    public function getPhpExtensions(): array
    {
        return ['intl'];
    }

    public function getPostInstallInstructions(): array
    {
        return [
            'To create your first admin user, run:',
            'larakube art make:filament-user',
        ];
    }

    public function getManifestFiles(): array
    {
        return [];
    }
}
