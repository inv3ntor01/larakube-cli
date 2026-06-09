<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Data\RegistryData;
use App\Enums\IngressController;
use App\Enums\RegistryProvider;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\PromptsForHosts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class EnvCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput, PromptsForHosts;

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

        $envName = $this->argument('name') ?? text(
            label: 'What is the name of the new environment?',
            placeholder: 'staging',
            required: true,
        );

        // 1. Per-environment .env file (seeded from the base .env).
        $newEnvFile = ".env.{$envName}";
        if (! file_exists($projectPath.'/'.$newEnvFile) && file_exists($projectPath.'/.env')) {
            copy($projectPath.'/.env', $projectPath.'/'.$newEnvFile);
            $this->laraKubeInfo("Created {$newEnvFile}");
        }

        // 2. Keep .env.* out of version control.
        $gitignorePath = $projectPath.'/.gitignore';
        if (file_exists($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);
            if (! str_contains($gitignore, '.env.*')) {
                file_put_contents($gitignorePath, $gitignore."\n.env.*\n");
                $this->laraKubeInfo('Updated .gitignore to exclude .env.* files');
            }
        }

        // 3. Update Project DNA — gather per-env settings if this is a fresh env.
        if (! $config->hasEnvironment($envName)) {
            $this->laraKubeInfo("Creating environment '{$envName}'...");
            $envData = $this->gatherEnvironmentData($config, $envName);
            $config->addEnvironment($envName, $envData);
        } else {
            $this->laraKubeInfo("Environment '{$envName}' already exists in DNA; keeping current settings.");
        }
        $this->saveProjectConfig($projectPath, $config);

        // 4. Regenerate manifests from the blueprint. The architectural engine
        // is environment-aware, so this produces a complete overlays/{$envName}
        // reflecting THIS env's ingress, hosts, managed services, and feature
        // set — not a copy of production.
        $this->withSpin("Generating manifests for '{$envName}' (and refreshing all environments)...", function () use ($config) {
            $this->orchestrateProjectScaffolding($config, false, false);

            return true;
        });

        $this->laraKubeInfo("Environment '{$envName}' is now part of your project DNA.");
        $this->newLine();

        // 5. Offer to set up the CI/CD deploy workflow for this env now. It's a
        // separate concern (cloud:configure also uploads GitHub secrets via the
        // `gh` CLI, which needs auth), so we ask rather than force it.
        $configureCicd = confirm(
            label: "Set up the GitHub Actions deploy workflow for '{$envName}' now?",
            default: false,
            hint: 'Generates .github/workflows/larakube-deploy-'.$envName.'.yml (you pick its trigger branch) and uploads secrets.',
        );

        if ($configureCicd) {
            $this->call('cloud:configure:gha', ['environment' => $envName]);

            return;
        }

        $this->line('  <fg=gray>Next steps:</>');
        $this->line("  1. Preview the merged manifests:  <fg=yellow>larakube kustomize {$envName}</>");
        $this->line("  2. Set up CI/CD (per-env workflow + branch):  <fg=yellow>larakube cloud:configure:gha {$envName}</>");
        $this->line("  3. Or deploy manually:  <fg=yellow>larakube cloud:deploy {$envName}</>");
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

        // Managed services: every backing service this project could offload
        // to a managed provider (DB, cache, search, object storage).
        $managedOptions = $config->getManageableServices();
        if (! empty($managedOptions)) {
            $envData->managed = multiselect(
                label: "Which services are managed externally in {$envName} (e.g. AWS RDS, ElastiCache, Meilisearch Cloud, S3)?",
                options: $managedOptions,
                hint: 'Selected services will be skipped in this env\'s manifests.',
            );
        }

        // Client-facing hosts — the optional web host plus any HasPromptableHosts
        // service overrides (Reverb WS, object-storage S3/CDN). Shared via
        // PromptsForHosts so the bundle installer and other flows reuse one wizard.
        // Admin consoles aren't prompted; they get a derived ingress host.
        foreach ($this->promptForHosts($envName, $this->resolveEnvComponents($config, $envName, $envData)) as $service => $host) {
            $envData->hosts[$service] = $host;
        }

        // Container registry: optional. Only relevant for cloud environments.
        if ($envName !== 'local') {
            $configureRegistry = confirm(
                label: "Configure a container registry for {$envName}?",
                default: false,
                hint: 'Required for GitHub Actions CI/CD (push to GHCR or Docker Hub)',
            );

            if ($configureRegistry) {
                $provider = select(
                    label: "Which container registry for {$envName}?",
                    options: [
                        RegistryProvider::GHCR->value => RegistryProvider::GHCR->label(),
                        RegistryProvider::DOCKERHUB->value => RegistryProvider::DOCKERHUB->label(),
                    ],
                );

                $image = text(
                    label: 'Image repository path (optional, e.g. owner/repo)',
                    placeholder: "Leave blank for default: {$config->getName()}",
                    required: false,
                );

                $envData->registry = new RegistryData(
                    provider: RegistryProvider::from($provider),
                    image: $image !== '' ? $image : null,
                );
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
