<?php

namespace App\Traits;

use App\Contracts\HasKubernetesFiles;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use Random\RandomException;

trait GeneratesProjectInfrastructure
{
    use InteractsWithHosts, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Sync values to the .env file.
     */
    protected function syncEnvFile(string $projectPath, array $values): void
    {
        $envPath = $projectPath.'/.env';
        if (! file_exists($envPath)) {
            return;
        }

        $lines = explode("\n", file_get_contents($envPath));
        $newLines = [];
        $processedKeys = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($values as $key => $value) {
                if (preg_match("/^#?\s*{$key}=.*/", $line)) {
                    $newLines[] = "{$key}={$value}";
                    $processedKeys[] = $key;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $newLines[] = $line;
            }
        }

        // Add keys that didn't exist in the original file
        foreach ($values as $key => $value) {
            if (! in_array($key, $processedKeys)) {
                $newLines[] = "{$key}={$value}";
            }
        }

        $content = implode("\n", $newLines);
        file_put_contents($envPath, $content);

        if (file_exists($projectPath.'/.env.production')) {
            file_put_contents($projectPath.'/.env.production', $content);
        }
    }

    protected function generateDockerfiles(ConfigData $config): void
    {
        $projectPath = $config->getPath();

        $phpDockerfile = view('docker.php', ['config' => $config])->render();
        file_put_contents("$projectPath/Dockerfile.php", $phpDockerfile);

        $nodeDockerfile = view('docker.node', ['config' => $config])->render();
        file_put_contents("$projectPath/Dockerfile.node", $nodeDockerfile);
    }

