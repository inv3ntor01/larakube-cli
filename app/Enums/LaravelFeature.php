<?php

namespace App\Enums;

use App\Contracts\HasArtisanCommands;
use App\Contracts\HasAutoUsedComponents;
use App\Contracts\HasCommandOptions;
use App\Contracts\HasComposerDependencies;
use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasHosts;
use App\Contracts\HasJsDependencies;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasLifecycleHooks;
use App\Contracts\HasSelectOptions;
use App\Contracts\RequiresPhpExtensions;
use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum LaravelFeature: string implements HasArtisanCommands, HasAutoUsedComponents, HasCommandOptions, HasComposerDependencies, HasDependencies, HasEnvironmentVariables, HasHiddenComponents, HasHosts, HasJsDependencies, HasKubernetesFiles, HasLabel, HasLifecycleHooks, HasSelectOptions, RequiresPhpExtensions
{
    use GeneratesProjectInfrastructure, ProvidesCommandOptions, ProvidesSelectOptions;

    case TASK_SCHEDULING = 'scheduler';
    case HORIZON = 'horizon';
    case QUEUES = 'queues';
    case REVERB = 'reverb';
    case SCOUT = 'scout';
    case OCTANE = 'octane';
    case MONITORING = 'monitoring';
    case METALLB = 'metallb';
    case MAILPIT = 'mailpit';

    case AI = 'ai';
    case MCP = 'mcp';
    case BOOST = 'boost';

    public function getLabel(): string
    {
        return match ($this) {
            self::QUEUES => 'Queues (without Redis)',
            self::TASK_SCHEDULING => 'Task Scheduling',
            self::HORIZON => 'Horizon (with Redis)',
            self::REVERB => 'Reverb',
            self::SCOUT => 'Scout',
            self::OCTANE => 'Octane (requires FrankenPHP)',
            self::AI => 'Laravel AI',
            self::MCP => 'Laravel MCP',
            self::BOOST => 'Laravel Boost',
            self::MONITORING => 'Monitoring (Prometheus & Grafana)',
            self::METALLB => 'MetalLB (LoadBalancer Provider)',
            self::MAILPIT => 'Mailpit (Local SMTP)',
        };
    }

    public function isHidden(): bool
    {
        return match ($this) {
            self::MONITORING, self::METALLB, self::MAILPIT => true,
            default => false,
        };
    }

    public static function getAutoUsedComponents(): array
    {
        return [
            self::MAILPIT,
        ];
    }

    public function getEnvironmentVariables(?ConfigData $config = null): array
    {
        return match ($this) {
            self::REVERB => [
                'REVERB_APP_ID' => 'larakube',
                'REVERB_APP_KEY' => 'larakubekey',
                'REVERB_APP_SECRET' => 'larakubesecret',
                'REVERB_HOST' => '0.0.0.0',
                'REVERB_PORT' => '8080',
                'REVERB_SCHEME' => 'http',
            ],
            self::MAILPIT => [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => 'mailpit',
                'MAIL_PORT' => '1025',
                'MAIL_USERNAME' => 'null',
                'MAIL_PASSWORD' => 'null',
                'MAIL_ENCRYPTION' => 'null',
            ],
            default => [],
        };
    }

    public function getHosts(ConfigData $config): array
    {
        $appName = $config->getName();

        return match ($this) {
            self::REVERB => ["reverb.{$appName}.dev.test" => 'Reverb Console'],
            self::MAILPIT => ["mailpit.{$appName}.dev.test" => 'Mailpit Dashboard'],
            self::MONITORING => [
                "grafana.{$appName}.dev.test" => 'Grafana Dashboard',
                "prometheus.{$appName}.dev.test" => 'Prometheus Dashboard',
            ],
            default => [],
        };
    }

    public function getDependencies(ConfigData $config): array
    {
        return match ($this) {
            self::HORIZON => array_merge($config->getCoreDependencies(), [$config->getServerVariation(), DatabaseDriver::REDIS]),
            self::OCTANE => [ServerVariation::FRANKENPHP],
            self::QUEUES, self::TASK_SCHEDULING, self::REVERB => array_merge($config->getCoreDependencies(), [$config->getServerVariation()]),
            default => [],
        };
    }

    public function composerDependencies(): array
    {
        return match ($this) {
            self::HORIZON => ['laravel/horizon'],
            self::OCTANE => ['laravel/octane --with-all-dependencies'],
            self::SCOUT => ['laravel/scout'],
            default => [],
        };
    }

    public function artisanInstallCommands(): array
    {
        return match ($this) {
            self::HORIZON => ['php artisan horizon:install'],
            self::OCTANE => ['php artisan octane:install --server=frankenphp'],
            self::REVERB => ['php artisan install:broadcasting --reverb --without-node'],
            default => [],
        };
    }

    public function k8sDeploymentArgs(): string
    {
        return match ($this) {
            self::HORIZON => '["php", "artisan", "horizon"]',
            self::TASK_SCHEDULING => '["php", "artisan", "schedule:run"]',
            self::QUEUES => '["php", "artisan", "queue:work"]',
            self::REVERB => '["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]',
            default => '[]',
        };
    public function getComposerDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::HORIZON => [
                'laravel/horizon',
            ],
            self::SCOUT => [
                'laravel/scout',
            ],
            self::OCTANE => [
                'laravel/octane',
            ],
            self::REVERB => [
                'laravel/reverb',
            ],
            self::AI => [
                'laravel/ai',
            ],
            self::MCP => [
                'laravel/mcp',
            ],
            self::BOOST => [
                'laravel/boost',
            ],
            default => [],
        };
    }
    public function getArtisanCommands(?ConfigData $context = null): array
    {
        return match ($this) {
            self::HORIZON => [
                'horizon:install',
            ],
            self::REVERB => [
                'install:broadcasting --reverb --without-node',
            ],
            self::SCOUT => [
                'vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"',
            ],
            default => [],
        };
    }

    public function getJsDependencies(?ConfigData $context = null): array
    {
        return match ($this) {
            self::REVERB => $this->getReverbJsCommands($context),
            default => [],
        };
    }

    public function getPhpExtensions(): array
    {
        return match ($this) {
            default => [],
        };
    }

    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void
    {
        // TODO: Implement onPostInstall() method.
    }

    public function getPostInstallInstructions(): array
    {
        return [];
    }

    public function updateK8s(ConfigData $config): void
    {
        $k8sPath = $config->getK8sPath();

        if ($viewName = $this->getWorkloadViewName()) {
            $content = view($viewName, ['config' => $config, 'feature' => $this])->render();
            file_put_contents("$k8sPath/{$this->getWorkloadYamlDestination()}", $content);
        }

        if ($viewName = $this->getPatchViewName()) {
            $patch = view($viewName, ['config' => $config, 'feature' => $this])->render();
            file_put_contents("$k8sPath/{$this->getPatchYamlDestination()}", $patch);
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'k8s.scheduler.cronjob',
            self::HORIZON => 'k8s.horizon.deployment',
            self::QUEUES => 'k8s.queues.deployment',
            self::REVERB => 'k8s.reverb.deployment',
            self::MAILPIT => 'k8s.mailpit.deployment',
            default => null,
        };
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return match ($this) {
            self::TASK_SCHEDULING => 'base/scheduler-cronjob.yaml',
            self::HORIZON => 'base/horizon-deployment.yaml',
            self::QUEUES => 'base/queue-deployment.yaml',
            self::REVERB => 'base/reverb-deployment.yaml',
            self::MAILPIT => 'overlays/local/mailpit.yaml',
            default => null,
        };
    }

    public function getNetworkViewName(): ?string
    {
        return null;
    }

    public function getNetworkYamlDestination(): ?string
    {
        return null;
    }

    public function getStorageViewName(): ?string
    {
        return null;
    }

    public function getStorageYamlDestination(): ?string
    {
        return null;
    }

    public function getPatchViewName(): ?string
    {
        return match ($this) {
            self::HORIZON => 'k8s.horizon.patch',
            self::REVERB => 'k8s.reverb.patch',
            default => null,
        };
    }

    public function getPatchYamlDestination(): ?string
    {
        return match ($this) {
            self::HORIZON => 'overlays/local/horizon-patch.yaml',
            self::REVERB => 'overlays/local/reverb-patch.yaml',
            default => null,
        };
    }

    public function getK8sDeploymentArgs(): string
    {
        return match ($this) {
            self::TASK_SCHEDULING => '["php", "artisan", "schedule:run"]',
            self::HORIZON => '["php", "artisan", "horizon"]',
            self::QUEUES => '["php", "artisan", "queue:work"]',
            self::REVERB => '["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]',
            default => '[]',
        };
    }

    public function getManifestFiles(): array
    {
        return match ($this) {
            self::TASK_SCHEDULING => [
                'base' => ['scheduler-cronjob.yaml'],
            ],
            self::HORIZON => [
                'base' => ['horizon-deployment.yaml'],
                'patches' => ['horizon-patch.yaml'],
            ],
            self::QUEUES => [
                'base' => ['queue-deployment.yaml'],
            ],
            self::REVERB => [
                'base' => ['reverb-deployment.yaml'],
                'patches' => ['reverb-patch.yaml'],
            ],
            self::MAILPIT => [
                'local' => ['mailpit.yaml'],
            ],
            default => [],
        };
    }

    private function getReverbJsCommands(?ConfigData $context): array
    {
        $projectPath = $context?->getName() ? getcwd().'/'.$context->getName() : null;
        if (! $projectPath || ! file_exists($projectPath.'/package.json')) {
            return [];
        }

        $packageJson = json_decode(file_get_contents($projectPath.'/package.json'), true);
        $dependencies = array_merge($packageJson['dependencies'] ?? [], $packageJson['devDependencies'] ?? []);

        if (isset($dependencies['laravel-echo'])) {
            return [];
        }

        $jsPackages = ['laravel-echo', 'pusher-js'];
        $frontend = $context?->getFrontend();

        if ($frontend && $echoPkg = $frontend->echoPackage()) {
            $jsPackages[] = $echoPkg;
        } elseif (! $frontend) {
            // Fallback to legacy detection if frontend is none but packages are present
            if (isset($dependencies['react'])) {
                $jsPackages[] = '@laravel/echo-react';
            } elseif (isset($dependencies['vue'])) {
                $jsPackages[] = '@laravel/echo-vue';
            }
        }

        $pm = $context?->getPackageManager() ?? PackageManager::NPM;

        return [$pm->addDevCommand($jsPackages).' --ignore-scripts'];
    }
}
