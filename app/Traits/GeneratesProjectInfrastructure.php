<?php

namespace App\Traits;

use App\Enums\Blueprint;
use App\Enums\DatabaseEngine;
use App\Enums\LaravelFeature;
use App\Enums\PackageManager;
use App\Enums\ServerVariation;
use Illuminate\Support\Str;
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

        $env = file_get_contents($envPath);

        foreach ($values as $key => $value) {
            // Pattern to match the key even if it's commented out
            $pattern = "/^#?\s*{$key}=.*/m";
            $replacement = "{$key}={$value}";

            if (preg_match($pattern, $env)) {
                $env = preg_replace($pattern, $replacement, $env);
            } else {
                $env .= "\n{$replacement}";
            }
        }

        file_put_contents($envPath, $env);

        if (file_exists($projectPath.'/.env.production')) {
            file_put_contents($projectPath.'/.env.production', $env);
        }
    }

    protected function generateDockerfiles($projectPath, $serverVariation, $phpVersion, $os, $additionalExtensions): void
    {
        $osSuffix = $os === 'alpine' ? '-alpine' : '';
        if ($serverVariation === 'fpm-apache') {
            $osSuffix = '';
        }

        $phpExtensions = implode(' ', $additionalExtensions);

        $phpDockerfile = file_get_contents(base_path('resources/stubs/Dockerfile.php.stub'));
        $phpDockerfile = str_replace(
            ['{{PHP_VERSION}}', '{{SERVER_VARIATION}}', '{{OS_SUFFIX}}', '{{PHP_EXTENSIONS}}'],
            [$phpVersion, $serverVariation, $osSuffix, $phpExtensions],
            $phpDockerfile
        );

        if (empty($additionalExtensions)) {
            $phpDockerfile = str_replace('RUN install-php-extensions', '# RUN install-php-extensions', $phpDockerfile);
        }

        file_put_contents($projectPath.'/Dockerfile.php', $phpDockerfile);

        $nodeDockerfile = file_get_contents(base_path('resources/stubs/Dockerfile.node.stub'));
        file_put_contents($projectPath.'/Dockerfile.node', $nodeDockerfile);
    }

    protected function generateK8sManifests($projectPath, $appName, $email, $databases, $features, $serverVariation, $context = []): void
    {
        $k8sPath = $projectPath.'/.infrastructure/k8s';
        @mkdir($projectPath.'/.infrastructure', 0755, true);
        @mkdir($k8sPath.'/base', 0755, true);
        @mkdir($k8sPath.'/overlays/local', 0755, true);
        @mkdir($k8sPath.'/overlays/production', 0755, true);

        $host = $appName.'.dev.test';

        $dbEngines = collect($databases)->map(fn ($db) => DatabaseEngine::from($db));
        $primaryDb = $dbEngines->first(fn ($db) => $db->isPersistent()) ?? DatabaseEngine::MYSQL;

        $dbConnection = $primaryDb->dbConnection();
        $dbHost = $primaryDb->dbHost();
        $dbPort = $primaryDb->dbPort();
        $dbUsername = $primaryDb->dbUsername();
        $redisHost = $dbEngines->contains(DatabaseEngine::REDIS) ? 'redis' : '127.0.0.1';

        $server = ServerVariation::from($serverVariation);
        $containerPort = $server->containerPort();
        $traefikScheme = $server->traefikScheme();

        // Calculate Node Command - Added --host so readiness probes can reach port 5173
        $pm = PackageManager::from($context['packageManager'] ?? 'npm');
        $nodeCommand = match ($pm) {
            PackageManager::YARN => '["sh", "-c", "'.$pm->installCommand().' && yarn dev --host"]',
            PackageManager::PNPM => '["sh", "-c", "'.$pm->installCommand().' && pnpm dev --host"]',
            PackageManager::BUN => '["sh", "-c", "'.$pm->installCommand().' && bun run dev --host"]',
            default => '["sh", "-c", "'.$pm->installCommand().' && npm run dev -- --host"]',
        };

        $this->laraKubeInfo('Generating Kubernetes manifests...');

        // 1. Generate ALL core stubs first (Clean slate)
        $stubs = [
            'base/kustomization.yaml', 'base/deployment.yaml', 'base/service.yaml', 'base/ingress.yaml',
            'base/pvc.yaml',
            'overlays/local/kustomization.yaml', 'overlays/local/namespace.yaml', 'overlays/local/node-deployment.yaml', 'overlays/local/mailpit.yaml', 'overlays/local/deployment-patch.yaml', 'overlays/local/ingress-patch.yaml', 'overlays/local/laravel-volumes.yaml',
            'overlays/production/kustomization.yaml', 'overlays/production/namespace.yaml', 'overlays/production/deployment-patch.yaml',
        ];

        foreach ($stubs as $stub) {
            $content = file_get_contents(base_path("resources/stubs/k8s/{$stub}.stub"));

            $namespace = $appName;
            if (str_contains($stub, 'overlays/local')) {
                $namespace = $appName.'-local';
            } elseif (str_contains($stub, 'overlays/production')) {
                $namespace = $appName.'-production';
            }

            // Calculate environment-aware command
            $command = '[]';
            if ($server === ServerVariation::FRANKENPHP) {
                $watchFlag = str_contains($stub, 'overlays/local') ? ', "--watch"' : '';
                $command = "[\"php\", \"artisan\", \"octane:start\", \"--server=frankenphp\", \"--port=8080\", \"--host=0.0.0.0\"{$watchFlag}]";
            }

            $content = str_replace(
                ['{{IMAGE_NAME}}', '{{APP_NAME}}', '{{HOST}}', '{{DB_CONNECTION}}', '{{APP_KEY}}', '{{DB_PASSWORD}}', '{{EMAIL}}', '{{NAMESPACE}}', '{{PROJECT_PATH}}', '{{MEILI_MASTER_KEY}}', '{{DB_HOST}}', '{{DB_PORT}}', '{{DB_USERNAME}}', '{{CONTAINER_PORT}}', '{{TRAEFIK_SCHEME}}', '{{COMMAND}}', '{{NODE_COMMAND}}', '{{REDIS_HOST}}'],
                [$appName, $appName, $host, $dbConnection, 'base64:'.base64_encode(random_bytes(32)), 'secretpassword', $email, $namespace, realpath($projectPath), 'secretpassword', $dbHost, $dbPort, $dbUsername, $containerPort, $traefikScheme, $command, $nodeCommand, $redisHost],
                $content
            );

            file_put_contents($k8sPath.'/'.$stub, $content);
        }

        // 2. APPLY ACTIONS & COLLECT CATEGORIZED MANIFESTS
        $manifests = [
            'base' => [],
            'local' => [],
            'production' => [],
            'patches' => [],
        ];

        // Apply Blueprint
        $blueprintName = $context['blueprint'] ?? Blueprint::LARAVEL->value;
        $blueprint = Blueprint::from($blueprintName);
        if ($blueprintAction = $blueprint->action()) {
            $blueprintAction->apply($projectPath, $k8sPath, $appName, $context);
            $actionFiles = $blueprintAction->getManifestFiles();
            foreach (['base', 'local', 'production', 'patches'] as $key) {
                if (isset($actionFiles[$key])) {
                    $manifests[$key] = array_merge($manifests[$key], $actionFiles[$key]);
                }
            }
        }

        // Add Features
        foreach ($features as $featureName) {
            $feature = LaravelFeature::tryFrom($featureName);
            if ($feature && $action = $feature->action()) {
                $action->updateK8s($k8sPath, $appName, [
                    'projectPath' => $projectPath,
                ]);
                $actionFiles = $action->getManifestFiles();
                foreach (['base', 'local', 'production', 'patches'] as $key) {
                    if (isset($actionFiles[$key])) {
                        $manifests[$key] = array_merge($manifests[$key], $actionFiles[$key]);
                    }
                }
            }
        }

        // Add Databases
        foreach ($dbEngines as $engine) {
            if ($action = $engine->action()) {
                $action->updateK8s($k8sPath, $appName, ['projectPath' => $projectPath]);
                $actionFiles = $action->getManifestFiles();
                foreach (['base', 'local', 'production', 'patches'] as $key) {
                    if (isset($actionFiles[$key])) {
                        $manifests[$key] = array_merge($manifests[$key], $actionFiles[$key]);
                    }
                }
            }
        }

        // 3. SYNCHRONIZED REGISTRATION (Explicit Deduplication)
        foreach (array_unique($manifests['base']) as $file) {
            $this->appendToKustomization($k8sPath, 'base', $file, 'resources');
        }
        foreach (array_unique($manifests['local']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file, 'resources');
        }
        foreach (array_unique($manifests['production']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/production', $file, 'resources');
        }
        foreach (array_unique($manifests['patches']) as $file) {
            $this->appendToKustomization($k8sPath, 'overlays/local', $file, 'patches');
        }
    }

    protected function appendToKustomization(string $k8sPath, string $folder, string $filename, string $type = 'resources'): void
    {
        $kustomizationFile = $k8sPath.'/'.$folder.'/kustomization.yaml';
        if (! file_exists($kustomizationFile)) {
            return;
        }

        $content = file_get_contents($kustomizationFile);

        if ($type === 'resources') {
            if (! str_contains($content, "  - {$filename}") && ! str_contains($content, "- {$filename}")) {
                $content = preg_replace('/resources:\n/', "resources:\n  - {$filename}\n", $content, 1);
            }
        } elseif ($type === 'patches') {
            if (! str_contains($content, "path: {$filename}")) {
                if (! str_contains($content, 'patches:')) {
                    $content .= "\npatches:\n";
                }
                $content = preg_replace('/patches:\n/', "patches:\n  - path: {$filename}\n", $content, 1);
            }
        }

        file_put_contents($kustomizationFile, $content);
    }

    protected function generateDockerCompose($projectPath, $appName, $packageManager, $databases, $features, $serverVariation): void
    {
        $content = file_get_contents(base_path('resources/stubs/docker-compose.yml.stub'));
        file_put_contents($projectPath.'/docker-compose.yml', $content);

        $dbEngines = collect($databases)->map(fn ($db) => DatabaseEngine::from($db));

        // Add databases via Actions
        foreach ($dbEngines as $engine) {
            if ($action = $engine->action()) {
                $action->updateDockerCompose($projectPath);
            }
        }
    }

    /**
     * @throws RandomException
     */
    protected function installLaravelFeatures(string $projectPath, string $appName, array $features, string $packageManager, array $context = []): void
    {
        // 1. Gather and categorize commands
        $composerPackages = [];
        $artisanCommands = [];
        $jsCommands = [];

        $pmEnum = PackageManager::from($packageManager);

        // Add Blueprint commands
        $blueprintName = $context['blueprint'] ?? Blueprint::LARAVEL->value;
        $blueprint = Blueprint::from($blueprintName);
        if ($blueprintAction = $blueprint->action()) {
            $blueprintCmds = $blueprintAction->getInstallCommands($context);
            foreach ($blueprintCmds as $cmd) {
                if (str_starts_with($cmd, 'composer require ')) {
                    $packages = str_replace('composer require ', '', $cmd);
                    $composerPackages = array_merge($composerPackages, explode(' ', $packages));
                } elseif (str_starts_with($cmd, 'php artisan ')) {
                    $artisanCommands[] = $cmd;
                } else {
                    $jsCommands[] = $cmd;
                }
            }
        }

        foreach ($features as $featureName) {
            $feature = LaravelFeature::tryFrom($featureName);
            if ($feature && $action = $feature->action()) {
                $featureCmds = $action->getInstallCommands([
                    'projectPath' => $projectPath,
                    'packageManager' => $packageManager,
                ]);
                foreach ($featureCmds as $cmd) {
                    if (str_starts_with($cmd, 'composer require ')) {
                        $packages = str_replace('composer require ', '', $cmd);
                        $composerPackages = array_merge($composerPackages, explode(' ', $packages));
                    } elseif (str_starts_with($cmd, 'php artisan ')) {
                        $artisanCommands[] = $cmd;
                    } else {
                        $jsCommands[] = $cmd;
                    }
                }

                // Run onPostInstall
                $action->onPostInstall($projectPath, [
                    'projectPath' => $projectPath,
                ]);
            }
        }

        // Handle Database installation (for DBs that are also features like MongoDB)
        $dbEngines = collect($context['databases'] ?? [])->map(fn ($db) => DatabaseEngine::tryFrom($db));
        foreach ($dbEngines as $engine) {
            if ($engine && $action = $engine->action()) {
                if ($action instanceof FeatureAction) {
                    $dbCmds = $action->getInstallCommands([
                        'projectPath' => $projectPath,
                        'packageManager' => $packageManager,
                    ]);
                    foreach ($dbCmds as $cmd) {
                        if (str_starts_with($cmd, 'composer require ')) {
                            $packages = str_replace('composer require ', '', $cmd);
                            $composerPackages = array_merge($composerPackages, explode(' ', $packages));
                        } elseif (str_starts_with($cmd, 'php artisan ')) {
                            $artisanCommands[] = $cmd;
                        } else {
                            $jsCommands[] = $cmd;
                        }
                    }

                    $action->onPostInstall($projectPath, [
                        'projectPath' => $projectPath,
                    ]);
                }
            }
        }

        // 2. Execute PHP installation (Composer/Artisan)
        if (! empty($composerPackages) || ! empty($artisanCommands)) {
            $this->laraKubeInfo('Installing PHP requirements...');

            $phpCommands = [];

            if (! empty($composerPackages)) {
                $uniquePackages = array_unique($composerPackages);
                $phpCommands[] = 'composer require '.implode(' ', $uniquePackages);
            }

            foreach ($artisanCommands as $cmd) {
                $phpCommands[] = $cmd;
            }

            $this->runInContainer(implode(' && ', $phpCommands), $projectPath, 'php');
        }

        // 3. Execute JS installation and build
        $this->laraKubeInfo('Installing JS packages and building assets...');
        $jsExecution = [];
        foreach ($jsCommands as $cmd) {
            $jsExecution[] = $cmd;
        }
        $jsExecution[] = $pmEnum->buildCommand();

        // Remove Wayfinder from config BEFORE running build
        $this->removeWayfinderFromViteConfig($projectPath);
        $this->configureViteHmr($projectPath, $appName);
        $this->removeDuplicateReverbImports($projectPath);

        $this->runInContainer(implode(' && ', $jsExecution), $projectPath, 'node');
    }

    protected function removeDuplicateReverbImports(string $projectPath): void
    {
        $appTs = $projectPath.'/resources/js/app.ts';
        if (file_exists($appTs)) {
            $content = file_get_contents($appTs);
            // Match the import line and only keep the first one
            $pattern = "/import\s*{\s*configureEcho\s*}\s*from\s*['\"]@laravel\/echo-(vue|react)['\"];?\r?\n?/";

            if (preg_match_all($pattern, $content, $matches) > 1) {
                // Keep only the first occurrence of the import
                $newContent = preg_replace($pattern, '', $content);
                $newContent = $matches[0][0]."\n".$newContent;
                file_put_contents($appTs, $newContent);
                $this->laraKubeInfo('Deduplicated Reverb imports in app.ts');
            }
        }
    }

    protected function configureViteHmr(string $projectPath, string $appName): void
    {
        $files = ['vite.config.ts', 'vite.config.js'];
        $hmrConfig = <<<JS
    server: {
        host: '0.0.0.0',
        strictPort: true,
        port: 5173,
        hmr: {
            host: 'vite.{$appName}.dev.test',
            clientPort: 80,
        },
        cors: true,
    },
JS;

        foreach ($files as $file) {
            $path = $projectPath.'/'.$file;
            if (file_exists($path)) {
                $content = file_get_contents($path);

                if (! str_contains($content, 'server: {')) {
                    // Inject before plugins array
                    $newContent = preg_replace('/export\s+default\s+defineConfig\s*\(\s*\{/', "export default defineConfig({\n{$hmrConfig}", $content);
                    file_put_contents($path, $newContent);
                    $this->laraKubeInfo("Configured Vite HMR in {$file}");
                }
            }
        }
    }

    protected function removeWayfinderFromViteConfig(string $projectPath): void
    {
        $files = ['vite.config.ts', 'vite.config.js'];

        foreach ($files as $file) {
            $path = $projectPath.'/'.$file;
            if (file_exists($path)) {
                $content = file_get_contents($path);

                // 1. Remove explicit wayfinder import
                $cleanContent = preg_replace("/import\s*{\s*wayfinder\s*}\s*from\s*['\"]@laravel\/vite-plugin-wayfinder['\"];?\r?\n?/", '', $content);

                // 2. Remove explicit wayfinder plugin call (including multi-line configuration blocks)
                $cleanContent = preg_replace("/wayfinder\s*\((?:[^()]+|(?R))*\),?\r?\n?/s", '', $cleanContent);

                if ($cleanContent !== $content) {
                    file_put_contents($path, $cleanContent);
                    $this->laraKubeInfo("Cleaned up Wayfinder plugin from {$file}");
                }
            }
        }
    }

    protected function setLaravelStoragePermissions(string $projectPath): void
    {
        $this->laraKubeInfo('Fixing storage permissions...');
        $this->runInContainer('chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache', $projectPath);
    }

    /**
     * Orchestrate the entire project infrastructure generation and installation.
     */
    protected function orchestrateProjectScaffolding(string $projectPath, string $appName, array $config, bool $installFeatures = true, bool $buildImage = true, bool $dryRun = false): void
    {
        if ($dryRun) {
            $this->laraKubeInfo("Architectural Preview for '{$appName}':");
        }

        // 0. Ensure permanent Project ID
        if (! isset($config['id'])) {
            $config['id'] = (string) Str::uuid();
        }

        // 0. Persist configuration for self-healing
        if ($dryRun) {
            $this->line('  <fg=gray>[FILE]</> Would create .larakube.json with project blueprint.');
            $this->line("  <fg=gray>[DB]</> Would register project '{$appName}' in internal database.");
        } else {
            file_put_contents(
                $projectPath.'/.larakube.json',
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // Register in the internal list
            $this->registerProject($projectPath, $config);
        }

        // 1. Handle Blueprint extensions
        $blueprint = Blueprint::from($config['blueprint']);
        $blueprintExtensions = $blueprint->action() ? $blueprint->action()->getPhpExtensions() : [];
        $allExtensions = array_unique(array_merge($config['additionalExtensions'], $blueprintExtensions));

        // 2. Generate Dockerfiles
        if ($dryRun) {
            $this->line("  <fg=gray>[FILE]</> Would generate Dockerfile.php ({$config['serverVariation']}).");
        } else {
            $this->generateDockerfiles($projectPath, $config['serverVariation'], $config['phpVersion'], $config['os'], $allExtensions);
        }

        // 3. Build the local image if requested
        if ($buildImage) {
            if ($dryRun) {
                $this->line("  <fg=gray>[DOCKER]</> Would build image '{$appName}:latest'.");
            } else {
                $this->buildImage($projectPath, $appName);
            }
        }

        // 4. Generate Manifests
        if ($dryRun) {
            $this->line('  <fg=gray>[K8S]</> Would generate base and overlay manifests in .infrastructure/k8s/');
        } else {
            $this->generateK8sManifests($projectPath, $appName, $config['email'], $config['databases'], $config['features'], $config['serverVariation'], $config);
            $this->generateDockerCompose($projectPath, $appName, $config['packageManager'], $config['databases'], $config['features'], $config['serverVariation']);
        }

        // 5. Sync .env
        $dbEngines = collect($config['databases'])->map(fn ($db) => DatabaseEngine::from($db));
        $primaryDb = $dbEngines->first(fn ($db) => $db->isPersistent()) ?? DatabaseEngine::MYSQL;

        $envChanges = [
            'APP_URL' => "http://{$appName}.dev.test",
            'DB_CONNECTION' => $primaryDb->dbConnection(),
            'DB_HOST' => $primaryDb->dbHost(),
            'DB_PORT' => $primaryDb->dbPort(),
            'DB_USERNAME' => $primaryDb->dbUsername(),
            'DB_PASSWORD' => 'secretpassword',
            'DB_DATABASE' => 'laravel',
            'REDIS_HOST' => $dbEngines->contains(DatabaseEngine::REDIS) ? 'redis' : '127.0.0.1',
        ];

        if ($dryRun) {
            $this->line('  <fg=gray>[.ENV]</> Would sync the following variables:');
            foreach ($envChanges as $k => $v) {
                $this->line("         {$k}={$v}");
            }
        } else {
            $this->syncEnvFile($projectPath, $envChanges);
        }

        // 6. Install features
        if ($installFeatures) {
            if ($dryRun) {
                $this->line('  <fg=gray>[PHP]</> Would install features: '.implode(', ', $config['features']));
            } else {
                $this->installLaravelFeatures($projectPath, $appName, $config['features'], $config['packageManager'], $config);
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');
        }
    }
}
