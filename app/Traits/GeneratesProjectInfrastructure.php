<?php

namespace App\Traits;

use App\Contracts\HasKubernetesFiles;
use App\Contracts\RemovableWhenManaged;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use Random\RandomException;

trait GeneratesProjectInfrastructure
{
    use InteractsWithHosts, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Sync values to the .env file.
     */
    protected function syncEnvFile(string $projectPath, array $values, bool $commented = false, bool $isProduction = false): void
    {
        $envFile = $isProduction ? '.env.production' : '.env';
        $envPath = $projectPath.'/'.$envFile;

        // --- 🛡️ SECURITY: Locked File Protection ---
        // If the user has manually locked this file in .larakube.json, we stay hands-off.
        if (method_exists($this, 'getProjectConfig')) {
            $config = $this->getProjectConfig($projectPath);
            if ($config->isLocked($envFile)) {
                return;
            }
        }

        if (! file_exists($envPath)) {
            if ($isProduction && file_exists($projectPath.'/.env')) {
                copy($projectPath.'/.env', $envPath);
            } else {
                return;
            }
        }

        $lines = explode("\n", file_get_contents($envPath));
        $newLines = [];
        $processedKeys = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($values as $key => $value) {
                if (preg_match("/^#?\s*{$key}=.*/", $line)) {
                    $prefix = $commented ? '# ' : '';
                    $newLines[] = "{$prefix}{$key}={$value}";
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
                $prefix = $commented ? '# ' : '';
                $newLines[] = "{$prefix}{$key}={$value}";
            }
        }

        $content = implode("\n", $newLines);
        file_put_contents($envPath, $content);

        // If we are updating the base .env and .env.production is missing, create it
        if (! $isProduction && ! file_exists($projectPath.'/.env.production')) {
            file_put_contents($projectPath.'/.env.production', $content);
        }
    }

    protected function generateDockerfiles(ConfigData $config): void
    {
        $projectPath = $config->getPath();

        if (! $config->isLocked('Dockerfile.php')) {
            $phpDockerfile = view('docker.php', ['config' => $config])->render();
            file_put_contents("$projectPath/Dockerfile.php", $phpDockerfile);
        }

        $this->generateDockerIgnore($config);
        $this->hardenGitIgnore($config);
    }

    protected function hardenGitIgnore(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $gitIgnorePath = "$projectPath/.gitignore";

        if (! file_exists($gitIgnorePath)) {
            return;
        }

        $content = file_get_contents($gitIgnorePath);
        $rules = [
            '# LaraKube Local Infrastructure (Dynamic paths)',
            '.infrastructure/k8s/overlays/local',
        ];

        $toAdd = [];
        foreach ($rules as $rule) {
            if (! str_contains($content, $rule)) {
                $toAdd[] = $rule;
            }
        }

        if (! empty($toAdd)) {
            $this->laraKubeInfo('Hardening .gitignore to exclude local infrastructure paths...');
            $newContent = trim($content)."\n\n".implode("\n", $toAdd)."\n";
            file_put_contents($gitIgnorePath, $newContent);
        }
    }

    protected function generateDockerIgnore(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $ignoreFile = view('docker.ignore', ['config' => $config])->render();
        file_put_contents("$projectPath/.dockerignore", $ignoreFile);
    }

