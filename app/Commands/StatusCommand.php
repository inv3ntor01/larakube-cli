<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

class StatusCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'status {environment=local : The environment to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Quickly check the health and status of all project services';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment');
        $config = $this->getProjectConfig(getcwd());
        $appName = $config->getName() ?? basename(getcwd());
        $namespace = $this->getNamespace($environment, $appName);

        $this->laraKubeInfo("Checking status for namespace '{$namespace}'...");

        $output = shell_exec("kubectl get pods -n {$namespace} -o json 2>/dev/null");

        if (! $output) {
            if ($this->isAiAgent()) {
                return $this->renderJson([
                    'status' => 'error',
                    'message' => "No services found in namespace '{$namespace}'",
                ]);
            }
            $this->laraKubeError("No services found in namespace '{$namespace}'. Is the app deployed?");

            return 1;
        }

        $pods = json_decode($output, true)['items'] ?? [];
        $rows = [];
        $jsonPods = [];

        foreach ($pods as $pod) {
            $name = $pod['metadata']['labels']['app'] ?? $pod['metadata']['name'];
            $status = $this->getPodStatus($pod);
            $restarts = $this->getPodRestarts($pod);
            $age = $this->getPodAge($pod);

            $jsonPods[] = [
                'name' => $name,
                'status' => $status,
                'restarts' => $restarts,
                'age' => $age,
                'ready' => $status === 'Running',
            ];

            $statusLabel = $status === 'Running' ? 'Ready 🟢' : "{$status} 🔴";

            $rows[] = [
                $name,
                $statusLabel,
                (string) $restarts,
                $age,
            ];
        }

        if ($this->isAiAgent()) {
            return $this->renderJson([
                'namespace' => $namespace,
                'pods' => $jsonPods,
                'hosts' => $config->getAllHosts(),
            ]);
        }

        if (empty($rows)) {
            $this->laraKubeInfo("No active pods found in '{$namespace}'.");

            return 0;
        }

        table(
            ['Service', 'Status', 'Restarts', 'Age'],
            $rows
        );

        $this->newLine();
        $this->laraKubeInfo('Active Service Links:');
        $hosts = $config->getAllHosts();

        if (empty($hosts)) {
            $this->line('  <fg=gray>No external hosts configured for this architecture.</>');
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
