<?php

namespace App\Actions\Queues;

use App\Actions\Contracts\FeatureAction;

class TaskSchedulingAction implements FeatureAction
{
    public function getInstallCommands(array $context = []): array
    {
        return []; // Task scheduling is built-in
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/schedule/k8s-cronjob.yaml.stub'));
        $content = str_replace('{{IMAGE_NAME}}', $appName, $content);
        file_put_contents($k8sPath.'/base/scheduler-cronjob.yaml', $content);
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        // Docker Compose doesn't have a direct equivalent to K8s CronJob easily
    }

    public function getManifestFiles(): array
    {
        return ['scheduler-cronjob.yaml'];
    }
}
