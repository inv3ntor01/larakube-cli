<?php

namespace App\Commands;

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ResourcesCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resources {environment? : The environment to configure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure Kubernetes resource requests and limits per component';

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

        $environments = $config->getEnvironments();
        $envName = $this->argument('environment');

        if (! $envName) {
            $envName = select(
                label: 'Which environment do you want to configure resources for?',
                options: $environments,
                default: 'local',
            );
        }

        if (! in_array($envName, $environments)) {
            $this->laraKubeError("Environment '{$envName}' not found in your blueprint.");

            return 1;
        }

        // Show current effective limits
        $this->showEffectiveResourcesTable($config, $envName);

        // Select component to configure
        $components = $this->getDeployableComponents($config, $envName);
        $options = array_merge(['default' => 'default (all pods)'], $components);

        $componentChoice = select(
            label: 'Which component do you want to configure?',
            options: $options,
            default: 'default',
        );

        $action = select(
            label: "What do you want to do with '{$componentChoice}' in '{$envName}'?",
            options: [
                'set' => 'Set or update resources',
                'reset' => 'Reset to inherit from default (or code defaults)',
            ],
            default: 'set',
        );

        if ($action === 'reset') {
            $config->setResources($envName, $componentChoice, null);
            $this->saveProjectConfig($projectPath, $config);
            $this->laraKubeInfo("Reset resources for '{$componentChoice}' in '{$envName}'.");
            $this->printNextSteps($envName);

            return 0;
        }

        // Prompt for resources
        $explicit = $config->getEnvironment($envName)?->resources[$componentChoice] ?? [];
        $inherited = $componentChoice === 'default'
            ? ConfigData::DEFAULT_RESOURCES
            : $config->getResources($envName, 'default');

        $cpuRequest = $this->promptQuantity(
            'CPU Request',
            $explicit['requests']['cpu'] ?? '',
            'inherit '.($inherited['requests']['cpu'] ?? 'none'),
        );
        $cpuLimit = $this->promptQuantity(
            'CPU Limit',
            $explicit['limits']['cpu'] ?? '',
            'inherit '.($inherited['limits']['cpu'] ?? 'none'),
        );
        $memRequest = $this->promptQuantity(
            'Memory Request',
            $explicit['requests']['memory'] ?? '',
            'inherit '.($inherited['requests']['memory'] ?? 'none'),
        );
        $memLimit = $this->promptQuantity(
            'Memory Limit',
            $explicit['limits']['memory'] ?? '',
            'inherit '.($inherited['limits']['memory'] ?? 'none'),
        );

        $newResources = [];
        if ($cpuRequest !== '') {
            $newResources['requests']['cpu'] = $cpuRequest;
        }
        if ($memRequest !== '') {
            $newResources['requests']['memory'] = $memRequest;
        }
        if ($cpuLimit !== '') {
            $newResources['limits']['cpu'] = $cpuLimit;
        }
        if ($memLimit !== '') {
            $newResources['limits']['memory'] = $memLimit;
        }

        $config->setResources($envName, $componentChoice, $newResources);
        $this->saveProjectConfig($projectPath, $config);

        $this->laraKubeInfo("Updated resources for '{$componentChoice}' in '{$envName}'.");

        $this->printNextSteps($envName);

        return 0;
    }

    protected function showEffectiveResourcesTable(ConfigData $config, string $envName): void
    {
        $components = $this->getDeployableComponents($config, $envName);
        $headers = ['Component', 'CPU Request', 'CPU Limit', 'Memory Request', 'Memory Limit'];
        $rows = [];

        foreach (array_merge(['default'], array_keys($components)) as $component) {
            $res = $config->getResources($envName, $component);
            $rows[] = [
                $component === 'default' ? 'default (fallback)' : $component,
                $res['requests']['cpu'] ?? '-',
                $res['limits']['cpu'] ?? '-',
                $res['requests']['memory'] ?? '-',
                $res['limits']['memory'] ?? '-',
            ];
        }

        table($headers, $rows);
    }

    protected function getDeployableComponents(ConfigData $config, string $envName): array
    {
        $components = ['web' => 'web (PHP / Nginx)'];

        $features = $config->getFeatures($envName);

        if (in_array(LaravelFeature::HORIZON, $features, true)) {
            $components['horizon'] = 'horizon';
        }
        if (in_array(LaravelFeature::QUEUES, $features, true)) {
            $components['queues'] = 'queues';
        }
        if (in_array(LaravelFeature::REVERB, $features, true)) {
            $components['reverb'] = 'reverb';
        }
        if (in_array(LaravelFeature::TASK_SCHEDULING, $features, true)) {
            $components['scheduler'] = 'scheduler';
        }
        if (in_array(LaravelFeature::SSR, $features, true)) {
            $components['ssr'] = 'ssr';
        }

        return $components;
    }

    protected function promptQuantity(string $label, string $default, string $placeholder): string
    {
        while (true) {
            $val = text(
                label: $label,
                placeholder: $placeholder,
                default: $default,
                required: false,
                hint: 'e.g. 100m, 1, 256Mi, 1Gi (leave blank to omit / inherit)',
            );

            if ($val === '') {
                return '';
            }

            if (ConfigData::isValidQuantity($val)) {
                return $val;
            }

            $this->laraKubeError("Invalid Kubernetes quantity: {$val}. Must be like 100m, 1, 256Mi, 1Gi, etc.");
        }
    }

    protected function printNextSteps(string $envName): void
    {
        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');
        if ($envName === 'local') {
            $this->line('  Run <fg=yellow>larakube up</> to apply changes locally.');
        } else {
            $this->line("  Run <fg=yellow>larakube cloud:deploy {$envName}</> to apply changes to the cloud.");
        }
    }
}
