<?php

namespace App\Enums;

use App\Contracts\AsDependency;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDependencies;
use App\Contracts\HasDockerImage;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasSelectOptions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum ScoutDriver: string implements AsDependency, HasCommandOptions, HasComposerDependencies, HasDependencies, HasDockerImage, HasEnvironmentVariables, HasHosts, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasSelectOptions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case MEILISEARCH = 'meilisearch';
    case TYPESENSE = 'typesense';
    case DATABASE = 'database';

    public function getLabel(): string
    {
        return match ($this) {
            self::MEILISEARCH => 'Meilisearch (Self-hosted)',
            self::TYPESENSE => 'Typesense (Self-hosted)',
            self::DATABASE => 'Database (No extra infrastructure)',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} for Scout",
            ];
        }

        return $options;
    }

    public function port(): int
    {
        return match ($this) {
            self::MEILISEARCH => 7700,
            self::TYPESENSE => 8108,
            default => 80,
        };
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return match ($this) {
            self::MEILISEARCH => 'getmeili/meilisearch:v1.12',
            self::TYPESENSE => 'typesense/typesense:27.1',
            default => '',
        };
    }

    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::MEILISEARCH => ['meilisearch/meilisearch-php'],
            self::TYPESENSE => ['typesense/typesense-php'],
            self::DATABASE => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        $this->syncEnvFile($projectPath, $this->getEnvironmentVariables());
    }

    public function getPostInstallInstructions(): array
    {
        return [];
    }

    public function getEnvironmentVariables(?ConfigData $config = null): array
    {
        return match ($this) {
            self::MEILISEARCH => [
                'SCOUT_DRIVER' => 'meilisearch',
                'MEILISEARCH_HOST' => 'http://meilisearch:7700',
                'MEILISEARCH_KEY' => 'larakubesecretpassword',
            ],
            self::TYPESENSE => [
                'SCOUT_DRIVER' => 'typesense',
                'TYPESENSE_HOST' => 'laravel-typesense',
                'TYPESENSE_PORT' => '8108',
                'TYPESENSE_PROTOCOL' => 'http',
                'TYPESENSE_API_KEY' => 'larakubesecretpassword',
            ],
            self::DATABASE => [
                'SCOUT_DRIVER' => 'database',
            ],
        };
    }

    public function getHosts(ConfigData $config): array
    {
        $appName = $config->getName();

        return match ($this) {
            self::MEILISEARCH => ["meilisearch-{$appName}.dev.test" => 'Meilisearch Console'],
            self::TYPESENSE => ["typesense-{$appName}.dev.test" => 'Typesense Console'],
            default => [],
        };
    }

    public function getDependencyConfig(ConfigData $config): array
    {
        return [$this->value => $this->port()];
    }

    public function getDependencies(ConfigData $config): array
    {
        return match ($this) {
            self::MEILISEARCH, self::TYPESENSE => [LaravelFeature::SCOUT],
            default => [],
        };
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        // Write workload
        if ($viewName = $this->getWorkloadViewName()) {
            $content = view($viewName, ['config' => $config, 'driver' => $this])->render();
            file_put_contents("$k8sPath/{$this->getWorkloadYamlDestination()}", $content);
        }

        // Write storage
        if ($viewName = $this->getStorageViewName()) {
            $vols = view($viewName, ['config' => $config, 'driver' => $this])->render();
            file_put_contents("$k8sPath/{$this->getStorageYamlDestination()}", $vols);
        }

        // Write network
        if ($viewName = $this->getNetworkViewName()) {
            $ingress = view($viewName, ['config' => $config, 'driver' => $this])->render();
            file_put_contents("$k8sPath/{$this->getNetworkYamlDestination()}", $ingress);
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'k8s.meilisearch.deployment',
            self::TYPESENSE => 'k8s.typesense.deployment',
            default => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'base/meilisearch-deployment.yaml',
            self::TYPESENSE => 'base/typesense-deployment.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'k8s.meilisearch.ingress',
            self::TYPESENSE => 'k8s.typesense.ingress',
            default => null,
        };
    }

    public function getNetworkYamlDestination(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'overlays/local/meilisearch-ingress.yaml',
            self::TYPESENSE => 'overlays/local/typesense-ingress.yaml',
            default => null,
        };
    }

    public function getStorageViewName(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'k8s.meilisearch.volumes',
            self::TYPESENSE => 'k8s.typesense.volumes',
            default => null,
        };
    }

    public function getStorageYamlDestination(): ?string
    {
        return match ($this) {
            self::MEILISEARCH => 'overlays/local/meilisearch-volumes.yaml',
            self::TYPESENSE => 'overlays/local/typesense-volumes.yaml',
            default => null,
        };
    }

    public function getPatchViewName(): ?string
    {
        return null;
    }

    public function getPatchYamlDestination(): ?string
    {
        return null;
    }

    public function getK8sDeploymentArgs(): string
    {
        return '';
    }

    public function getManifestFiles(): array
    {
        return match ($this) {
            self::MEILISEARCH => [
                'base' => ['meilisearch-deployment.yaml'],
                'local' => ['meilisearch-volumes.yaml', 'meilisearch-ingress.yaml'],
            ],
            self::TYPESENSE => [
                'base' => ['typesense-deployment.yaml'],
                'local' => ['typesense-volumes.yaml', 'typesense-ingress.yaml'],
            ],
            default => [],
        };
    }
}
