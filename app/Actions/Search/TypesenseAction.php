<?php

namespace App\Actions\Search;

use App\Actions\Contracts\FeatureAction;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;

class TypesenseAction implements FeatureAction
{
    use GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithProjectConfig;

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require laravel/scout typesense/typesense-php',
            'php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->syncEnvFile($projectPath, [
            'SCOUT_DRIVER' => 'typesense',
            'TYPESENSE_HOST' => 'laravel-typesense',
            'TYPESENSE_PORT' => '8108',
            'TYPESENSE_PROTOCOL' => 'http',
            'TYPESENSE_API_KEY' => 'secretpassword',
        ]);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/typesense/k8s-deployment.yaml.stub'));
        $content = str_replace('{{APP_NAME}}', $appName, $content);
        file_put_contents($k8sPath.'/base/typesense-deployment.yaml', $content);

        // Add local volumes
        if (isset($context['projectPath'])) {
            $vols = file_get_contents(base_path('resources/stubs/blocks/typesense/k8s-volumes.yaml.stub'));
            $vols = str_replace(['{{PROJECT_PATH}}', '{{APP_NAME}}'], [realpath($context['projectPath']), $appName], $vols);
            file_put_contents($k8sPath.'/overlays/local/typesense-volumes.yaml', $vols);

            // Add local Ingress for API access
            $ingress = file_get_contents(base_path('resources/stubs/blocks/typesense/k8s-ingress.yaml.stub'));
            $ingress = str_replace('{{HOST}}', $appName.'.local', $ingress);
            file_put_contents($k8sPath.'/overlays/local/typesense-ingress.yaml', $ingress);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // Not adding to docker-compose yet to stay focused on K8s
    }

    public function getManifestFiles(): array
    {
        return ['typesense-deployment.yaml', 'typesense-volumes.yaml', 'typesense-ingress.yaml'];
    }
}