    protected function generateK8sManifests(ConfigData $config): void
    {
        $appName = $config->getName();
        $k8sPath = $config->getk8sPath();
        @mkdir($config->getInfrastructurePath(), 0755, true);
        @mkdir("$k8sPath/base", 0755, true);
        @mkdir("$k8sPath/overlays/local", 0755, true);
        @mkdir("$k8sPath/overlays/production", 0755, true);

        // 0. Copy certificates for local development (e.g. for Vite HTTPS)
        $projectCertsPath = $config->getPath().'/.infrastructure/traefik/certificates';
        @mkdir($projectCertsPath, 0755, true);
        $cliCertsPath = base_path('resources/views/traefik/certificates');
        @copy("$cliCertsPath/local-dev.pem", "$projectCertsPath/local-dev.pem");
        @copy("$cliCertsPath/local-dev-key.pem", "$projectCertsPath/local-dev-key.pem");
        @copy("$cliCertsPath/local-ca.pem", "$projectCertsPath/local-ca.pem");

        $this->laraKubeInfo('Generating Kubernetes manifests...');

        // 1. Generate ALL core stubs first (Clean slate)
        $stubs = [
            'base/kustomization.yaml', 'base/deployment.yaml', 'base/service.yaml', 'base/ingress.yaml',
            'base/pvc.yaml', 'base/configmap.yaml',
            'overlays/local/kustomization.yaml', 'overlays/local/namespace.yaml', 'overlays/local/node-deployment.yaml', 'overlays/local/deployment-patch.yaml', 'overlays/local/ingress-patch.yaml', 'overlays/local/laravel-volumes.yaml',
            'overlays/production/kustomization.yaml', 'overlays/production/namespace.yaml', 'overlays/production/deployment-patch.yaml',
        ];

        foreach ($stubs as $stub) {
            $namespace = $appName;
            if (str_contains($stub, 'overlays/local')) {
                $namespace .= '-local';
            } elseif (str_contains($stub, 'overlays/production')) {
                $namespace .= '-production';
            }

            // Calculate environment-aware command
            $command = $config->getServerVariation()->getStartCommand(str_contains($stub, 'overlays/local'));

            $viewName = 'k8s.'.str_replace(['/', '.yaml'], ['.', ''], $stub);
            $content = view($viewName, [
                'config' => $config,
                'namespace' => $namespace,
                'command' => $command,
            ])->render();

            file_put_contents("$k8sPath/$stub", $content);
        }

        // 2. APPLY ACTIONS & COLLECT CATEGORIZED MANIFESTS
        $manifests = [
            'base' => [],
            'local' => [],
            'production' => [],
            'patches' => [],
        ];

        // Add Features, Databases, Object Storage
        $pods = array_filter([...$config->getFeatures(), ...$config->getDatabases(), $config->getObjectStorage(), $config->getScoutDriver()]);

        foreach ($pods as $pod) {
            if ($pod instanceof HasKubernetesFiles) {
                $pod->updateK8s($config);
                $actionFiles = $pod->getManifestFiles();
                foreach (['base', 'local', 'production', 'patches'] as $key) {
                    if (isset($actionFiles[$key])) {
                        $manifests[$key] = array_merge($manifests[$key], $actionFiles[$key]);
                    }
                }
            }
        }

        // 3. SYNCHRONIZED REGISTRATION (Explicit Deduplication)
        foreach (array_unique($manifests['base']) as $file) {
            $this->appendToKustomization($k8sPath, 'base', $file);
        }
        foreach (array_unique($manifests['local']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file);
        }
        foreach (array_unique($manifests['production']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/production', $file);
        }
        foreach (array_unique($manifests['patches']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file, 'patches');
        }
    }

    protected function appendToKustomization(string $k8sPath, string $folder, string $filename, string $type = 'resources'): void
    {
        $kustomizationFile = "$k8sPath/$folder/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);

        if ($type === 'resources') {
            if (! str_contains($content, "  - $filename") && ! str_contains($content, "- $filename")) {
                $content = preg_replace('/resources:\n/', "resources:\n  - $filename\n", $content, 1);
            }
        } elseif ($type === 'patches') {
            if (! str_contains($content, "path: $filename")) {
                if (! str_contains($content, 'patches:')) {
                    $content .= "\npatches:\n";
                }
                $content = preg_replace('/patches:\n/', "patches:\n  - path: $filename\n", $content, 1);
            }
        }

        file_put_contents($kustomizationFile, $content);
    }

    protected function setLaravelStoragePermissions(string $projectPath): void
    {
        $this->laraKubeInfo('Fixing storage permissions...');
        $this->runInContainer('chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache', $projectPath);
    }

    protected function ensureHttpsCompatibility(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $providerPath = $projectPath.'/app/Providers/AppServiceProvider.php';

        if (! file_exists($providerPath)) {
            return;
        }

        $content = file_get_contents($providerPath);

        // Check if already applied
        if (str_contains($content, 'URL::forceScheme')) {
            return;
        }

        $this->laraKubeInfo('Surgically applying HTTPS compatibility to AppServiceProvider...');

        $injection = "        if (str_starts_with(config('app.url'), 'https://')) {\n            \Illuminate\Support\Facades\URL::forceScheme('https');\n        }\n\n";

        // Find the boot() method
        $pattern = "/public function boot\(\): void\n    \{/";
        $replacement = "public function boot(): void\n    {\n".$injection;

        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, $replacement, $content);
            file_put_contents($providerPath, $newContent);
        }
    }

    /**
     * Orchestrate the entire project infrastructure generation and installation.
     *
     * @throws RandomException
     */
    protected function orchestrateProjectScaffolding(ConfigData $config, bool $installFeatures = true, bool $buildImage = true, bool $dryRun = false): void
    {
        if ($dryRun) {
            $this->laraKubeInfo("Architectural Preview for '{$config->getName()}':");
        }

        // 0. Persist configuration for self-healing
        if ($dryRun) {
            $this->line('  <fg=gray>[FILE]</> Would create .larakube.json with project blueprint.');
            $this->line("  <fg=gray>[DB]</> Would register project '{$config->getName()}' in internal database.");
        } else {
            $this->saveProjectConfig($config->getPath(), $config);
        }

        // 1. Handle Blueprint extensions
        if (! $config->hasBlueprint()) {
            $config->setBlueprint(Blueprint::LARAVEL);
        }

        // 2. Sync .env (Source of Truth)
        $envChanges = $config->getAllEnvironmentVariables();

        if ($dryRun) {
            $this->line('  <fg=gray>[.ENV]</> Would sync the following variables:');
            foreach ($envChanges as $k => $v) {
                $this->line("         $k=$v");
            }
        } else {
            $this->syncEnvFile($config->getPath(), $envChanges);
        }

        // 3. Ensure HTTPS compatibility (Surgical)
        if (! $dryRun) {
            $this->ensureHttpsCompatibility($config);
        }

        // 4. Generate Dockerfiles
        if ($dryRun) {
            $this->line("  <fg=gray>[FILE]</> Would generate Dockerfile.php ({$config->getServerVariation()?->value}).");
        } else {
            $this->generateDockerfiles($config);
        }

        // 4. Build the local image if requested
        if ($buildImage) {
            if ($dryRun) {
                $this->line("  <fg=gray>[DOCKER]</> Would build image '{$config->getName()}:latest'.");
            } else {
                $this->buildImage($config);
            }
        }

        // 5. Generate Manifests
        if ($dryRun) {
            $this->line('  <fg=gray>[K8S]</> Would generate base and overlay manifests in .infrastructure/k8s/');
        } else {
            $this->generateK8sManifests($config);
        }

        // 6. Install features
        if ($installFeatures) {
            if ($dryRun) {
                $featuresList = array_map(fn ($f) => $f->value, $config->getFeatures());
                $this->line('  <fg=gray>[PHP]</> Would install features: '.implode(', ', $featuresList));
            } else {
                $config->installComponents();
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');
        }
    }
}
