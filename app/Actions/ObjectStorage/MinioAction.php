<?php

namespace App\Actions\ObjectStorage;

use App\Actions\Contracts\FeatureAction;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;

class MinioAction implements FeatureAction
{
    use GeneratesProjectInfrastructure, InteractsWithDocker;

    public function getInstallCommands(array $context = []): array
    {
        return [
            'composer require league/flysystem-aws-s3-v3',
        ];
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        $this->syncEnvFile($projectPath, [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'larakube',
            'AWS_SECRET_ACCESS_KEY' => 'secretpassword',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => 'laravel',
            'AWS_URL' => 'http://s3.localhost',
            'AWS_ENDPOINT' => 'http://laravel-minio:9000',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ]);
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/minio/k8s-deployment.yaml.stub'));
        $content = str_replace('minio-pvc', $appName.'-minio-pvc', $content);
        file_put_contents($k8sPath.'/base/minio-deployment.yaml', $content);

        // Add local volumes
        if (isset($context['projectPath'])) {
            $vols = file_get_contents(base_path('resources/stubs/blocks/minio/k8s-volumes.yaml.stub'));
            $vols = str_replace(['{{PROJECT_PATH}}', '{{APP_NAME}}-minio-pv', '{{APP_NAME}}', 'minio-pvc'], [realpath($context['projectPath']), $appName.'-minio-pv', $appName, $appName.'-minio-pvc'], $vols);
            file_put_contents($k8sPath.'/overlays/local/minio-volumes.yaml', $vols);

            // Add local Ingress for dashboard and API
            $ingress = file_get_contents(base_path('resources/stubs/blocks/minio/k8s-ingress.yaml.stub'));
            $ingress = str_replace('{{HOST}}', $appName.'.local', $ingress);
            file_put_contents($k8sPath.'/overlays/local/minio-ingress.yaml', $ingress);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        if (! str_contains(file_get_contents($projectPath.'/docker-compose.yml'), 'minio:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/minio/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, file_get_contents($projectPath.'/docker-compose.yml'));
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return ['minio-deployment.yaml', 'minio-volumes.yaml', 'minio-ingress.yaml'];
    }
}
