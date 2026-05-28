<?php

namespace App\Commands;

use App\Contracts\HasReloadCommand;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ReloadCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'reload {--environment=local : The environment to target}';

    protected $description = 'Reload PHP code in long-running pods (Octane workers, Horizon, queues)';

    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $this->renderHeader();

        $config = $this->getProjectConfig();
        if (! $config) {
            $this->laraKubeError('Could not load .larakube.json.');

            return 1;
        }

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        $candidates = array_merge([$config->getServerVariation()], $config->getFeatures());

        $ran = 0;
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof HasReloadCommand) {
                continue;
            }

            $command = $candidate->getReloadCommand();
            if ($command === null) {
                continue;
            }

            $this->reloadInPod($candidate->getPodName($config), $command, $namespace);
            $ran++;
        }

        if ($ran === 0) {
            $this->laraKubeInfo('Nothing to reload — no Octane/Horizon/queues in this project.');
        }

        return 0;
    }

    protected function reloadInPod(string $service, string $command, string $namespace): void
    {
        $labels = ["app={$service}", "app=laravel-{$service}"];

        if ($service === 'queues') {
            $labels[] = 'app=queue';
            $labels[] = 'app=laravel-queue';
        }

        $podName = null;
        foreach ($labels as $label) {
            $podName = trim(shell_exec("kubectl get pods -n {$namespace} -l {$label} -o jsonpath='{.items[0].metadata.name}' 2>/dev/null") ?? '');
            if ($podName !== '') {
                break;
            }
        }

        if (! $podName) {
            $this->laraKubeWarn("Pod for '{$service}' not found in '{$namespace}'. Skipping.");

            return;
        }

        $this->laraKubeInfo("↻ {$service}: {$command}");
        passthru("kubectl exec -n {$namespace} -c php {$podName} -- {$command}");
    }
}
