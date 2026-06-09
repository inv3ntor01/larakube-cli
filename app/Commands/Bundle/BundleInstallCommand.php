<?php

namespace App\Commands\Bundle;

use App\Traits\GeneratesBundleSecrets;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use App\Traits\PromptsForHosts;
use LaravelZero\Framework\Commands\Command;

/**
 * Install-side: extract and deploy the air-gapped bundle on the customer's server.
 * This runs completely offline. It reads the customer's .env if provided, merges
 * it with auto-generated secure credentials, imports the container images, and
 * applies the K8s manifests to the local k3s cluster.
 */
class BundleInstallCommand extends Command
{
    use GeneratesBundleSecrets, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput, PromptsForHosts;

    protected $signature = 'bundle:install
                            {--env-file= : Path to a custom .env file to merge with auto-generated secrets}';

    protected $description = 'Install an air-gapped bundle on the current server';

    public function handle(): int
    {
        $this->renderHeader();

        // 1. Verify we are inside an extracted bundle directory
        if (! file_exists('bundle.json') || ! file_exists('.larakube.json')) {
            $this->laraKubeError("This command must be run from inside an extracted air-gapped bundle directory.\nMake sure bundle.json and .larakube.json are present.");

            return 1;
        }

        $bundleManifest = json_decode((string) file_get_contents('bundle.json'), true);
        if (! is_array($bundleManifest)) {
            $this->laraKubeError('Invalid bundle.json.');

            return 1;
        }

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Failed to load .larakube.json.');

            return 1;
        }

        $env = $bundleManifest['environment'];
        $namespace = $config->getNamespace($env);
        $name = $config->getName();

        $this->laraKubeInfo("Installing air-gapped bundle — {$name} · {$env}");

        // TODO: 2. Detect/install k3s (INSTALL_K3S_SKIP_DOWNLOAD=true)
        $this->laraKubeWarn('TODO: k3s detection and offline install');

        // TODO: 3. ctr images import the tarballs
        $this->laraKubeWarn('TODO: ctr images import images/*.tar');

        // 4. Secrets Generation & Customer .env Merging
        $this->laraKubeInfo('Generating secure install secrets...');
        $mergedEnv = $this->generateInstallSecrets($config, $env);

        $envFile = (string) $this->option('env-file');
        if ($envFile !== '' && file_exists($envFile)) {
            $this->line('  <fg=gray>Merging customer provided env file:</> <fg=cyan>'.$envFile.'</>');
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                if ($key !== '') {
                    // Customer overrides take precedence (e.g., they can override DB_PASSWORD if they really want)
                    $mergedEnv[$key] = trim($value);
                }
            }
        } elseif ($envFile !== '') {
            $this->laraKubeError("Provided env file '{$envFile}' does not exist.");

            return 1;
        }

        $mergedLines = [];
        foreach ($mergedEnv as $k => $v) {
            $mergedLines[] = "{$k}={$v}";
        }

        // Get known secrets from ConfigData
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($env));

        // Split for K8s ConfigMap (public) vs Secret (secret)
        ['public' => $public, 'secret' => $secret] = $this->splitEnvForK8s($mergedLines, $knownSecrets);

        // TODO: 5. Generate CA+cert + wire Traefik TLSStore
        $this->laraKubeWarn('TODO: Generate CA+cert + wire Traefik TLSStore');

        // 6. promptForHosts for the hostname
        $components = $config->getComponents($env);
        $webDefault = $config->getFqdn($env); // The default FQDN from the blueprint

        $this->laraKubeInfo('Configuring hostnames...');
        $hosts = $this->promptForHosts($env, $components, $webDefault);

        // Update ConfigData with the chosen hosts
        foreach ($hosts as $service => $host) {
            $config->setHost($service, $host, $env);
        }

        // 7. Write the Secret/ConfigMap into the cluster
        // We use `kubectl` against the local cluster. Since we are on-box, we use the default context (k3s).
        $this->laraKubeInfo('Applying ConfigMap and Secrets...');
        $ns = escapeshellarg($namespace);

        // Create namespace if not exists (ignore errors if it already exists)
        shell_exec("kubectl create namespace {$ns} --dry-run=client -o yaml | kubectl apply -f -");

        if ($public !== '') {
            shell_exec("kubectl create configmap laravel-config -n {$ns} {$public} --dry-run=client -o yaml | kubectl apply -f -");
        }
        if ($secret !== '') {
            shell_exec("kubectl create secret generic laravel-secrets -n {$ns} {$secret} --dry-run=client -o yaml | kubectl apply -f -");
        }

        // 8. Apply manifests
        $this->laraKubeInfo('Applying Kubernetes manifests...');
        $overlayPath = getcwd().'/manifests/overlays/'.$env;

        if (! is_dir($overlayPath)) {
            $this->laraKubeError("Manifests not found at {$overlayPath}. Ensure bundle:build copied them correctly.");

            return 1;
        }

        // The app image is already in the tarball under `<app>:latest`, and manifests reference it. No rewrite needed.
        $applyCmd = 'kubectl kustomize '.escapeshellarg($overlayPath).' | kubectl apply -f -';

        passthru($applyCmd, $applyCode);
        if ($applyCode !== 0) {
            $this->laraKubeError('Manifest apply failed.');

            return 1;
        }

        // 9. Wait for rollout
        $this->laraKubeInfo('Waiting for rollout...');
        passthru('kubectl rollout status deploy/web -n '.escapeshellarg($namespace).' --timeout=180s');

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle successfully installed!');
        if (isset($hosts['web'])) {
            $this->line("  <fg=gray>Your app should be available at:</> <fg=cyan>https://{$hosts['web']}</>");
        }
        $this->laraKubeWarn('TODO: Print larakube trust CA instructions');

        return 0;
    }
}
