<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class UpCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithHosts, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'up {environment? : The environment to deploy (local or production)} 
                            {--dashboard : Open k9s after deployment} 
                            {--no-dashboard : Disable the dashboard prompt}
                            {--migrate : Run migrations without prompting} 
                            {--no-migrate : Skip migrations without prompting}
                            {--dry-run : Validate manifests without deploying}
                            {--test : Run smoke test without prompting}
                            {--no-test : Skip smoke test without prompting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy the application to Kubernetes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?? 'local';

        // 1. Safe Dry-Run (Validation Mode)
        if ($this->option('dry-run')) {
            $this->laraKubeInfo("Performing Architectural Validation for '{$environment}'...");
            $path = ".infrastructure/k8s/overlays/{$environment}";

            if (! is_dir(getcwd().'/'.$path)) {
                $this->laraKubeError("Environment '{$environment}' configuration not found!");

                return 1;
            }

            $validationResult = ['result' => 0, 'output' => []];

            $this->withSpin('Validating Kubernetes manifests...', function () use (&$validationResult, $path) {
                $output = [];
                $result = 0;
                exec("kubectl kustomize {$path} 2>&1", $output, $result);

                $validationResult = [
                    'result' => $result,
                    'output' => $output,
                ];

                return $result === 0;
            });

            if ($validationResult['result'] !== 0) {
                $this->laraKubeError('Malformed configuration detected:');
                foreach (array_slice($validationResult['output'], 0, 10) as $line) {
                    $this->line("    {$line}");
                }
                exit(1);
            }

            $this->laraKubeInfo('ARCHITECTURAL INTEGRITY: VERIFIED ✅');
            if (confirm('Would you like to see the generated YAML?', false)) {
                $this->line(implode("\n", $validationResult['output']));
            }

            return 0;
        }

        $this->ensureHostsAreSet();

        $appName = basename(getcwd());
        $path = ".infrastructure/k8s/overlays/{$environment}";
        $namespace = $this->getNamespace($environment);

        if (! is_dir(getcwd().'/'.$path)) {
            $this->laraKubeError("Environment '{$environment}' configuration not found!");
            info('Make sure you are in the root of your Laravel project and the environment exists.');

            return 1;
        }

        $this->laraKubeInfo("Targeting environment: {$environment}");

        // Check if ANY Ingress Controller is running (local only)
        if ($environment === 'local' && shell_exec("kubectl get pods -A -l 'app.kubernetes.io/component=controller' 2>/dev/null | grep Running") === null && shell_exec('kubectl get pods -A -l app=traefik 2>/dev/null | grep Running') === null) {
            $this->laraKubeInfo('No Ingress Controller detected in your local cluster.');
            if (confirm('LaraKube works best with Traefik. Would you like us to install it for you?', true)) {
                $this->laraKubeInfo('Installing Traefik Ingress Controller...');
                passthru('kubectl apply -f '.base_path('resources/stubs/k8s/traefik-install.yaml.stub'));
                $this->laraKubeInfo('Waiting for Traefik to be ready...');
                passthru('kubectl wait --for=condition=ready pod -l app=traefik -n traefik --timeout=60s');
            }
        }

        // 1. Build image if local
        if ($environment === 'local') {
            $this->laraKubeInfo("Building local Docker image '{$appName}:latest'...");
            passthru("docker build -t {$appName}:latest -f Dockerfile.php .");
        }

        // 2. Ensure Namespace exists
        $this->withSpin("Ensuring namespace '{$namespace}' exists...", function () use ($namespace) {
            exec("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");
        });

        // 3. Handle .env injection
        $envFile = $environment === 'local' ? '.env' : ".env.{$environment}";
        $envPath = getcwd().'/'.$envFile;

        if (file_exists($envPath)) {
            $this->withSpin('Injecting configuration and blueprint...', function () use ($namespace, $envPath) {
                exec("kubectl create configmap laravel-config -n {$namespace} --from-env-file={$envPath} --dry-run=client -o yaml | kubectl apply -f -");
                exec("kubectl create secret generic laravel-secrets -n {$namespace} --from-env-file={$envPath} --dry-run=client -o yaml | kubectl apply -f -");

                // Store the blueprint for resilience
                $blueprintPath = getcwd().'/.larakube.json';
                if (file_exists($blueprintPath)) {
                    exec("kubectl create secret generic larakube-blueprint -n {$namespace} --from-file=.larakube.json={$blueprintPath} --dry-run=client -o yaml | kubectl apply -f -");
                }
            });
        } else {
            $this->laraKubeError("Environment file {$envFile} not found! Deployment may fail due to missing configuration.");
        }

        // 4. Apply manifests
        $this->laraKubeInfo('Applying Kubernetes manifests...');

        // Scale down to release file locks (Safe transition)
        $this->withSpin('Preparing cluster for architectural update...', function () use ($namespace) {
            exec("kubectl scale deployment --all --replicas=0 -n {$namespace} 2>/dev/null");
        });

        passthru("kubectl apply -k {$path}");

        // 5. Restart deployments to pick up new ConfigMap/Secret changes
        $this->laraKubeInfo('Restarting deployments to apply potential configuration changes...');
        passthru("kubectl rollout restart deployment -n {$namespace}");

        // 6. Wait for pods to be ready
        $this->laraKubeInfo('Waiting for infrastructure to be ready...');
        $timeout = '120s';

        // Detect databases from manifests to ensure wait order even if config is missing
        $detectedDbs = $this->getDatabasesFromManifests();

        foreach ($detectedDbs as $db) {
            $this->laraKubeInfo("  ● Waiting for {$db['name']}...");
            passthru("kubectl wait --for=condition=ready pod -l app={$db['label']} -n {$namespace} --timeout={$timeout}");
        }

        // Wait for web pod LAST
        $this->laraKubeInfo('  ● Waiting for Web application...');
        passthru("kubectl wait --for=condition=ready pod -l app=laravel-web -n {$namespace} --timeout={$timeout}");

        // 7. Handle manual migration override
        if ($this->option('migrate')) {
            $this->laraKubeInfo('Executing manual database migration...');
            $this->call('exec', [
                'commands' => ['php artisan migrate --force'],
                '--environment' => $environment,
            ]);
        } else {
            $this->laraKubeInfo('Database state is managed by LaraKube Automations.');
        }

        // 8. Run smoke test
        $runTest = ($environment === 'local' && $this->option('test')) || ($environment === 'local' && ! $this->option('no-test') && confirm('Would you like to perform a smoke test to check accessibility?', true));
        if ($runTest) {
            $this->laraKubeInfo('Running connectivity smoke test...');
            $this->call('test', ['environment' => $environment]);
        }

        // 9. Dashboard
        $openDashboard = $this->option('dashboard') || (! $this->option('no-dashboard') && confirm('Would you like to open the dashboard to monitor the deployment?'));
        if ($openDashboard) {
            $this->call('dashboard', ['environment' => $environment]);
        }

        $this->renderStarPrompt();

        return 0;
    }

    protected function getDatabasesFromManifests(): array
    {
        $dbs = [];
        $basePath = getcwd().'/.infrastructure/k8s/base';

        if (! is_dir($basePath)) {
            return [];
        }

        $mappings = [
            'postgres-deployment.yaml' => ['name' => 'PostgreSQL', 'label' => 'postgres'],
            'mysql-deployment.yaml' => ['name' => 'MySQL',      'label' => 'mysql'],
            'redis-deployment.yaml' => ['name' => 'Redis',      'label' => 'redis'],
        ];

        foreach ($mappings as $file => $info) {
            if (file_exists("{$basePath}/{$file}")) {
                $dbs[] = $info;
            }
        }

        return $dbs;
    }
}
