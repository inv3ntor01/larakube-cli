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
use App\Enums\CacheDriver;
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
        protected ?array $blueprints = [],
        protected ?string $serverVariation = null,
        protected ?string $frontend = null,
        protected ?string $phpVersion = null,
        protected ?string $os = null,
        protected ?string $email = null,
        protected ?array $additionalExtensions = [],
        protected ?array $features = [],
        protected ?string $scoutDriver = null,
        protected ?array $scoutDrivers = [],
        protected ?string $packageManager = null,
        protected ?string $objectStorage = null,
        protected ?array $objectStorages = [],
        protected ?string $cacheDriver = null,
        protected ?array $cacheDrivers = [],
        protected ?string $database = null,
        protected ?array $databases = [],
        protected ?array $environments = [],
        protected ?string $productionImage = null,
        protected ?bool $githubActions = true,
        protected ?bool $isSystem = false,
        protected bool $isScaffolding = false,
        protected ?array $lockedFiles = [],
    ) {
        $this->id = $id ?? (string) Str::uuid();
    }

    public function getLockedFiles(): array
    {
        return $this->lockedFiles ?? [];
    }

    public function isLocked(string $path): bool
    {
        $relative = str_replace($this->getPath().'/', '', $path);

        return in_array($relative, $this->getLockedFiles());
    }

    public function isSystem(): bool
    {
        return $this->isSystem ?? false;
    }

    public function setIsSystem(bool $isSystem): void
    {
        $this->isSystem = $isSystem;
    }

    public function isScaffolding(): bool
    {
        return $this->isScaffolding;
    }

    public function setIsScaffolding(bool $isScaffolding): void
    {
        $this->isScaffolding = $isScaffolding;
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

    public function setBlueprints(array $blueprints): void
    {
        $this->blueprints = array_values(array_unique(array_map(fn ($b) => $b instanceof Blueprint ? $b->value : $b, $blueprints)));
    }

    /**
     * @return Blueprint[]
     */
    public function getBlueprints(): array
    {
        return ! empty($this->blueprints) ? array_filter(array_map(fn (string $b) => Blueprint::tryFrom($b), $this->blueprints)) : [];
    }

    public function hasBlueprints(): bool
    {
        return ! empty($this->blueprints);
    }

    public function hasBlueprint(Blueprint $blueprint): bool
    {
        return in_array($blueprint->value, $this->blueprints, true);
    }

    public function addBlueprint(Blueprint $blueprint): void
    {
        $this->blueprints = array_values(array_unique(array_merge($this->blueprints, [$blueprint->value])));
    }

    public function removeBlueprint(Blueprint $blueprint): void
    {
        $this->blueprints = array_values(array_diff($this->blueprints, [$blueprint->value]));
    }

    public function setServerVariation(?ServerVariation $serverVariation): void
    {
        $this->serverVariation = $serverVariation?->value;
    }

    public function getServerVariation(): ?ServerVariation
    {
        return $this->serverVariation ? ServerVariation::from($this->serverVariation) : null;
    }

    public function hasServerVariation(): bool
    {
        return ! is_null($this->serverVariation);
    }

    public function setFrontend(?FrontendStack $frontend): void
    {
        $this->frontend = $frontend?->value;
    }

    public function getFrontend(): ?FrontendStack
    {
        return $this->frontend ? FrontendStack::from($this->frontend) : null;
    }

    public function setPhpVersion(PhpVersion $phpVersion): void
    {
        $this->phpVersion = $phpVersion->value;
    }

    public function getPhpVersion(): PhpVersion
    {
        return $this->phpVersion ? PhpVersion::from($this->phpVersion) : PhpVersion::PHP_8_5;
    }

    public function hasPhpVersion(): bool
    {
        return ! is_null($this->phpVersion);
    }

    public function setOs(OperatingSystem $os): void
    {
        $this->os = $os->value;
    }

    public function getOs(): OperatingSystem
    {
        return $this->os ? OperatingSystem::from($this->os) : OperatingSystem::ALPINE;
    }

    public function hasOs(): bool
    {
        return ! is_null($this->os);
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function hasEmail(): bool
    {
        return ! is_null($this->email);
    }

    public function setAdditionalExtensions(array $extensions): void
    {
        $this->additionalExtensions = array_values(array_unique($extensions));
    }

    public function getAdditionalExtensions(): array
    {
        return $this->additionalExtensions ?? [];
    }

    public function hasAdditionalExtensions(): bool
    {
        return $this->additionalExtensions && count($this->additionalExtensions) > 0;
    }

    public function addAdditionalExtension(string ...$extension): void
    {
        $this->additionalExtensions = array_values(array_unique(array_merge($this->additionalExtensions, $extension)));
    }

    public function removeAdditionalExtension(string ...$extension): void
    {
        $this->additionalExtensions = array_values(array_diff($this->additionalExtensions, $extension));
    }

    public function setFeatures(array $features): void
    {
        $this->features = array_values(array_unique(array_map(function (LaravelFeature|string $feature) {
            return $feature instanceof LaravelFeature ? $feature->value : LaravelFeature::tryFrom($feature)?->value;
        }, $features)));
    }

    public function getFeatures(): array
    {
        return ! empty($this->features) ? array_filter(array_map(fn (string $feature) => LaravelFeature::tryFrom($feature), $this->features)) : [];
    }

    public function hasFeatures(): bool
    {
        return $this->features && count($this->features) > 0;
    }

    public function hasFeature(LaravelFeature $feature): bool
    {
        return in_array($feature->value, $this->features, true);
    }

    public function addFeature(LaravelFeature ...$feature): void
    {
        $this->features = array_values(array_unique(array_merge($this->features, array_map(fn ($f) => $f->value, $feature))));
    }

    public function removeFeature(LaravelFeature ...$feature): void
    {
        $this->features = array_values(array_diff($this->features, array_map(fn ($f) => $f->value, $feature)));
    }

    public function setScoutDriver(?ScoutDriver $scoutDriver): void
    {
        $this->scoutDriver = $scoutDriver?->value;
    }

    public function getScoutDriver(): ?ScoutDriver
    {
        return $this->scoutDriver ? ScoutDriver::from($this->scoutDriver) : null;
    }

    public function setScoutDrivers(array $drivers): void
    {
        $this->scoutDrivers = array_values(array_unique(array_map(fn ($d) => $d instanceof ScoutDriver ? $d->value : ScoutDriver::tryFrom($d)?->value, $drivers)));
    }

    public function addScoutDriver(ScoutDriver ...$driver): void
    {
        foreach ($driver as $d) {
            if (is_null($this->scoutDriver)) {
                $this->scoutDriver = $d->value;
            } elseif ($this->scoutDriver !== $d->value) {
                $this->scoutDrivers = array_values(array_unique(array_merge($this->scoutDrivers, [$d->value])));
            }
        }
    }

    /**
     * @return ScoutDriver[]
     */
    public function getScoutDrivers(): array
    {
        $all = [];
        if ($primary = $this->getScoutDriver()) {
            $all[] = $primary;
        }
        foreach ($this->scoutDrivers as $d) {
            if ($driver = ScoutDriver::tryFrom($d)) {
                $all[] = $driver;
            }
        }

        return array_unique($all, SORT_REGULAR);
    }

    public function setPackageManager(PackageManager $packageManager): void
    {
        $this->packageManager = $packageManager->value;
    }

    public function getPackageManager(): ?PackageManager
    {
        return $this->packageManager ? PackageManager::from($this->packageManager) : null;
    }

    public function hasPackageManager(): bool
    {
        return ! is_null($this->packageManager);
    }

    public function setObjectStorage(?StorageDriver $objectStorage): void
    {
        $this->objectStorage = $objectStorage?->value;
    }

    public function getObjectStorage(): ?StorageDriver
    {
        return $this->objectStorage ? StorageDriver::tryFrom($this->objectStorage) : null;
    }

    public function setObjectStorages(array $storages): void
    {
        $this->objectStorages = array_values(array_unique(array_map(fn ($s) => $s instanceof StorageDriver ? $s->value : StorageDriver::tryFrom($s)?->value, $storages)));
    }

    public function addObjectStorage(StorageDriver ...$storage): void
    {
        foreach ($storage as $s) {
            if (is_null($this->objectStorage)) {
                $this->objectStorage = $s->value;
            } elseif ($this->objectStorage !== $s->value) {
                $this->objectStorages = array_values(array_unique(array_merge($this->objectStorages, [$s->value])));
            }
        }
    }

    /**
     * @return StorageDriver[]
     */
    public function getObjectStorages(): array
    {
        $all = [];
        if ($primary = $this->getObjectStorage()) {
            $all[] = $primary;
        }
        foreach ($this->objectStorages as $s) {
            if ($driver = StorageDriver::tryFrom($s)) {
                $all[] = $driver;
            }
        }

        return array_unique($all, SORT_REGULAR);
    }

    public function setCacheDriver(CacheDriver $cacheDriver): void
    {
        $this->cacheDriver = $cacheDriver->value;
    }

    public function getCacheDriver(): CacheDriver
    {
        return $this->cacheDriver ? CacheDriver::from($this->cacheDriver) : CacheDriver::DATABASE;
    }

    public function setCacheDrivers(array $drivers): void
    {
        $this->cacheDrivers = array_values(array_unique(array_map(fn ($d) => $d instanceof CacheDriver ? $d->value : CacheDriver::tryFrom($d)?->value, $drivers)));
    }

    public function addCacheDriver(CacheDriver ...$driver): void
    {
        foreach ($driver as $d) {
            if (is_null($this->cacheDriver)) {
                $this->cacheDriver = $d->value;
            } elseif ($this->cacheDriver !== $d->value) {
                $this->cacheDrivers = array_values(array_unique(array_merge($this->cacheDrivers, [$d->value])));
            }
        }
    }

    /**
     * @return CacheDriver[]
     */
    public function getCacheDrivers(): array
    {
        $all = [];
        if ($primary = $this->getCacheDriver()) {
            $all[] = $primary;
        }
        foreach ($this->cacheDrivers as $d) {
            if ($driver = CacheDriver::tryFrom($d)) {
                $all[] = $driver;
            }
        }

        return array_unique($all, SORT_REGULAR);
    }

    public function hasCacheDriver(): bool
    {
        return ! is_null($this->cacheDriver) || count($this->cacheDrivers) > 0;
    }

    public function setDatabase(DatabaseDriver $database): void
    {
        $this->database = $database->value;
    }

    public function getDatabase(): DatabaseDriver
    {
        return $this->database ? DatabaseDriver::from($this->database) : DatabaseDriver::SQLITE;
    }

    public function setDatabases(array $databases): void
    {
        $this->databases = array_values(array_unique(array_map(function (DatabaseDriver|string $database) {
            return $database instanceof DatabaseDriver ? $database->value : DatabaseDriver::tryFrom($database)?->value;
        }, $databases)));
    }

    public function hasDatabases(): bool
    {
        return ! is_null($this->database) || ($this->databases && count($this->databases) > 0);
    }

    public function hasDatabase(DatabaseDriver $database): bool
    {
        if ($this->database === $database->value) {
            return true;
        }

        return in_array($database->value, $this->databases, true);
    }

    public function addDatabase(DatabaseDriver ...$database): void
    {
        foreach ($database as $db) {
            if (is_null($this->database)) {
                $this->database = $db->value;
            } elseif ($this->database !== $db->value) {
                $this->databases = array_values(array_unique(array_merge($this->databases, [$db->value])));
            }
        }
    }

    public function removeDatabase(DatabaseDriver ...$database): void
    {
        foreach ($database as $db) {
            if ($this->database === $db->value) {
                $this->database = null;
            }
        }
        $this->databases = array_values(array_diff($this->databases, array_map(fn ($d) => $d->value, $database)));
    }

    /**
     * Get ALL databases (Primary + Additional)
     *
     * @return DatabaseDriver[]
     */
    public function getDatabases(): array
    {
        $databases = array_unique(array_filter([$this->database, ...($this->databases ?? [])]));
        $all = [];

        foreach ($databases as $db) {
            if ($driver = DatabaseDriver::tryFrom($db)) {
                $all[] = $driver;
            }
        }

        return $all;
    }

    /**
     * Alias for getDatabase() with a fallback.
     */
    public function getPrimaryDatabase(): DatabaseDriver
    {
        return $this->getDatabase() ?? DatabaseDriver::MYSQL;
    }

    /**
     * Get the core dependencies that the main application needs.
     *
     * @return AsDependency[]
     */
    public function getCoreDependencies(): array
    {
        return array_filter([
            $this->getPrimaryDatabase(),
            $this->getCacheDriver(),
        ], fn ($dep) => $dep instanceof AsDependency);
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
                // Skip invalid hosts or ports
                if (empty($host) || empty($port)) {
                    continue;
                }

                // If it's explicitly the host machine or a known local-only file driver
                if ($host !== '127.0.0.1' && $host !== 'localhost' && $host !== 'sqlite') {
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

    public function hasPrimaryDatabase(): bool
    {
        return ! is_null($this->database);
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

    public function setEnvironments(array $environments): void
    {
        $this->environments = $environments;
    }

    public function getEnvironments(): array
    {
        return $this->environments ?? ['local', 'production'];
    }

    public function setProductionImage(?string $image): void
    {
        $this->productionImage = $image;
    }

    public function getProductionImage(): ?string
    {
        return $this->productionImage;
    }

    public static function fromArray(array $data): self
    {
        $config = new self(
            id: $data['id'] ?? null,
            name: $data['name'] ?? null,
            path: $data['path'] ?? null,
            blueprints: $data['blueprints'] ?? [],
            serverVariation: $data['serverVariation'] ?? null,
            frontend: $data['frontend'] ?? null,
            phpVersion: $data['phpVersion'] ?? null,
            os: $data['os'] ?? null,
            email: $data['email'] ?? null,
            additionalExtensions: $data['additionalExtensions'] ?? [],
            features: $data['features'] ?? [],
            scoutDriver: $data['scoutDriver'] ?? null,
            scoutDrivers: $data['scoutDrivers'] ?? [],
            packageManager: $data['packageManager'] ?? null,
            objectStorage: $data['objectStorage'] ?? null,
            objectStorages: $data['objectStorages'] ?? [],
            cacheDriver: $data['cacheDriver'] ?? null,
            cacheDrivers: $data['cacheDrivers'] ?? [],
            database: $data['database'] ?? null,
            databases: $data['databases'] ?? [],
            environments: $data['environments'] ?? ['local', 'production'],
            productionImage: $data['production_image'] ?? null,
            githubActions: $data['githubActions'] ?? true,
            isSystem: $data['is_system'] ?? false,
            lockedFiles: $data['locked_files'] ?? [],
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

        // --- 🏗 RUNTIME CONTEXT ---
        // We set the path from the actual directory being loaded,
        // ensuring portability across different machines/developers.
        $data['path'] = realpath($directory) ?: $directory;

        return self::fromArray($data);
    }

    public function toArray(): array
    {
        $this->resolveDependencies();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'blueprints' => $this->blueprints,
            'serverVariation' => $this->serverVariation,
            'frontend' => $this->frontend,
            'phpVersion' => $this->phpVersion,
            'os' => $this->os,
            'email' => $this->email,
            'additionalExtensions' => $this->additionalExtensions,
            'features' => $this->features,
            'scoutDriver' => $this->scoutDriver,
            'scoutDrivers' => $this->scoutDrivers,
            'packageManager' => $this->packageManager,
            'objectStorage' => $this->objectStorage,
            'objectStorages' => $this->objectStorages,
            'cacheDriver' => $this->cacheDriver,
            'cacheDrivers' => $this->cacheDrivers,
            'database' => $this->database,
            'databases' => $this->databases,
            'environments' => $this->environments,
            'production_image' => $this->productionImage,
            'githubActions' => $this->githubActions,
            'is_system' => $this->isSystem,
            'locked_files' => $this->lockedFiles,
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

    /**
     * Surgically install a single component and its dependencies.
     */
    public function installComponent(object $component): void
    {
        $projectPath = $this->getPath();
        $composerPackages = [];
        $artisanCommands = [];
        $jsCommands = [];

        if ($component instanceof HasComposerDependencies) {
            $composerPackages = $component->getComposerDependencies($this);
        }

        if ($component instanceof HasArtisanCommands) {
            foreach ($component->getArtisanCommands($this) as $cmd) {
                $artisanCommands[] = "php artisan $cmd";
            }
        }

        if ($component instanceof HasJsDependencies) {
            $jsCommands = $component->getJsDependencies($this);
        }

        if ($component instanceof HasLifecycleHooks) {
            $component->onPostInstall($projectPath, $this);
        }

        // Execute PHP installation if needed
        if (! empty($composerPackages) || (! empty($artisanCommands) && $this->isScaffolding())) {
            $phpCommands = [];
            $noScripts = $this->isScaffolding() ? '' : ' --no-scripts';

            if (! empty($composerPackages)) {
                $phpCommands[] = 'composer require '.implode(' ', array_unique($composerPackages)).' --with-all-dependencies --ignore-platform-reqs'.$noScripts;
            }

            if ($this->isScaffolding()) {
                foreach ($artisanCommands as $cmd) {
                    $phpCommands[] = $cmd;
                }
            }

            // Inject a safe environment for Artisan commands to prevent connection errors on boot
            $safeEnv = '-e REDIS_CLIENT=null -e CACHE_STORE=array -e SESSION_DRIVER=array -e DB_CONNECTION=sqlite';
            $this->runInContainer(implode(' && ', $phpCommands), $projectPath, envs: $safeEnv);
        }

        // Execute JS installation if needed
        if (! empty($jsCommands)) {
            $js = [...$jsCommands, $this->getPackageManager()->buildCommand()];
            $this->runInContainer(implode(' && ', $js), $projectPath, $this->getFrontend()->getPodName($this));
        }
    }

    public function installComponents(): void
    {
        $projectPath = $this->getPath();
        $appName = $this->getName();

        $pods = $this->getComponents();

        $composerPackages = [];
        $devComposerPackages = [];
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
        if (! empty($composerPackages) || (! empty($artisanCommands) && $this->isScaffolding())) {
            $this->laraKubeInfo('Installing PHP requirements...');

            $phpCommands = [];
            $noScripts = $this->isScaffolding() ? '' : ' --no-scripts';

            if (! empty($composerPackages)) {
                $uniquePackages = array_unique($composerPackages);
                $phpCommands[] = 'composer require '.implode(' ', $uniquePackages).' --with-all-dependencies --ignore-platform-reqs'.$noScripts;
            }

            if ($this->isScaffolding()) {
                foreach ($artisanCommands as $cmd) {
                    $phpCommands[] = $cmd;
                }
            }

            // Inject a safe environment for Artisan commands to prevent connection errors on boot
            $safeEnv = '-e REDIS_CLIENT=null -e CACHE_STORE=array -e SESSION_DRIVER=array -e DB_CONNECTION=sqlite';
            $this->runInContainer(implode(' && ', $phpCommands), $projectPath, envs: $safeEnv);
        }

        // JS

        if (! empty($jsCommands)) {
            $this->laraKubeInfo('Installing JS packages and building assets...');

            $js = [...$jsCommands, $this->getPackageManager()->buildCommand()];

            $this->runInContainer(implode(' && ', $js), $projectPath, $this->getFrontend()->getPodName($this));
        }
    }

    public function hardenViteConfig(): void
    {
        $projectPath = $this->path;
        $viteFile = file_exists("$projectPath/vite.config.ts") ? "$projectPath/vite.config.ts" : "$projectPath/vite.config.js";

        if (! file_exists($viteFile)) {
            return;
        }

        $content = file_get_contents($viteFile);
        $appName = $this->getName();
        $viteHost = "vite-{$appName}.dev.test";

        // Check if the config is already "K8s Ready"
        $isK8sReady = str_contains($content, "host: '{$viteHost}'") && str_contains($content, 'cors: true');

        // 1. Aggressive Cleanups (ONLY for new scaffolding)
        if ($this->isScaffolding()) {
            $this->laraKubeInfo('Hardening Vite configuration for Kubernetes...');

            // Strip Wayfinder
            $content = preg_replace("/import\s+({?\s*wayfinder\s*}?)\s+from\s+['\"].*?wayfinder.*?['\"];?\n?/s", '', $content);
            $content = preg_replace("/\bwayfinder\s*\((?:[^()]|(?R))*\),?\n?/s", '', $content);

            // Disable Inertia SSR
            $content = preg_replace('/inertia\(\)/', 'inertia({ ssr: false })', $content);
        }

        // 2. Network Alignment
        // We only auto-edit if there is NO server block, or if we are scaffolding.
        // If it's an existing project with a custom server block, we stay hands-off and advise.
        if (! str_contains($content, 'server: {') || $this->isScaffolding()) {
            $harden = view('k8s.viteserver', ['viteHost' => $viteHost])->render();

            if (! str_contains($content, 'server: {')) {
                $content = preg_replace('/(defineConfig\s*\(\s*\{)/', "$1\n{$harden}", $content);
            } else {
                // Scaffolding update for existing block
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
            ...$this->getBlueprints(),
            $this->getServerVariation(),
            ...$this->getDatabases(),
            ...$this->getCacheDrivers(),
            ...$this->getScoutDrivers(),
            ...$this->getObjectStorages(),
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
                        } elseif ($dependency instanceof CacheDriver && ! in_array($dependency, $this->getCacheDrivers())) {
                            $this->addCacheDriver($dependency);
                            $settled = false;
                        } elseif ($dependency instanceof StorageDriver && ! in_array($dependency, $this->getObjectStorages())) {
                            $this->addObjectStorage($dependency);
                            $settled = false;
                        }
                    }
                }
            }
        }
    }

    public function hasCacheStore(string $store): bool
    {
        return $this->cacheDriver === $store || in_array($store, $this->cacheDrivers, true);
    }

    public function hasFeatureName(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function getAllEnvironmentVariables(): array
    {
        $envs = $this->getServerVariation()?->getEnvironmentVariables($this) ?? [];

        // 🛡️ DEFAULT PHP PERFORMANCE & STABILITY
        $envs = array_merge([
            'APP_URL' => $this->getAppUrl(),
            'ASSET_URL' => $this->getAppUrl(),
        ], $envs);

        if ($this->getFrontend()?->requiresNodePod()) {
            $envs['VITE_URL'] = "https://vite-{$this->getName()}.dev.test";
        }

        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasEnvironmentVariables) {
                $envs = array_merge($envs, $component->getEnvironmentVariables($this));
            }
        }

        return $envs;
    }

    public function getAllPhpExtensions(): array
    {
        $extensions = $this->getAdditionalExtensions();

        foreach ($this->getComponents() as $component) {
            if ($component instanceof RequiresPhpExtensions) {
                $extensions = array_merge($extensions, $component->getPhpExtensions());
            }
        }

        return array_values(array_unique(array_filter($extensions)));
    }

    public function hasPhpExtensions(): bool
    {
        return count($this->getAllPhpExtensions()) > 0;
    }

    public function getAllHosts(): array
    {
        $hosts = [
            "{$this->getName()}.dev.test" => 'Primary Application',
        ];

        if ($this->getFrontend()?->requiresNodePod()) {
            $hosts["vite-{$this->getName()}.dev.test"] = 'Vite Asset Server';
        }

        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasHosts) {
                $hosts = array_merge($hosts, $component->getHosts($this));
            }
        }

        return $hosts;
    }
}
