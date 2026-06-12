<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class AboutCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'about {environment=local : The environment to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display a unified architectural and health overview of the project';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');
        $config = $this->getProjectConfig(getcwd());
        $namespace = $this->getNamespace($environment, $config->getName());

        // 1. Architectural DNA
        $this->laraKubeInfo('Architectural DNA');
        $blueprintLabels = array_map(fn ($b) => $b->getLabel(), $config->getBlueprints());

        $dnaRows = [
            ['<fg=gray>Name</>', $config->getName()],
            ['<fg=gray>UUID</>', $config->getId()],
            ['<fg=gray>Blueprints</>', implode(', ', $blueprintLabels) ?: 'Standard Laravel'],
            ['<fg=gray>Runtime</>', $config->getServerVariation()?->getLabel()],
            ['<fg=gray>PHP / OS</>', "{$config->getPhpVersion()?->value} ({$config->getOs()?->getLabel()})"],
            ['<fg=gray>Primary DB</>', $config->getDatabase()?->getLabel()],
            ['<fg=gray>Primary Cache</>', $config->getCacheDriver()?->getLabel()],
            ['<fg=gray>Primary Storage</>', $config->getObjectStorage()?->getLabel() ?? 'None'],
            ['<fg=gray>Primary Search</>', $config->getScoutDriver()?->getLabel() ?? 'None'],
            ['<fg=gray>Features</>', implode(', ', array_map(fn ($f) => $f->getLabel(), $config->getFeatures())) ?: 'None'],
        ];

        table(['Property', 'Configuration'], $dnaRows);

        // 2. Environments Overview
        $this->newLine();
        $this->laraKubeInfo('Environments');

        $envRows = [];
        foreach ($config->getEnvironments() as $env) {
            $envData = $config->getEnvironment($env);
            $cloud = $envData?->cloud;

            if ($env === 'local') {
                $type = 'Local';
            } elseif ($cloud?->isManaged()) {
                $provider = strtoupper($cloud->provider ?? 'Managed');
                $type = $cloud->context ? "{$provider} ({$cloud->context})" : $provider;
            } elseif ($cloud?->ip) {
                $type = "VPS ({$cloud->ip})";
            } else {
                $type = '<fg=gray>—</>';
            }

            $strategy = $config->getStrategy($env);
            $strategyLabel = match ($strategy->value) {
                'single-node' => 'Single-Node',
                'multi-node-ha' => 'Multi-Node HA',
                default => $strategy->value,
            };

            $ingress = $config->getIngress($env);

            $registry = $envData?->registry;
            $registryLabel = $registry
                ? match ($registry->provider->value) {
                    'ghcr' => 'GHCR',
                    'dockerhub' => 'Docker Hub',
                    default => $registry->provider->value,
                }
            : '<fg=gray>—</>';

            $hostCount = count($envData?->hosts ?? []);
            $hostsLabel = $hostCount > 0 ? (string) $hostCount : '<fg=gray>—</>';

            $envRows[] = [
                $env === $environment ? "<options=bold>{$env}</>" : $env,
                $type,
                $strategyLabel,
                $ingress->getLabel(),
                $registryLabel,
                $hostsLabel,
            ];
        }

        table(['Env', 'Type', 'Strategy', 'Ingress', 'Registry', 'Hosts'], $envRows);

        // 3. Live Cluster Status
        $this->newLine();
        $this->laraKubeInfo("Live Cluster Status ($environment)");

        $output = shell_exec("kubectl get pods -n {$namespace} -o json 2>/dev/null");
        $pods = $output ? (json_decode($output, true)['items'] ?? []) : [];

        if (empty($pods)) {
            $this->warn('  No active pods found in this environment. Run "larakube up" to deploy.');
        } else {
            $statusRows = [];
            foreach ($pods as $pod) {
                $name = $pod['metadata']['labels']['app'] ?? $pod['metadata']['name'];
                $status = $this->getPodStatus($pod);
                $restarts = $this->getPodRestarts($pod);
                $age = $this->getPodAge($pod);

                $statusLabel = $status === 'Running' ? 'Ready 🟢' : "{$status} 🔴";

                $statusRows[] = [
                    $name,
                    $statusLabel,
                    (string) $restarts,
                    $age,
                ];
            }

            table(['Service', 'Status', 'Restarts', 'Age'], $statusRows);
        }

        // 4. Project URLs
        $this->newLine();
        $this->laraKubeInfo('Active Service Links');
        $hosts = $config->getAllHosts($environment);

        if (empty($hosts)) {
            $this->line('  <fg=gray>No external hosts configured.</>');
        } else {
            foreach ($hosts as $host => $label) {
                $this->line("  <fg=gray>●</> <fg=blue;options=underscore>https://{$host}</> <fg=gray>($label)</>");
            }
        }

        return 0;
    }

    protected function getPodStatus(array $pod): string
    {
        $phase = $pod['status']['phase'];

        if ($phase === 'Running') {
            $containerStatuses = $pod['status']['containerStatuses'] ?? [];
            foreach ($containerStatuses as $cs) {
                if (! $cs['ready']) {
                    return 'Initializing';
                }
            }
        }

        return $phase;
    }

    protected function getPodRestarts(array $pod): int
    {
        $restarts = 0;
        $containerStatuses = $pod['status']['containerStatuses'] ?? [];
        foreach ($containerStatuses as $cs) {
            $restarts += $cs['restartCount'];
        }

        return $restarts;
    }

    protected function getPodAge(array $pod): string
    {
        $startTime = strtotime($pod['metadata']['creationTimestamp']);
        $diff = time() - $startTime;

        if ($diff < 60) {
            return $diff.'s';
        }
        if ($diff < 3600) {
            return round($diff / 60).'m';
        }
        if ($diff < 86400) {
            return round($diff / 3600).'h';
        }

        return round($diff / 86400).'d';
    }
}
