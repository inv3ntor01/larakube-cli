<?php

namespace App\Actions\Queues;

use App\Actions\Contracts\FeatureAction;

class QueueAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return []; // Standard queue worker is built-in
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/queues/k8s-deployment.yaml.stub'));
        $content = str_replace('{{IMAGE_NAME}}', $appName, $content);
        file_put_contents($k8sPath.'/base/queue-deployment.yaml', $content);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'worker:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/queues/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return ['queue-deployment.yaml'];
    }
}
