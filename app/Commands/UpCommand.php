<?php

namespace App\Commands;

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithSslTrust;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class UpCommand extends Command
{
    use GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithClusterContext, InteractsWithDocker, InteractsWithEnvironments, InteractsWithHosts, InteractsWithProjectConfig, InteractsWithSslTrust, InteractsWithTraefik, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'up {environment? : The environment to deploy (local or production)}
                            {--console : Open the LaraKube Console after deployment}
                            {--no-console : Disable the console prompt}
                            {--no-sync : Skip architectural DNA sync with current machine}
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

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment') ?? 'local';

        if (! $this->validateContextForEnvironment($environment)) {
            return 1;
        }

        // 1. Safe Dry-Run (Validation Mode)
        if ($this->option('dry-run')) {
            $this->laraKubeInfo("Performing Architectural Validation for '$environment'...");
            $path = ".infrastructure/k8s/overlays/$environment";

            if (! is_dir(getcwd().'/'.$path)) {
                $this->laraKubeError("Environment '$environment' configuration not found!");

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

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        // --- 🏗 ARCHITECTURAL SYNC (Local Only) ---
        // Every time we run 'up' locally, we ensure the local overlays
        // match the current machine's paths (e.g. hostPath code mounts).
        if ($environment === 'local' && ! $this->option('no-sync')) {
            $this->withSpin('Syncing architectural DNA with current machine...', function () use ($config) {
                $this->orchestrateProjectScaffolding($config, false, false);

                return true;
            });
        }

        if ($environment === 'local') {
            // 🔒 1. Handle missing .env
            if (! file_exists($projectPath.'/.env')) {
                if (file_exists($projectPath.'/.env.example')) {
                    $this->laraKubeInfo('No .env file found. Creating from .env.example...');
                    @copy($projectPath.'/.env.example', $projectPath.'/.env');
                    passthru('php artisan key:generate --no-interaction');
                } else {
                    $this->laraKubeError('No .env or .env.example found! Deployment may fail.');
                }
            }

            // 📦 2. Handle missing dependencies (Surgical)
            if (! is_dir($projectPath.'/vendor') || ! is_dir($projectPath.'/node_modules')) {
                $this->laraKubeInfo('Missing local dependencies. Orchestrating installation...');
                $config->installComponents();
            }
        }

        $appName = $config->getName() ?? basename($projectPath);
        $path = ".infrastructure/k8s/overlays/{$environment}";
        $namespace = $this->getNamespace($environment, $appName);

        if (! is_dir(getcwd().'/'.$path)) {
            $this->laraKubeError("Environment '{$environment}' configuration not found!");
            info('Make sure you are in the root of your Laravel project and the environment exists.');

            return 1;
        }

        $this->laraKubeInfo("Targeting environment: {$environment}");

        if ($environment === 'local') {
            $this->withSpin('Ensuring local infrastructure directories exist...', function () use ($projectPath) {
                @mkdir($projectPath.'/.infrastructure/volume_data', 0777, true);
                $dbFile = $projectPath.'/.infrastructure/volume_data/database.sqlite';
                if (! file_exists($dbFile)) {
                    touch($dbFile);
                }
                chmod($dbFile, 0666); // User/Group/Others can read/write
            });
        }

        // Check if ANY Ingress Controller is running (local only)
        if ($environment === 'local') {
            if (! $this->isTraefikInstalled()) {
                $this->laraKubeInfo('No Ingress Controller detected in your local cluster.');
                if (confirm('LaraKube works best with Traefik. Would you like us to install it for you?', true)) {
                    $this->setupTraefik();
                }
            }
        }

        // 1. Build image if local
        if ($environment === 'local') {
            $this->laraKubeInfo("Building local Docker image '$appName:latest'...");
            $this->buildImage($config);
        }

        // 2. Ensure Namespace exists
        $this->withSpin("Ensuring namespace '$namespace' exists...", function () use ($namespace) {
            exec("kubectl create namespace $namespace --dry-run=client -o yaml | kubectl apply -f -");
        });

        // 3. Handle .env injection
        $envFile = $environment === 'local' ? '.env' : ".env.$environment";
        $envPath = getcwd().'/'.$envFile;

        if (file_exists($envPath)) {
            $this->withSpin('Injecting configuration and blueprint...', function () use ($namespace, $envPath, $projectPath, $config, $environment) {
                exec("kubectl create configmap laravel-config -n $namespace --from-env-file=$envPath --dry-run=client -o yaml | kubectl apply -f -");
                exec("kubectl create secret generic laravel-secrets -n $namespace --from-env-file=$envPath --dry-run=client -o yaml | kubectl apply -f -");

                // Persist locally and sync blueprint to cluster for resilience
                $this->saveProjectConfig($projectPath, $config, $environment);
            });
        } else {
            $this->laraKubeError("Environment file $envFile not found! Deployment may fail due to missing configuration.");
        }

        // 4. Apply manifests
        $this->laraKubeInfo('Applying Kubernetes manifests...');

        // --- 🛡 SAFETY: Handle Immutable PersistentVolumes ---
        // If a PersistentVolume's path has changed (e.g. cloned to a new location),
        // we must delete the old one because K8s spec.hostPath is immutable.
        if ($environment === 'local') {
            $this->withSpin('Optimizing local storage bindings...', function () use ($config) {
                $appName = $config->getName();
                $pvNames = [
                    "{$appName}-laravel-storage-pv",
                    "{$appName}-laravel-data-pv",
                ];

                foreach ($pvNames as $pvName) {
                    $currentPath = shell_exec("kubectl get pv {$pvName} -o jsonpath='{.spec.hostPath.path}' 2>/dev/null");
                    if ($currentPath && trim($currentPath) !== $config->getPath()) {
                        // Path mismatch! Delete the PV (data is safe because it's a hostPath)
                        exec("kubectl delete pv {$pvName} --grace-period=0 --force 2>/dev/null");
                    }
                }

                return true;
            });
        }

        // Scale down to release file locks (Safe transition)
        $this->withSpin('Preparing cluster for architectural update...', function () use ($namespace) {
            exec("kubectl scale deployment --all --replicas=0 -n $namespace 2>/dev/null");
        });

        passthru("kubectl apply -k $path");

        // 5. Restart deployments to pick up new ConfigMap/Secret changes
        $this->laraKubeInfo('Restarting deployments to apply potential configuration changes...');
        passthru("kubectl rollout restart deployment -n $namespace");

        // 6. Proactive HTTPS Trust Check
        if ($environment === 'local' && str_starts_with($config->getAppUrl(), 'https://') && ! $this->isSslTrusted()) {
            $this->newLine();
            $this->warn(' 🔒 This project is configured for HTTPS, but your system does not trust the LaraKube Local CA yet.');
            if (confirm('Would you like us to install it now for you? (Requires sudo/admin)', true)) {
                $this->call('trust');
            }
        }

        // 7. Console
        $openConsole = $this->option('console') || (! $this->option('no-console') && confirm('Would you like to open the console to monitor the deployment?'));
        if ($openConsole) {
            $this->call('console', ['environment' => $environment]);
        }

        $this->renderStarPrompt();

        if ($config->getId()) {
            $this->logToConsole($config->getId(), 'up', "Deployed project '{$config->getName()}' to {$environment} environment.");
        }

        return 0;
    }
}