    protected function generateK8sManifests(ConfigData $config): void
    {
        $config->resolveDependencies();

        $appName = $config->getName();
        $k8sPath = $config->getK8sPath();
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

        // 1. Generate consolidated core stubs (Stacked Architecture).
        // Cloud overlays (production, staging, qa, …) all share the same
        // environment-parameterized templates that live under
        // resources/views/k8s/overlays/production — they're rendered once per
        // cloud environment with that env's namespace + environment context.
        $cloudEnvs = $config->getCloudEnvironments();

        $baseStubs = [
            'base/kustomization.yaml',
            'base/laravel.yaml',
            'base/config.yaml',
            'base/volumes.yaml',
        ];

        $localStubs = [
            'overlays/local/kustomization.yaml',
            'overlays/local/infrastructure.yaml',
            'overlays/local/patches.yaml',
            'overlays/local/config-patch.yaml',
            'overlays/local/pvc-patch.yaml',
        ];
        if ($config->getFrontend()?->requiresNodePod()) {
            $localStubs[] = 'overlays/local/node-deployment.yaml';
        }

        $cloudStubFiles = [
            'kustomization.yaml',
            'namespace.yaml',
            'deployment-patch.yaml',
            'ingress-patch.yaml',
            'config-patch.yaml',
        ];

        $binaryPath = realpath($_SERVER['argv'][0]) ?: '/usr/local/bin/larakube';
        $workspacePath = dirname($config->getPath());

        $renderStub = function (string $stub, string $environment, string $namespace, string $viewName) use ($config, $k8sPath, $binaryPath, $workspacePath) {
            $command = $config->getServerVariation()?->getStartCommand($environment === 'local') ?? '[]';
            $content = view($viewName, [
                'config' => $config,
                'namespace' => $namespace,
                'environment' => $environment,
                'command' => $command,
                'binaryPath' => $binaryPath,
                'workspacePath' => $workspacePath,
            ])->render();

            if (! $config->isLocked(".infrastructure/k8s/{$stub}")) {
                file_put_contents("$k8sPath/$stub", $content);
            }
        };

        // Base layer (environment-agnostic; rendered with the local command
        // context and the bare app namespace, matching prior behaviour).
        foreach ($baseStubs as $stub) {
            $renderStub($stub, 'local', $appName, 'k8s.'.str_replace(['/', '.yaml'], ['.', ''], $stub));
        }

        // Local overlay.
        foreach ($localStubs as $stub) {
            $renderStub($stub, 'local', "{$appName}-local", 'k8s.'.str_replace(['/', '.yaml'], ['.', ''], $stub));
        }

        // Cloud overlays — one directory per non-local environment.
        foreach ($cloudEnvs as $env) {
            @mkdir("$k8sPath/overlays/$env", 0755, true);
            foreach ($cloudStubFiles as $file) {
                $stub = "overlays/$env/$file";
                $viewName = 'k8s.overlays.production.'.str_replace('.yaml', '', $file);
                $renderStub($stub, $env, "{$appName}-{$env}", $viewName);
            }
        }

        // 2. APPLY ACTIONS (write component-owned manifest files to disk)
        foreach ($config->getComponents() as $pod) {
            if ($pod instanceof HasKubernetesFiles) {
                $pod->updateK8s($config);
            }
        }

        // 3. SYNCHRONIZED REGISTRATION (Explicit Deduplication)
        // Base + local are environment-agnostic — collected from the full
        // component set.
        $base = $local = $localPatches = [];
        foreach ($config->getComponents() as $pod) {
            if (! $pod instanceof HasKubernetesFiles) {
                continue;
            }
            $files = $pod->getManifestFiles($config);
            $base = array_merge($base, $files['base'] ?? []);
            $local = array_merge($local, $files['local'] ?? []);
            $localPatches = array_merge($localPatches, $files['patches'] ?? []);
        }

        foreach (array_unique($base) as $file) {
            $this->appendToKustomization($k8sPath, 'base', $file);
        }
        foreach (array_unique($local) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file);
        }
        foreach (array_unique($localPatches) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file, 'patches');
        }

        // Cloud overlays — registered per environment, honouring per-env
        // feature filtering (getComponents($env)) and skipping services that
        // are externally managed in that env.
        foreach ($cloudEnvs as $env) {
            $managed = $config->getManaged($env);
            $cloudFiles = [];

            foreach ($config->getComponents($env) as $pod) {
                if (! $pod instanceof HasKubernetesFiles) {
                    continue;
                }

                // Externally managed in this env: don't register its cloud
                // volumes, and emit a delete-patch so its base Deployment/
                // Service is removed from this overlay (local keeps it).
                if (in_array($pod->value, $managed, true)) {
                    if ($pod instanceof RemovableWhenManaged) {
                        $this->writeManagedDeletePatch($k8sPath, $env, $pod, $config);
                    }

                    continue;
                }

                $files = $pod->getManifestFiles($config);
                $cloudFiles = array_merge($cloudFiles, $files['cloud'] ?? []);
            }

            foreach (array_unique($cloudFiles) as $file) {
                $this->appendToKustomization($k8sPath, "overlays/$env", $file);
            }
            $this->appendToKustomization($k8sPath, "overlays/$env", 'ingress-patch.yaml', 'patches');
        }
    }

    /**
     * Write (and register) a kustomize delete-patch that removes an externally
     * managed service's base resources from a cloud environment's overlay.
     */
    protected function writeManagedDeletePatch(string $k8sPath, string $env, RemovableWhenManaged $pod, ConfigData $config): void
    {
        $resources = $pod->getManagedResources($config);
        if (empty($resources)) {
            return;
        }

        $apiVersionFor = fn (string $kind): string => match ($kind) {
            'Service' => 'v1',
            default => 'apps/v1',
        };

        $docs = [];
        foreach ($resources as $resource) {
            $docs[] = implode("\n", [
                'apiVersion: '.$apiVersionFor($resource['kind']),
                'kind: '.$resource['kind'],
                'metadata:',
                '  name: '.$resource['name'],
                '$patch: delete',
            ]);
        }

        $filename = "{$pod->value}-managed-delete.yaml";
        $dest = "overlays/$env/$filename";
        if (! $config->isLocked(".infrastructure/k8s/{$dest}")) {
            file_put_contents("$k8sPath/$dest", implode("\n---\n", $docs)."\n");
        }
        $this->appendToKustomization($k8sPath, "overlays/$env", $filename, 'patches');
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
                $oldContent = $content;
                $content = preg_replace('/resources:\s*\n/', "resources:\n  - $filename\n", $content, 1);
                if ($oldContent === $content) {
                    // This means it didn't match.
                    $this->laraKubeError("Failed to append $filename to $kustomizationFile. Regex mismatch.");
                }
            }
        } elseif ($type === 'patches') {
            if (! str_contains($content, "path: $filename")) {
                if (! str_contains($content, 'patches:')) {
                    $content .= "\npatches:\n";
                }
                $content = preg_replace('/patches:\s*\n/', "patches:\n  - path: $filename\n", $content, 1);
            }
        }

        file_put_contents($kustomizationFile, $content);
    }

    protected function removeResourceFromKustomization(string $k8sPath, string $folder, string $filename): void
    {
        $kustomizationFile = "$k8sPath/$folder/kustomization.yaml";
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);

        // Remove from resources list
        $newContent = preg_replace("/^\s*-\s*".preg_quote($filename, '/')."\s*$/m", '', $content);

        if ($newContent !== $content) {
            file_put_contents($kustomizationFile, $newContent);
        }
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
    protected function orchestrateProjectScaffolding(ConfigData $config, bool $installFeatures = true, bool $buildImage = true, bool $dryRun = false, bool $syncK8s = true, bool $syncEnv = true): void
    {
        if ($dryRun) {
            $this->laraKubeInfo("Architectural Preview for '{$config->getName()}':");
        }

        // --- 🛡 SECURITY GUARD: SYSTEM PROJECTS ---
        if ($config->isSystem()) {
            $globalConfigPath = $_SERVER['HOME'].'/.larakube/config.json';
            $globalConfig = file_exists($globalConfigPath) ? json_decode(file_get_contents($globalConfigPath), true) : [];
            $trusted = $globalConfig['trusted_system_uuids'] ?? [];

            if (! in_array($config->id, $trusted)) {
                $shouldTrust = false;

                if ($this->hasOption('force') && $this->option('force')) {
                    $shouldTrust = true;
                } else {
                    $this->newLine();
                    $this->warn(' ⚠ SECURITY WARNING: This project is requesting "System" status.');
                    $this->warn('   It will have READ/WRITE access to your global LaraKube project registry and logs.');
                    $this->newLine();

                    if (\Laravel\Prompts\confirm("Do you trust this project ({$config->getName()}) and want to grant system access?", false)) {
                        $shouldTrust = true;
                    }
                }

                if ($shouldTrust) {
                    $trusted[] = $config->id;
                    $globalConfig['trusted_system_uuids'] = array_unique($trusted);
                    @mkdir($_SERVER['HOME'].'/.larakube', 0755, true);
                    file_put_contents($globalConfigPath, json_encode($globalConfig, JSON_PRETTY_PRINT));
                    $this->laraKubeInfo('Project UUID added to global trusted list.');
                } else {
                    $this->laraKubeError('Access Denied. Disabling system status for this run.');
                    $config->setIsSystem(false);
                }
            }
        }

        // 0. Ensure architectural dependencies are resolved
        $config->resolveDependencies();

        // 0. Persist configuration for self-healing
        if ($dryRun) {
            $this->line('  <fg=gray>[FILE]</> Would create .larakube.json with project blueprint.');
            $this->line("  <fg=gray>[DB]</> Would register project '{$config->getName()}' in internal database.");
        } else {
            $this->saveProjectConfig($config->getPath(), $config);
        }

        // 1. Handle Blueprint extensions (Laravel is the implicit base)
        if (! $config->hasBlueprints()) {
            // Optional: You can keep it empty or add LARAVEL as a marker
            // $config->addBlueprint(Blueprint::LARAVEL);
        }

        // 2. Sync .env (Source of Truth)
        if ($syncEnv) {
            $envChanges = $config->getAllEnvironmentVariables();

            if ($dryRun) {
                $this->line('  <fg=gray>[.ENV]</> Would sync the following variables to local .env and .env.production:');
                foreach ($envChanges as $k => $v) {
                    $this->line("         $k=$v");
                }

                $prodEnvChanges = $config->getAllEnvironmentVariables('production');
                $this->line('  <fg=gray>[.ENV.PRODUCTION]</> Would sync the following variables ONLY to .env.production:');
                foreach ($prodEnvChanges as $k => $v) {
                    if ($v !== ($envChanges[$k] ?? null)) {
                        $this->line("         $k=$v");
                    }
                }
            } else {
                $this->syncEnvFile($config->getPath(), $envChanges);
                // Specifically sync production-only overrides to .env.production
                $this->syncEnvFile($config->getPath(), $config->getAllEnvironmentVariables('production'), isProduction: true);
            }
        }

        if (! $dryRun) {
            $this->ensureHttpsCompatibility($config);
            $this->hardenViteConfig($config);
        }

        // 4. Generate Dockerfiles
        if ($syncK8s) {
            if ($dryRun) {
                $this->line("  <fg=gray>[FILE]</> Would generate Dockerfile.php ({$config->getServerVariation()?->value}).");
            } else {
                $this->generateDockerfiles($config);
            }
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
        if ($syncK8s) {
            if ($dryRun) {
                $this->line('  <fg=gray>[K8S]</> Would generate base and overlay manifests in .infrastructure/k8s/');
            } else {
                $this->generateK8sManifests($config);
            }
        }

        // 6. Install features
        if ($installFeatures) {
            if ($dryRun) {
                $featuresList = array_map(fn ($f) => $f->value, $config->getFeatures());
                $this->line('  <fg=gray>[PHP]</> Would install features: '.implode(', ', $featuresList));
            } else {
                $this->installComponents($config);
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');
        }
    }

    public function hardenViteConfig(ConfigData $config): void
    {
        $projectPath = $config->getPath();
        $viteFile = file_exists("$projectPath/vite.config.ts") ? "$projectPath/vite.config.ts" : "$projectPath/vite.config.js";

        if (! file_exists($viteFile)) {
            return;
        }

        $content = file_get_contents($viteFile);
        $viteHost = $config->getServiceHost('vite', 'local');

        // Check if the config is already "K8s Ready"
        $isK8sReady = str_contains($content, "host: '{$viteHost}'") && str_contains($content, 'cors: true');

        // 1. Aggressive Cleanups (ONLY for new scaffolding)
        if ($config->isScaffolding) {
            $this->laraKubeInfo('Hardening Vite configuration for Kubernetes...');

            // Strip Wayfinder
            $content = preg_replace("/import\s+({?\s*wayfinder\s*}?)\s+from\s+['\"].*?wayfinder.*?['\"];?\n?/s", '', $content);
            $content = preg_replace("/\bwayfinder\s*\((?:[^()]|(?R))*\),?\n?/s", '', $content);

            // Disable Inertia SSR
            $content = preg_replace('/inertia\(\)/', 'inertia({ ssr: false })', $content);
        }

        // 2. Network Alignment
        if (! str_contains($content, 'server: {') || $config->isScaffolding) {
            $harden = view('k8s.viteserver', ['viteHost' => $viteHost])->render();

            if (! str_contains($content, 'server: {')) {
                $content = preg_replace('/(defineConfig\s*\(\s*\{)/', "$1\n{$harden}", $content);
            } else {
                $content = preg_replace("/origin:\s*['\"].*?\.dev\.test['\"]/", "origin: 'https://{$viteHost}'", $content);
                $content = preg_replace("/host:\s*['\"].*?\.dev\.test['\"]/", "host: '{$viteHost}'", $content);
            }

            file_put_contents($viteFile, $content);
        } elseif (! $isK8sReady) {
            $harden = view('k8s.viteserver', ['viteHost' => $viteHost])->render();
            $this->laraKubeNewLine();
            $this->laraKubeWarn(" ⚠ VITE ADVISORY: Your {$viteFile} looks custom.");
            $this->laraKubeLine("   To ensure HMR works in Kubernetes, please ensure your 'server' block includes:");
            $this->laraKubeNewLine();
            $this->laraKubeLine($harden);
            $this->laraKubeNewLine();
        }
    }
}
