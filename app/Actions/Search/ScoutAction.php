<?php

namespace App\Actions\Search;

use App\Actions\Contracts\FeatureAction;
use App\Enums\ScoutDriver;
use App\Traits\InteractsWithProjectConfig;

class ScoutAction implements FeatureAction
{
    use InteractsWithProjectConfig;

    protected function getDriverAction(array $context): ?FeatureAction
    {
        $projectPath = $context['projectPath'] ?? getcwd();
        $config = $this->getProjectConfig($projectPath);
        $driverName = $context['scoutDriver'] ?? ($config['scoutDriver'] ?? ScoutDriver::MEILISEARCH->value);

        $driver = ScoutDriver::tryFrom($driverName) ?? ScoutDriver::from($driverName);

        return match ($driver) {
            ScoutDriver::MEILISEARCH => new MeilisearchAction,
            ScoutDriver::TYPESENSE => new TypesenseAction,
            ScoutDriver::DATABASE => new ScoutDatabaseAction,
            default => null,
        };
    }

    public function getInstallCommands(array $context = []): array
    {
        return $this->getDriverAction($context)?->getInstallCommands($context) ?? [];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->getDriverAction($context)?->onPostInstall($projectPath, $context);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $this->getDriverAction($context)?->updateK8s($k8sPath, $appName, $context);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        $this->getDriverAction($context)?->updateDockerCompose($projectPath, $context);
    }
}
