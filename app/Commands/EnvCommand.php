<?php

namespace App\Commands;

use App\Contracts\HasHosts;
use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\IngressController;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class EnvCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env {name? : The name of the new environment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Kubernetes environment overlay';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $appName = $config->getName() ?? basename($projectPath);

        $envName = $this->argument('name') ?? text(
            label: 'What is the name of the new environment?',
            placeholder: 'staging',
            required: true
        );

        $baseOverlayPath = "{$projectPath}/.infrastructure/k8s/overlays";
        $newEnvPath = "{$baseOverlayPath}/{$envName}";

        if (! is_dir("{$baseOverlayPath}/production")) {
            $this->laraKubeError('Base production environment not found.');

            return 1;
        }

        if (is_dir($newEnvPath)) {
            $this->laraKubeInfo("Environment '{$envName}' filesystem structure already exists.");
        } else {
            $this->laraKubeInfo("Creating environment '{$envName}'...");

            @mkdir($newEnvPath, 0755, true);

            // 1. Create .env.{env} file
            $newEnvFile = ".env.{$envName}";
            if (! file_exists($projectPath.'/'.$newEnvFile)) {
                copy($projectPath.'/.env', $projectPath.'/'.$newEnvFile);
                $this->laraKubeInfo("Created {$newEnvFile}");
            }

            // 2. Update .gitignore
            $gitignorePath = $projectPath.'/.gitignore';
            if (file_exists($gitignorePath)) {
                $gitignore = file_get_contents($gitignorePath);
                if (! str_contains($gitignore, '.env.*')) {
                    $gitignore .= "\n.env.*\n";
                    file_put_contents($gitignorePath, $gitignore);
                    $this->laraKubeInfo('Updated .gitignore to exclude .env.* files');
                }
            }

            // Copy from production as a safe base
            $files = ['kustomization.yaml', 'namespace.yaml', 'deployment-patch.yaml', 'ingress-patch.yaml'];
            foreach ($files as $file) {
                if (! file_exists("{$baseOverlayPath}/production/{$file}")) {
                    continue;
                }

                $content = file_get_contents("{$baseOverlayPath}/production/{$file}");

                // Update the namespace in the new files
                $oldNamespace = "{$appName}-production";
                $newNamespace = "{$appName}-{$envName}";
                $content = str_replace($oldNamespace, $newNamespace, $content);

                file_put_contents("{$newEnvPath}/{$file}", $content);
            }
        }

        // 3. Update Project DNA — gather per-env settings if this is a fresh env
        if (! $config->hasEnvironment($envName)) {
            $envData = $this->gatherEnvironmentData($config, $envName);
            $config->addEnvironment($envName, $envData);
        } else {
            $this->laraKubeInfo("Environment '{$envName}' already exists in DNA; keeping current settings.");
        }
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("Environment '{$envName}' is now part of your project DNA.");
        info("Next steps: larakube up {$envName}");
    }

    /**
     * Prompt for the new environment's per-env overrides (ingress, managed
     * services, web host). All optional — defaults produce an env that uses
     * Traefik, deploys everything locally, and has no external web host.
     */
    protected function gatherEnvironmentData(ConfigData $config, string $envName): EnvironmentData
    {
        $envData = new EnvironmentData;

        // Ingress: rare to differ from Traefik unless infra demands it.
        $ingress = select(
            label: "Which Ingress Controller will {$envName} use?",
            options: IngressController::getSelectOptions($config),
            default: IngressController::TRAEFIK->value,
        );
        $envData->ingress = IngressController::from($ingress);

        // Managed services: only meaningful if the project has databases/caches.
        $managedOptions = [];
        foreach ($config->getComponents() as $component) {
            if ($component instanceof DatabaseDriver || $component instanceof CacheDriver) {
                $managedOptions[$component->value] = $component->getLabel();
            }
        }
        if (! empty($managedOptions)) {
            $envData->managed = multiselect(
                label: "Which services are managed externally in {$envName} (e.g. AWS RDS, ElastiCache)?",
                options: $managedOptions,
                hint: 'Selected services will be skipped in this env\'s manifests.',
            );
        }

        // Web host: optional. Empty = no host configured (env still works on internal/dev.test domains).
        $webHost = text(
            label: "Web host for {$envName} (optional, e.g. staging.example.com)",
            placeholder: 'leave blank to skip',
            required: false,
        );
        if ($webHost !== '') {
            $envData->hosts['web'] = $webHost;
        }

        // Per-service host overrides — fully data-driven. Iterates every
        // component active in this env that declares overrideable services
        // via HasHosts::getHostServices(). The canonical case is Reverb on
        // ws.example.com (subdomain that doesn't share the web host prefix
        // scheme); StorageDriver's s3/s3-console and other features benefit
        // the same way. Skipping web here — already prompted above.
        foreach ($this->resolveEnvComponents($config, $envName, $envData) as $component) {
            if (! $component instanceof HasHosts) {
                continue;
            }
            foreach ($component->getHostServices() as $service => $label) {
                if ($service === 'web') {
                    continue;
                }
                $override = text(
                    label: "Custom host for {$label} in {$envName} (optional)",
                    placeholder: 'leave blank to derive from web host',
                    required: false,
                );
                if ($override !== '') {
                    $envData->hosts[$service] = $override;
                }
            }
        }

        return $envData;
    }

    /**
     * Project components that would be active in the new env, evaluated
     * with the freshly-gathered EnvironmentData so per-env feature filters
     * (addFeatures/excludeFeatures) are respected even before the env is
     * persisted to the config.
     */
    protected function resolveEnvComponents(ConfigData $config, string $envName, EnvironmentData $envData): array
    {
        // Briefly install the in-progress EnvironmentData so getFeatures()
        // sees the right addFeatures/excludeFeatures for this env, then
        // restore the prior map.
        $previous = $config->environments[$envName] ?? null;
        $config->environments[$envName] = $envData;

        try {
            return $config->getComponents($envName);
        } finally {
            if ($previous === null) {
                unset($config->environments[$envName]);
            } else {
                $config->environments[$envName] = $previous;
            }
        }
    }
}
