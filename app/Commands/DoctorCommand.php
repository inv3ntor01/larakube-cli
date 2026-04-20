<?php

namespace App\Commands;

use App\Ai\ClusterDoctorAgent;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

class DoctorCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'doctor {--environment=local : The environment to diagnose} {--ai : Use AI to provide a deep diagnosis of unhealthy pods}';

    protected $description = 'Diagnose project and cluster health issues';

    public function handle()
    {
        $this->renderHeader();
        $this->laraKubeInfo('Starting LaraKube Health Check...');

        $this->checkPrerequisites();
        $this->checkLocalEnvironment();
        $this->checkManifestIntegrity();
        $this->checkHostsFile();
        $this->checkPortConflicts();
        $this->checkClusterConnectivity();
        $unhealthyPods = $this->checkPodHealth();

        if ($this->option('ai') && ! empty($unhealthyPods)) {
            $this->performAiDiagnosis($unhealthyPods);
        }

        $this->line('');
        $this->laraKubeInfo('Diagnostic complete!');

        return 0;
    }

    protected function checkPrerequisites()
    {
        $this->task('Checking Prerequisites', function () {
            $docker = shell_exec('docker --version');
            $kubectl = shell_exec('kubectl version --client');

            return $docker && $kubectl;
        });
    }

    protected function checkLocalEnvironment()
    {
        $this->task('Checking .env configuration', function () {
            $projectPath = getcwd();
            $envPath = $projectPath.'/.env';

            if (! file_exists($envPath)) {
                $this->warn('  ⚠ .env file is missing.');

                return false;
            }

            $envContent = file_get_contents($envPath);
            $criticalKeys = ['DB_PASSWORD', 'APP_KEY', 'DB_CONNECTION'];
            $issues = [];

            foreach ($criticalKeys as $key) {
                if (preg_match("/^#\s*{$key}=/m", $envContent)) {
                    $issues[] = "{$key} is commented out.";
                } elseif (! preg_match("/^{$key}=/m", $envContent)) {
                    $issues[] = "{$key} is missing entirely.";
                }
            }

            if (! empty($issues)) {
                $this->line('');
                foreach ($issues as $issue) {
                    $this->warn("  ⚠ {$issue}");
                }

                return false;
            }

            return true;
        });
    }

    protected function checkManifestIntegrity()
    {
        $environment = $this->option('environment');
        $path = ".infrastructure/k8s/overlays/{$environment}";

        $this->task("Checking Manifest Integrity ({$environment})", function () use ($path) {
            if (! is_dir(getcwd().'/'.$path)) {
                $this->warn("  ⚠ Manifest directory '{$path}' not found.");

                return false;
            }

            $output = [];
            $result = 0;
            exec("kubectl kustomize {$path} 2>&1", $output, $result);

            if ($result !== 0) {
                $this->line('');
                $this->error('  ✖ Malformed or broken Kubernetes configuration detected:');
                foreach (array_slice($output, 0, 5) as $line) {
                    $this->error("    {$line}");
                }

                return false;
            }

            return true;
        });
    }

    protected function checkHostsFile()
    {
        $this->task('Checking /etc/hosts resolution', function () {
            $projectPath = getcwd();
            $config = $this->getProjectConfig($projectPath);
            if (empty($config)) {
                return true;
            }

            $appName = basename($projectPath);
            $baseHost = "{$appName}.local";
            $required = [$baseHost, "vite.{$baseHost}"];

            foreach ($required as $host) {
                $ip = gethostbyname($host);
                if ($ip === $host || ($ip !== '127.0.0.1' && $ip !== '::1')) {
                    $this->line('');
                    $this->warn("  ⚠ Host '{$host}' does not resolve to 127.0.0.1.");
                    $this->info("    👉 FIX: Run 'larakube up' to update your hosts file.");

                    return false;
                }
            }

            return true;
        });
    }

    protected function checkPortConflicts()
    {
        $this->task('Checking Port Conflicts (80, 443, 5173)', function () {
            $ports = [80, 443, 5173];
            $conflicts = [];

            foreach ($ports as $port) {
                $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $conflicts[] = $port;
                }
            }

            if (! empty($conflicts)) {
                $this->line('');
                $this->warn('  ⚠ The following ports are already in use on your host: '.implode(', ', $conflicts));

                return false;
            }

            return true;
        });
    }

    protected function checkClusterConnectivity()
    {
        $this->task('Checking Cluster Connectivity', function () {
            $output = shell_exec('kubectl cluster-info 2>&1');
            if (str_contains($output, 'Unable to connect') || str_contains($output, 'timeout')) {
                return false;
            }

            return true;
        });
    }

    protected function checkPodHealth(): array
    {
        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);
        $unhealthyPods = [];

        $this->task("Checking Project Pods ({$namespace})", function () use ($namespace, &$unhealthyPods) {
            $output = shell_exec("kubectl get pods -n {$namespace} -o json 2>/dev/null");
            if (! $output) {
                return false;
            }

            $pods = json_decode($output, true)['items'] ?? [];
            foreach ($pods as $pod) {
                $name = $pod['metadata']['name'];
                $ready = true;
                foreach ($pod['status']['containerStatuses'] ?? [] as $cs) {
                    if (! $cs['ready']) {
                        $ready = false;
                    }
                }

                if (! $ready || $pod['status']['phase'] !== 'Running') {
                    $unhealthyPods[] = $name;
                }
            }

            return empty($unhealthyPods);
        });

        return $unhealthyPods;
    }

    protected function performAiDiagnosis(array $podNames)
    {
        $provider = $this->getAiProvider();
        $apiKey = $this->getAiApiKey();

        if (! $apiKey) {
            $this->warn("  ⚠ AI API Key not found for provider '{$provider}'. Set it with: larakube config --ai-key=YOUR_KEY");

            return;
        }

        // Dynamically set the provider and key for the AI SDK
        config(['ai.default' => $provider]);
        config(["ai.providers.{$provider}.key" => $apiKey]);

        $this->line('');
        $this->laraKubeInfo('🧠 Performing Deep AI Diagnosis...');
        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        $config = $this->getProjectConfig(getcwd());
        $blueprint = json_encode($config, JSON_PRETTY_PRINT);

        foreach ($podNames as $podName) {
            $this->info("  🔍 Analyzing Pod: {$podName}");

            $logs = shell_exec("kubectl logs -n {$namespace} {$podName} --tail=50 2>&1");
            $events = shell_exec("kubectl get events -n {$namespace} --field-selector involvedObject.name={$podName} 2>&1");

            $prompt = "Namespace: {$namespace}\nPod: {$podName}\nBlueprint: {$blueprint}\n\nLogs:\n{$logs}\n\nEvents:\n{$events}";

            $response = spin(function () use ($prompt) {
                return ClusterDoctorAgent::make()->prompt($prompt);
            }, 'AI is thinking...');

            $this->line('');
            $this->line($response->text);
            $this->line('');
        }
    }
}
