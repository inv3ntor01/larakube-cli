<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\RegistryData;
use App\Enums\RegistryProvider;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudConfigureRegistryCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'cloud:configure registry';

    protected $description = 'Configure container registry for an environment';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Configure Container Registry');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);

        // Pick environment
        $environments = array_keys($config->environments);
        if (empty($environments)) {
            $this->laraKubeError('No environments found in your project.');

            return 1;
        }

        $environment = select(
            label: 'Which environment do you want to configure?',
            options: array_combine($environments, $environments),
        );

        // Skip local (it doesn't need a registry for push)
        if ($environment === 'local') {
            $this->laraKubeInfo('Local environment does not need a container registry (builds locally only).');

            return 0;
        }

        // Pick registry provider
        $provider = select(
            label: "Which container registry for {$environment}?",
            options: [
                RegistryProvider::GHCR->value => RegistryProvider::GHCR->label(),
                RegistryProvider::DOCKERHUB->value => RegistryProvider::DOCKERHUB->label(),
            ],
        );

        // Optional: image path
        $image = text(
            label: "Image repository path (optional, e.g. owner/repo)",
            placeholder: "Leave blank for default: {$config->getName()}",
            required: false,
        );

        // Build RegistryData
        $registryData = new RegistryData(
            provider: RegistryProvider::from($provider),
            image: $image !== '' ? $image : null,
        );

        // Update config
        $config->environments[$environment]->registry = $registryData;
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("✅ Registry configured for {$environment}!");
        $this->info("Provider: {$registryData->provider->label()}");
        $this->info("Registry host: {$registryData->getRegistryHost()}");
        $this->info("Image: {$registryData->image ?? $config->getName()}");
        $this->newLine();
        $this->line('Next: Run <fg=yellow>larakube cloud:configure gha</> to set up GitHub Actions.');

        return 0;
    }
}
