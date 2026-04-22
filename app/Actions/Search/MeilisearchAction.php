<?php

namespace App\Actions\Search;

use App\Actions\Contracts\FeatureAction;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;

class MeilisearchAction implements FeatureAction
{
    use GeneratesProjectInfrastructure, InteractsWithDocker, InteractsWithProjectConfig;

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require laravel/scout meilisearch/meilisearch-php',
            'php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->syncEnvFile($projectPath, [
            'SCOUT_DRIVER' => 'meilisearch',
            'MEILISEARCH_HOST' => 'http://meilisearch:7700',
            'MEILISEARCH_KEY' => 'secretpassword',
        ]);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/meilisearch/k8s-deployment.yaml.stub'));
        $content = str_replace('meilisearch-pvc', $appName.'-meilisearch-pvc', $content);
        file_put_contents($k8sPath.'/base/meilisearch-deployment.yaml', $content);

        // Add local volumes
        if (isset($context['projectPath'])) {
            $vols = file_get_contents(base_path('resources/stubs/blocks/meilisearch/k8s-volumes.yaml.stub'));
            $vols = str_replace(['{{PROJECT_PATH}}', '{{APP_NAME}}-meilisearch-pv', '{{APP_NAME}}', 'meilisearch-pvc'], [realpath($context['projectPath']), $appName.'-meilisearch-pv', $appName, $appName.'-meilisearch-pvc'], $vols);
            file_put_contents($k8sPath.'/overlays/local/meilisearch-volumes.yaml', $vols);

            // Add local Ingress for dashboard
            $ingress = file_get_contents(base_path('resources/stubs/blocks/meilisearch/k8s-ingress.yaml.stub'));
            $ingress = str_replace('{{HOST}}', $appName.'.local', $ingress);
            file_put_contents($k8sPath.'/overlays/local/meilisearch-ingress.yaml', $ingress);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        $content = file_get_contents($projectPath.'/docker-compose.yml');
        if (! str_contains($content, 'meilisearch:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/meilisearch/docker-compose.yml.stub'));
            $service = str_replace('{{MEILI_MASTER_KEY}}', 'secretpassword', $service);
            $content = str_replace('services:', "services:\n".$service, $content);
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return ['meilisearch-deployment.yaml', 'meilisearch-volumes.yaml', 'meilisearch-ingress.yaml'];
    }
}
