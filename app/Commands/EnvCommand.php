<?php

namespace App\Commands;

use App\Contracts\HasPromptableHosts;
use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\IngressController;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class EnvCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

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
            $this->call('cloud:configure', ['action' => 'gha']);

            return;
        }

        $this->line('  <fg=gray>Next steps:</>');
        $this->line("  1. Preview the merged manifests:  <fg=yellow>larakube kustomize {$envName}</>");
        $this->line("  2. Set up CI/CD (per-env workflow + branch):  <fg=yellow>larakube cloud:configure gha</> (choose '{$envName}')");
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

        // Web host: optional. Empty = no host configured (env still works on internal/dev.test domains).
        $webHost = text(
            label: "Web host for {$envName} (optional, e.g. staging.example.com)",
            placeholder: 'leave blank to skip',
            required: false,
        );
        if ($webHost !== '') {
            $envData->hosts['web'] = $webHost;
        }

        // Per-service host overrides — only for genuinely client-facing
        // endpoints worth a vanity subdomain (Reverb's WebSocket host, an
        // object-storage S3/CDN host). Admin consoles (search dashboards,
        // Mailpit, metrics) are intentionally NOT prompted — they still get
        // a derived ingress host, but the wizard stays quiet. Anything can
        // still be set by hand in .larakube.json.
        foreach ($this->resolveEnvComponents($config, $envName, $envData) as $component) {
            if (! $component instanceof HasPromptableHosts) {
                continue;
            }
            foreach ($component->getPromptableHostServices() as $service => $label) {
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
