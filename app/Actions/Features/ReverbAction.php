<?php

namespace App\Actions\Features;

use App\Actions\Contracts\FeatureAction;
use App\Enums\PackageManager;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;

class ReverbAction implements FeatureAction
{
    use InteractsWithDocker, InteractsWithProjectConfig;

    public function getInstallCommands(array $context = []): array
    {
        $projectPath = $context['projectPath'] ?? null;
        $commands = [];

        if ($projectPath && ! file_exists($projectPath.'/config/broadcasting.php')) {
            $commands[] = 'php artisan install:broadcasting --reverb --without-node';
        }

        if ($projectPath && file_exists($projectPath.'/package.json')) {
            $packageJson = json_decode(file_get_contents($projectPath.'/package.json'), true);
            $dependencies = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);

            $jsPackages = ['laravel-echo', 'pusher-js'];
            if (! isset($dependencies['laravel-echo'])) {
                $frontend = $context['frontend'] ?? 'none';

                if ($frontend === 'react' || isset($dependencies['react'])) {
                    $jsPackages[] = '@laravel/echo-react';
                } elseif ($frontend === 'vue' || isset($dependencies['vue'])) {
                    $jsPackages[] = '@laravel/echo-vue';
                }

                $pm = PackageManager::from($context['packageManager'] ?? 'npm');
                $commands[] = $pm->addDevCommand($jsPackages).' --ignore-scripts';
            }
        }

        return $commands;
    }

    public function onPostInstall(string $projectPath, array $context = []): void
    {
        // No host-side tasks
    }

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void
    {
        $content = file_get_contents(base_path('resources/stubs/blocks/reverb/k8s-deployment.yaml.stub'));
        $content = str_replace('{{IMAGE_NAME}}', $appName, $content);
        file_put_contents($k8sPath.'/base/reverb-deployment.yaml', $content);

        if (isset($context['projectPath'])) {
            $patch = file_get_contents(base_path('resources/stubs/blocks/reverb/k8s-patch.yaml.stub'));
            $patch = str_replace('{{PROJECT_PATH}}', realpath($context['projectPath']), $patch);
            file_put_contents($k8sPath.'/overlays/local/reverb-patch.yaml', $patch);
        }
    }

    public function updateDockerCompose(string $projectPath, array $context = []): void
    {
        $content = file_get_contents($projectPath.'/docker-compose.yml');
        if (! str_contains($content, 'reverb:')) {
            $service = file_get_contents(base_path('resources/stubs/blocks/reverb/docker-compose.yml.stub'));
            $content = str_replace('services:', "services:\n".$service, $content);
            file_put_contents($projectPath.'/docker-compose.yml', $content);
        }
    }

    public function getManifestFiles(): array
    {
        return [
            'base' => ['reverb-deployment.yaml'],
            'patches' => ['reverb-patch.yaml'],
        ];
    }
}
