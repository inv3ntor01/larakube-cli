<?php

namespace App\Actions\Queues;

use App\Actions\Contracts\FeatureAction;

class HorizonAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require laravel/horizon',
            'php artisan horizon:install',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/horizon/k8s-deployment.yaml.stub'));
        $content = str_replace('{{IMAGE_NAME}}', $appName, $content);
        file_put_contents($k8sPath.'/base/horizon-deployment.yaml', $content);

        if (isset($context['projectPath'])) {
            $patch = file_get_contents(base_path('resources/stubs/blocks/horizon/k8s-patch.yaml.stub'));
            $patch = str_replace('{{PROJECT_PATH}}', realpath($context['projectPath']), $patch);
            file_put_contents($k8sPath.'/overlays/local/horizon-patch.yaml', $patch);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        $content = file_get_contents($projectPath.'/docker-compose.yml');
        if (! str_contains($content, 'horizon:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/horizon/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, $content);
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['horizon-deployment.yaml'],
            'patches' => ['horizon-patch.yaml'],
        ];
    }
}
