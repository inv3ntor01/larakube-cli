<?php

namespace App\Commands;

use App\Ai\Agents\ClusterDoctorAgent;
use App\Traits\CheckPrerequisites;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithInternalDatabase;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Exception;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class DoctorCommand extends Command
{
    use CheckPrerequisites, InteractsWithEnvironments, InteractsWithInternalDatabase, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctor {--environment=local : The environment to diagnose} {--ai : Use AI to analyze issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan your LaraKube project and cluster for issues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        $this->laraKubeInfo("Diagnosing LaraKube environment: {$environment}...");

        $issues = $this->runDiagnostics($namespace);

        if (empty($issues)) {
            $this->laraKubeInfo('✅ No critical issues detected! Your cluster health is looking like a masterpiece.');
            $this->logActivity('Doctor scan completed: Healthy', ['environment' => $environment]);
        } else {
            $this->laraKubeError('Issues detected:');
            foreach ($issues as $issue) {
                $this->line("  ● <fg=red>{$issue['title']}</>: {$issue['description']}");
                if (isset($issue['fix'])) {
                    $this->line("    <fg=gray>👉 Fix: {$issue['fix']}</>");
                }
            }

            $this->logActivity('Doctor scan completed: Issues found', [
                'environment' => $environment,
                'issue_count' => count($issues),
            ]);

            if ($this->option('ai')) {
                $this->performAiDiagnosis($issues);
            } else {
                $this->line('');
                info('Pro Tip: Run larakube doctor --ai for an automated recovery plan.');
            }
        }

        return 0;
    }

    protected function runDiagnostics(string $namespace): array
    {
        $issues = [];

        // 1. Check for .larakube.json
        if (! file_exists(getcwd().'/'.ConfigData::CONFIG_FILE)) {
            $issues[] = [
                'title' => 'Missing Configuration',
                'description' => 'No .larakube.json file found in the current directory.',
                'fix' => 'Run larakube init to adopting this project.',
            ];
        }

        // 2. Check Cluster Connectivity
        $check = shell_exec('kubectl cluster-info 2>&1');
        if (str_contains($check, 'refused') || str_contains($check, 'error')) {
            $issues[] = [
                'title' => 'Cluster Unreachable',
                'description' => 'Cannot connect to the Kubernetes cluster.',
                'fix' => 'Ensure your cluster (Docker Desktop, OrbStack, or k3s) is running.',
            ];

            return $issues; // Stop here if cluster is down
        }

        // 3. Check for failed pods
        $pods = shell_exec("kubectl get pods -n {$namespace} -o json 2>/dev/null");
        if ($pods) {
            $data = json_decode($pods, true);
            foreach ($data['items'] ?? [] as $pod) {
                $phase = $pod['status']['phase'];
                if ($phase !== 'Running' && $phase !== 'Succeeded') {
                    $issues[] = [
                        'title' => "Pod Failure: {$pod['metadata']['name']}",
                        'description' => "Pod is in state '{$phase}'.",
                        'fix' => 'Check logs with: larakube logs '.str_replace('laravel-', '', $pod['metadata']['name']),
                    ];
                }
            }
        }

        return $issues;
    }

    protected function performAiDiagnosis(array $issues): void
    {
        $this->line('');
        $this->laraKubeInfo('🧠 LaraKube AI is analyzing cluster telemetry...');

        $provider = $this->getAiProvider();
        $apiKey = $this->getAiApiKey($provider);

        if (! $apiKey) {
            $this->laraKubeError('AI API Key not found. Cannot perform AI diagnosis.');

            return;
        }

        config(['ai.default' => $provider]);
        config(["ai.providers.{$provider}.key" => $apiKey]);

        $agent = ClusterDoctorAgent::make();
        $query = 'Here are the current cluster issues: '.json_encode($issues).'. Please analyze and provide a recovery plan.';

        $this->output->write('🤖 Recovery Plan: ');

        try {
            $stream = $agent->stream($query);
            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $this->output->write($event->delta);
                } elseif ($event instanceof ToolCall) {
                    $this->line("\n  <fg=gray>🛠 Fixing:</> <fg=yellow>{$event->toolCall->name}</>");
                }
            }
            $this->line("\n");
        } catch (Exception $e) {
            $this->error('AI Error: '.$e->getMessage());
        }
    }
}
