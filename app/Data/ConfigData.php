<?php

namespace App\Data;

use App\Contracts\AsDependency;
use App\Contracts\HasArtisanCommands;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasJsDependencies;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\RequiresPhpExtensions;
use App\Enums\Blueprint;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithDocker;
use App\Traits\LaraKubeOutput;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class ConfigData implements Arrayable
{
    use InteractsWithDocker, LaraKubeOutput;

    const string CONFIG_FILE = '.larakube.json';

    public function __construct(
        protected ?string $id = null,
        protected ?string $name = null,
        protected ?string $path = null,
        protected ?string $blueprint = null,
        protected ?string $serverVariation = null,
        protected ?string $frontend = null,
        protected ?string $phpVersion = null,
        protected ?string $os = null,
        protected ?string $email = null,
        protected ?array $additionalExtensions = [],
        protected ?array $features = [],
        protected ?string $scoutDriver = null,
        protected ?string $packageManager = null,
        protected ?string $objectStorage = null,
        protected ?array $databases = [],
        protected ?array $environments = [],
        protected ?bool $githubActions = true,
    ) {
        $this->id = $id ?? (string) Str::uuid();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setPath(string $path, bool $skip_check = false): void
    {
        if ($skip_check || is_dir($path)) {
            $this->path = $path;
        }
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getInfrastructurePath(): ?string
    {
        return $this->path ? "$this->path/.infrastructure" : null;
    }

    public function getK8sPath(): ?string
    {
        return $this->path ? "{$this->getInfrastructurePath()}/k8s" : null;
    }

    public function setBlueprint(Blueprint $blueprint): void
    {
        $this->blueprint = $blueprint->value;
    }

    public function hasBlueprint(): bool
    {
        return ! is_null($this->blueprint);
    }

    public function getBlueprint(): ?Blueprint
    {
        return $this->blueprint ? Blueprint::tryFrom($this->blueprint) : null;
    }

    public function getDefaultBlueprint(): Blueprint
    {
        return Blueprint::LARAVEL;
    }

    public function setServerVariation(ServerVariation $serverVariation): void
    {
        $this->serverVariation = $serverVariation->value;

        if ($serverVariation === ServerVariation::FRANKENPHP) {
            $this->addFeature(LaravelFeature::OCTANE);
        }
    }

    public function hasServerVariation(): bool
    {
        return ! is_null($this->serverVariation);
    }

    public function getServerVariation(): ?ServerVariation
    {
        return $this->serverVariation ? ServerVariation::tryFrom($this->serverVariation) : null;
    }

    public function getDefaultServerVariation(): ServerVariation
    {
        return ServerVariation::FPM_NGINX;
    }

    public function setFrontend(FrontendStack $frontend): void
    {
        $this->frontend = $frontend->value;
    }

    public function getFrontend(): ?FrontendStack
    {
        return $this->frontend ? FrontendStack::tryFrom($this->frontend) : null;
    }

    public function setPhpVersion(PhpVersion $phpVersion): void
    {
        $this->phpVersion = $phpVersion->value;
    }

    public function hasPhpVersion(): bool
    {
        return ! is_null($this->phpVersion);

    }

    public function getPhpVersion(): PhpVersion
    {
        return $this->phpVersion ? PhpVersion::tryFrom($this->phpVersion) : PhpVersion::PHP_8_5;
    }

    public function setOs(OperatingSystem $os): void
    {
        $this->os = $os->value;
    }

    public function hasOs(): bool
    {
        return ! is_null($this->os);
    }

    public function getOs(): OperatingSystem
    {
        return $this->os ? OperatingSystem::tryFrom($this->os) : OperatingSystem::ALPINE;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function hasEmail(): bool
    {
        return ! is_null($this->email);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setAdditionalExtensions(array $additionalExtensions): void
    {
        $this->additionalExtensions = $additionalExtensions;
    }

    public function hasAdditionalExtensions(): bool
    {
        return count($this->getAdditionalExtensions()) > 0;
    }

    public function addAdditionalExtension(string ...$extension): void
    {
        $this->additionalExtensions = array_values(array_unique(array_merge($this->additionalExtensions, $extension)));
    }

    public function getAdditionalExtensions(): array
    {
        $extensions = $this->additionalExtensions ?? [];

        foreach ($this->getComponents() as $component) {
            if ($component instanceof RequiresPhpExtensions) {
                $extensions = array_merge($extensions, $component->getPhpExtensions());
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * @param  LaravelFeature[]  $features
     */
    public function setFeatures(array $features): void
    {
        $this->features = Arr::map($features, fn ($feature) => $feature instanceof LaravelFeature ? $feature->value : $feature);
    }

    public function hasFeatures(): bool
    {
        return count($this->features) > 0;
    }

    public function hasFeature(LaravelFeature $feature): bool
    {
        return in_array($feature->value, $this->features, true);
    }

    public function addFeature(LaravelFeature ...$feature): void
    {
        $this->features = array_values(array_unique(array_merge($this->features, array_map(fn (LaravelFeature $f) => $f->value, $feature))));
    }

    public function removeFeature(LaravelFeature ...$feature): void
    {
        $this->features = array_values(array_diff($this->features, array_map(fn ($f) => $f->value, $feature)));

    }

    /**
     * @return LaravelFeature[]
     */
    public function getFeatures(): array
    {
        $selected = array_map(fn (string $feature) => LaravelFeature::tryFrom($feature), array_unique($this->features ?? []));

        return array_values(array_filter(array_unique(array_merge($selected, LaravelFeature::getAutoUsedComponents()), SORT_REGULAR)));
    }

    public function setScoutDriver(ScoutDriver $driver): void
    {
        $this->scoutDriver = $driver->value;
    }

    public function removeScoutDriver(ScoutDriver $driver): bool
    {
        if ($this->getScoutDriver() === $driver) {
            $this->scoutDriver = null;

            return true;
        }

        return false;
    }

    public function getScoutDriver(): ?ScoutDriver
    {
        return $this->scoutDriver ? ScoutDriver::tryFrom($this->scoutDriver) : null;
    }

    public function setPackageManager(PackageManager $packageManager): void
    {
        $this->packageManager = $packageManager->value;
    }

    public function hasPackageManager(): bool
    {
        return ! is_null($this->packageManager);
    }

    public function getPackageManager(): PackageManager
    {
        return $this->packageManager ? PackageManager::tryFrom($this->packageManager) : PackageManager::NPM;
    }

    public function setObjectStorage(StorageDriver $storage): void
    {
        $this->objectStorage = $storage->value;
    }

    public function removeObjectStorage(StorageDriver $storage): bool
    {
        if ($this->getObjectStorage() === $storage) {
            $this->objectStorage = null;

            return true;
        }

        return false;
    }

    public function getObjectStorage(): ?StorageDriver
    {
        return $this->objectStorage ? StorageDriver::tryFrom($this->objectStorage) : null;
    }

    public function setDatabases(array $databases): void
    {
        $this->databases = array_unique(array_map(function (DatabaseDriver|string $database) {
            return $database instanceof DatabaseDriver ? $database->value : DatabaseDriver::tryFrom($database)?->value;
        }, $databases));
    }

    public function hasDatabases(): bool
    {
        return $this->databases && count($this->databases) > 0;
    }

    public function hasDatabase(DatabaseDriver $database): bool
    {
        return in_array($database->value, $this->databases, true);
    }

    public function addDatabase(DatabaseDriver ...$database): void
    {
        $this->databases = array_values(array_unique(array_merge($this->databases, array_map(fn ($d) => $d->value, $database))));
    }

    public function removeDatabase(DatabaseDriver ...$database): void
    {
        $this->databases = array_values(array_diff($this->databases, array_map(fn ($d) => $d->value, $database)));
    }

    /**
     * @return DatabaseDriver[]
     */
    public function getDatabases(): array
    {
        return ! empty($this->databases) ? Arr::map($this->databases, fn (string $database) => DatabaseDriver::tryFrom($database)) : [];
    }

    public function getPrimaryDatabase(): DatabaseDriver
    {
        return Arr::first($this->getDatabases(), fn (DatabaseDriver $db) => $db->isPersistent()) ?? DatabaseDriver::MYSQL;
    }

    /**
     * Get the core dependencies that the main application needs.
     *
     * @return AsDependency[]
     */
    public function getCoreDependencies(): array
    {
        $deps = [$this->getPrimaryDatabase()];

        if ($this->hasDatabase(DatabaseDriver::REDIS)) {
            $deps[] = DatabaseDriver::REDIS;
        }

        return $deps;
    }

    /**
     * Build the Kubernetes initContainer command for waiting on dependencies.
     *
     * @param  AsDependency[]  $dependencies
     */
    public function buildWaitForCommand(array $dependencies): ?string
    {
        if (empty($dependencies)) {
            return null;
        }

        $checks = [];
        foreach ($dependencies as $dep) {
            foreach ($dep->getDependencyConfig($this) as $host => $port) {
                // If it's a local host (127.0.0.1), it's not a k8s dependency to wait for
                if ($host !== '127.0.0.1') {
                    $checks[] = "nc -z -v -w 1 $host $port";
                }
            }
        }

        if (empty($checks)) {
            return null;
        }

        $cmd = implode(' && ', $checks);

        return "[\"sh\", \"-c\", \"until $cmd; do echo 'Waiting for dependencies...'; sleep 2; done;\"]";
    }

    public function hasPersistentDatabase(): bool
    {
        foreach ($this->getDatabases() as $database) {
            if ($database->isPersistent()) {
                return true;
            }
        }

        return false;
    }

    public function setGithubActions(bool $githubActions): void
    {
        $this->githubActions = $githubActions;
    }

    public function hasGithubActions(): bool
    {
        return ! is_null($this->githubActions);
    }

    public function getGithubActions(): bool
    {
        return $this->githubActions ?? true;
    }

    public function getAppUrl(): string
    {
        return "https://{$this->getName()}.dev.test";
    }

    /**
     * Get all active architectural components.
     */
    public function getComponents(): array
    {
        return array_filter([
            $this->getBlueprint(),
            $this->getServerVariation(),
            ...$this->getDatabases(),
            $this->getScoutDriver(),
            $this->getObjectStorage(),
            ...$this->getFeatures(),
        ]);
    }

    /**
     * Recursively resolve and apply all component dependencies to the configuration state.
     * Only loops as long as the state is actually modified, safely handling circular dependencies.
     */
    public function resolveDependencies(): void
    {
        $settled = false;

        while (! $settled) {
            $settled = true;
            $components = $this->getComponents();

            foreach ($components as $component) {
                if ($component instanceof HasDependencies) {
                    foreach ($component->getDependencies($this) as $dependency) {
                        if ($dependency instanceof ServerVariation && $this->getServerVariation() !== $dependency) {
                            $this->setServerVariation($dependency);
                            $settled = false;
                        } elseif ($dependency instanceof LaravelFeature && ! $this->hasFeature($dependency)) {
                            $this->addFeature($dependency);
                            $settled = false;
                        } elseif ($dependency instanceof DatabaseDriver && ! $this->hasDatabase($dependency)) {
                            $this->addDatabase($dependency);
                            $settled = false;
                        } elseif ($dependency instanceof StorageDriver && $this->getObjectStorage() !== $dependency) {
                            $this->setObjectStorage($dependency);
                            $settled = false;
                        } elseif ($dependency instanceof ScoutDriver && $this->getScoutDriver() !== $dependency) {
                            $this->setScoutDriver($dependency);
                            $settled = false;
                        }
                    }
                }
            }
        }
    }

    /**
     * Aggregate environment variables from all components.
     */
    public function getAllEnvironmentVariables(): array
    {
        $appUrl = $this->getAppUrl();
        $envs = [
            'APP_URL' => $appUrl,
            'ASSET_URL' => $appUrl,
        ];

        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasEnvironmentVariables) {
                $envs = array_merge($envs, $component->getEnvironmentVariables($this));
            }
        }

        return $envs;
    }

    /**
     * Aggregate host URLs from all components.
     */
    public function getAllHosts(): array
    {
        $hosts = [
            "{$this->getName()}.dev.test" => 'Application',
            "vite.{$this->getName()}.dev.test" => 'Vite HMR',
        ];

        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasHosts) {
                $hosts = array_merge($hosts, $component->getHosts($this));
            }
        }

        return $hosts;
    }

    public function getEnvironments(): array
    {
        return $this->environments ?? [];
    }

    public function setEnvironments(array $environments): void
    {
        $this->environments = array_values(array_unique($environments));
    }

    public function addEnvironment(string $name): void
    {
        $envs = $this->getEnvironments();
        $envs[] = $name;
        $this->setEnvironments($envs);
    }

    public static function fromArray(array $data): self
    {
        $config = new self(
            id: $data['id'] ?? null,
            name: $data['name'] ?? null,
            path: $data['path'] ?? null,
            blueprint: $data['blueprint'] ?? null,
            serverVariation: $data['serverVariation'] ?? null,
            frontend: $data['frontend'] ?? null,
            phpVersion: $data['phpVersion'] ?? null,
            os: $data['os'] ?? null,
            email: $data['email'] ?? null,
            additionalExtensions: $data['additionalExtensions'] ?? [],
            features: $data['features'] ?? [],
            scoutDriver: $data['scoutDriver'] ?? null,
            packageManager: $data['packageManager'] ?? null,
            objectStorage: $data['objectStorage'] ?? 'none',
            databases: $data['databases'] ?? [],
            environments: $data['environments'] ?? ['local', 'production'],
            githubActions: $data['githubActions'] ?? true,
        );

        $config->resolveDependencies();

        return $config;
    }

    public static function loadFromFile(string $directory): self
    {
        $path = "$directory/".self::CONFIG_FILE;

        if (! file_exists($path)) {
            throw new RuntimeException("LaraKube DNA not found at: {$path}");
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("LaraKube DNA is malformed at: {$path}");
        }

        return self::fromArray($data);
    }

    public function toArray(): array
    {
        $this->resolveDependencies();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'blueprint' => $this->blueprint,
            'serverVariation' => $this->serverVariation,
            'frontend' => $this->frontend,
            'phpVersion' => $this->phpVersion,
            'os' => $this->os,
            'email' => $this->email,
            'additionalExtensions' => $this->additionalExtensions,
            'features' => $this->features,
            'scoutDriver' => $this->scoutDriver,
            'packageManager' => $this->packageManager,
            'objectStorage' => $this->objectStorage,
            'databases' => $this->databases,
            'environments' => $this->environments,
            'githubActions' => $this->githubActions,
        ];
    }

    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function backupToCluster(string $namespace): bool
    {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Use a temporary file to avoid shell escaping issues with large JSON blobs
        $tmpFile = tempnam(sys_get_temp_dir(), 'larakube-cfg');
        file_put_contents($tmpFile, $json);

        // Create secret with metadata labels for global discovery
        $appName = $this->name ?? 'app';
        $command = 'kubectl create secret generic larakube-blueprint '.
                   "-n {$namespace} ".
                   "--from-file=.larakube.json={$tmpFile} ".
                   '--dry-run=client -o yaml | '.
                   "kubectl label -f - --local larakube.io/project={$appName} larakube.io/config=blueprint -o yaml | ".
                   'kubectl apply -f -';

        exec($command, $output, $result);

        @unlink($tmpFile);

        return $result === 0;
    }

    public static function restoreFromCluster(?string $namespace = null, ?string $appName = null): ?self
    {
        if ($namespace) {
            $command = "kubectl get secret larakube-blueprint -n {$namespace} -o jsonpath='{.data.\\.larakube\\.json}' 2>/dev/null";
        } elseif ($appName) {
            // Search all namespaces for a secret labeled with this app name
            $command = "kubectl get secrets -A -l larakube.io/project={$appName},larakube.io/config=blueprint -o jsonpath='{.items[0].data.\\.larakube\\.json}' 2>/dev/null";
        } else {
            return null;
        }

        $encoded = shell_exec($command);

        if (! $encoded) {
            return null;
        }

        $json = base64_decode($encoded);
        if (! $json) {
            return null;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return self::fromArray($data);
    }

    public function saveToFile(string $directory): void
    {
        $path = "$directory/".self::CONFIG_FILE;

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function getPhpImage(bool $isCli = false): string
    {
        $osSuffix = $this->getOs()?->getSuffix() ?? '';

        $variation = $this->getServerVariation()?->value;

        if ($isCli) {
            $variation = 'cli';
        }

        return "serversideup/php:{$this->getPhpVersion()?->value}-$variation$osSuffix";
    }

    public function getOsSuffix(): string
    {
        $suffix = $this->getOs()?->getSuffix() ?? '';

        if ($this->getServerVariation() === ServerVariation::FPM_APACHE) {
            return '';
        }

        return $suffix;
    }

    public function installComponents(): void
    {
        $projectPath = $this->getPath();
        $appName = $this->getName();

        $pods = $this->getComponents();

        $composerPackages = [];
        $artisanCommands = [];
        $jsCommands = [];

        foreach ($pods as $pod) {
            if ($pod instanceof HasComposerDependencies) {
                $composerPackages = array_merge($composerPackages, $pod->getComposerDependencies($this));
            }

            if ($pod instanceof HasArtisanCommands) {
                foreach ($pod->getArtisanCommands($this) as $cmd) {
                    $artisanCommands[] = "php artisan $cmd";
                }
            }

            if ($pod instanceof HasJsDependencies) {
                $jsCommands = array_merge($jsCommands, $pod->getJsDependencies($this));
            }

            if ($pod instanceof HasLifecycleHooks) {
                $pod->onPostInstall($projectPath, $this);
            }
        }

        // PHP
        if (! empty($composerPackages) || ! empty($artisanCommands)) {
            $this->laraKubeInfo('Installing PHP requirements...');

            $phpCommands = [];

            if (! empty($composerPackages)) {
                $uniquePackages = array_unique($composerPackages);
                $phpCommands[] = 'composer require '.implode(' ', $uniquePackages).' --with-all-dependencies --ignore-platform-reqs';
            }

            foreach ($artisanCommands as $cmd) {
                $phpCommands[] = $cmd;
            }

            $this->runInContainer(implode(' && ', $phpCommands), $projectPath);
        }

        // JS

        if (! empty($jsCommands)) {
            $this->laraKubeInfo('Installing JS packages and building assets...');

            $js = [...$jsCommands, $this->getPackageManager()->buildCommand()];

            // Remove Wayfinder from config BEFORE running build
            $this->removeWayfinderFromViteConfig($projectPath);
            $this->configureViteHmr($projectPath, $appName);
            $this->removeDuplicateReverbImports($projectPath);

            $this->runInContainer(implode(' && ', $js), $projectPath, 'node');
        }
    }

    // Installation-related

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
                // This version handles the trailing comma more safely
                $cleanContent = preg_replace("/\bwayfinder\s*\((?:[^()]+|(?R))*\),?\r?\n?/s", '', $cleanContent);

                if ($cleanContent !== $content) {
                    file_put_contents($path, $cleanContent);
                    $this->laraKubeInfo("Cleaned up Wayfinder plugin from {$file}");
                }
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
            clientPort: 443,
            protocol: 'wss',
        },
        https: {
            key: fs.readFileSync('/usr/src/app/.infrastructure/traefik/certificates/local-dev-key.pem'),
            cert: fs.readFileSync('/usr/src/app/.infrastructure/traefik/certificates/local-dev.pem'),
        },
        cors: true,
    },
JS;

        foreach ($files as $file) {
            $path = $projectPath.'/'.$file;
            if (file_exists($path)) {
                $content = file_get_contents($path);

                if (! str_contains($content, 'server: {')) {
                    // 1. Ensure fs is imported
                    if (! str_contains($content, "import fs from 'fs'")) {
                        $content = "import fs from 'fs';\n".$content;
                    }

                    // 2. Inject before plugins array
                    $newContent = preg_replace('/export\s+default\s+defineConfig\s*\(\s*\{/', "export default defineConfig({\n{$hmrConfig}", $content);
                    file_put_contents($path, $newContent);
                    $this->laraKubeInfo("Configured Vite HMR in {$file}");
                }
            }
        }
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
}
