<?php

namespace App\Commands\Bundle;

use App\Traits\GeneratesBundleSecrets;
use App\Traits\GeneratesOfflineCertificates;
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
    use GeneratesBundleSecrets, GeneratesOfflineCertificates, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput, PromptsForHosts;

    protected $signature = 'bundle:install
                            {--env-file= : Path to a custom .env file to merge with auto-generated secrets}';

    protected $description = 'Install an air-gapped bundle on the current server';

    public function handle(): int
    {
        $this->renderHeader();

        // 0. Pre-flight checks
        $missing = [];
        foreach (['openssl', 'curl', 'tar'] as $tool) {
            if (shell_exec("which {$tool} 2>/dev/null") === null) {
                $missing[] = $tool;
            }
        }
        if (count($missing) > 0) {
            $this->laraKubeError('Missing required system tools: '.implode(', ', $missing));
            $this->line('  <fg=gray>Please ensure these basic Linux utilities are installed before running the offline installer.</>');

            return 1;
        }

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

        // 2. Detect/install k3s (INSTALL_K3S_SKIP_DOWNLOAD=true)
        $this->laraKubeInfo('Ensuring k3s is installed (offline mode)...');
        $k3sInstalled = (shell_exec('which k3s') !== null && trim((string) shell_exec('which k3s')) !== '');

        if (! $k3sInstalled) {
            $this->line('  <fg=gray>K3s not found. Installing from offline artifacts...</>');

            // Put images in containerd directory BEFORE install
            passthru('mkdir -p /var/lib/rancher/k3s/agent/images/');
            passthru('cp k3s-airgap-images.tar /var/lib/rancher/k3s/agent/images/');

            // Put k3s binary in PATH
            passthru('cp k3s /usr/local/bin/k3s');

            // Run offline installer
            passthru('INSTALL_K3S_SKIP_DOWNLOAD=true ./k3s-install.sh --disable=traefik --write-kubeconfig-mode 644', $k3sCode);
            if ($k3sCode !== 0) {
                $this->laraKubeError('K3s installation failed.');

                return 1;
            }
            $this->laraKubeInfo('✅ K3s installed successfully.');
        } else {
            $this->laraKubeInfo('✅ K3s is already installed. Skipping installation.');
        }

        $this->laraKubeInfo('Waiting for K3s containerd to become ready...');
        for ($i = 0; $i < 30; $i++) {
            exec('k3s ctr version >/dev/null 2>&1', $output, $code);
            if ($code === 0) {
                break;
            }
            sleep(1);
        }

        $this->laraKubeInfo('Importing application and dependency images into containerd...');
        foreach (glob('images/*.tar') as $tar) {
            $this->line("  <fg=gray>import</> {$tar}");
            $success = false;
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                passthru('k3s ctr images import '.escapeshellarg($tar), $importCode);
                if ($importCode === 0) {
                    $success = true;
                    break;
                }

                $this->line("  <fg=yellow>import failed. Waiting for containerd to recover... ({$attempt}/10)</>");

                // Actively wait for the containerd socket to come back online
                for ($wait = 0; $wait < 30; $wait++) {
                    exec('k3s ctr version >/dev/null 2>&1', $output, $code);
                    if ($code === 0) {
                        break;
                    }
                    sleep(1);
                }

                // Give it an extra 5 seconds of breathing room after the socket responds
                sleep(5);
            }

            if (! $success) {
                $this->laraKubeError("Failed to import {$tar} after 10 attempts.");

                return 1;
            }
        }

        // 4. Secrets Generation & Customer .env Merging
        $ns = escapeshellarg($namespace);

        // Extract existing secrets from the cluster (for idempotent updates)
        $existingSecretsJson = shell_exec("kubectl get secret laravel-secrets -n {$ns} -o json 2>/dev/null");
        $existingSecrets = [];
        if ($existingSecretsJson) {
            $parsed = json_decode($existingSecretsJson, true);
            if (isset($parsed['data']) && is_array($parsed['data'])) {
                foreach ($parsed['data'] as $k => $v) {
                    $existingSecrets[$k] = base64_decode($v);
                }
            }
        }

        $this->laraKubeInfo('Generating secure install secrets...');
        $mergedEnv = $this->generateInstallSecrets($config, $env, $existingSecrets);

        $envFile = (string) $this->option('env-file');
        if ($envFile === '') {
            $envFile = getcwd().'/.env';
        }

        if (! file_exists($envFile) && file_exists(getcwd().'/.env.example')) {
            $this->newLine();
            $this->laraKubeInfo('Almost ready to deploy! We found a .env.example file in the bundle.');
            $this->line('  Please copy it to .env and fill in any required third-party API keys (like AIRTABLE_API_KEY).');

            \Laravel\Prompts\pause('Press ENTER when you have created and saved the .env file.');
        }

        if (file_exists($envFile)) {
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
        } elseif ((string) $this->option('env-file') !== '') {
            $this->laraKubeError("Provided env file '".((string) $this->option('env-file'))."' does not exist.");

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

        // 5. promptForHosts for the hostname
        $components = $config->getComponents($env);
        $webDefault = $config->getWebHost($env); // The default FQDN from the blueprint

        $this->laraKubeInfo('Configuring hostnames...');
        $hosts = $this->promptForHosts($env, $components, $webDefault);

        // Update ConfigData with the chosen hosts
        foreach ($hosts as $service => $host) {
            $config->setHost($service, $host, $env);
        }

        // 6. Generate CA+cert + wire Traefik TLSStore
        $this->laraKubeInfo('Generating secure local CA and TLS certificates...');
        $certDir = sys_get_temp_dir().'/larakube-certs-'.time();
        @mkdir($certDir, 0700, true);
        $certs = $this->generateSanCertificates(array_values($hosts), $certDir);

        $this->laraKubeInfo('Waiting for Kubernetes API to be ready...');
        for ($wait = 0; $wait < 60; $wait++) {
            exec('kubectl get nodes >/dev/null 2>&1', $output, $code);
            if ($code === 0) {
                break;
            }
            sleep(2);
        }

        // Ensure namespace exists before we create secrets
        shell_exec("kubectl create namespace {$ns} --dry-run=client -o yaml | kubectl apply -f -");

        // Traefik expects the TLSStore in its own namespace for the default certificate
        shell_exec('kubectl create namespace traefik --dry-run=client -o yaml | kubectl apply -f -');

        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, view('traefik.dev-certs')->render());
        shell_exec("kubectl create configmap traefik-config -n traefik --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply -f -");

        $tmpTlsCrt = escapeshellarg($certs['tls_crt']);
        $tmpTlsKey = escapeshellarg($certs['tls_key']);
        shell_exec("kubectl create secret generic traefik-certificates -n traefik --from-file=local-dev.pem={$tmpTlsCrt} --from-file=local-dev-key.pem={$tmpTlsKey} --dry-run=client -o yaml | kubectl apply -f -");

        @unlink($tmpCertsYml);

        // 7. Deploy Traefik
        $this->laraKubeInfo('Deploying Traefik Ingress Controller...');
        $tmpInstall = sys_get_temp_dir().'/traefik-install.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-install')->render());
        passthru("kubectl apply -f {$tmpInstall}");
        @unlink($tmpInstall);

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

        // Use the standalone Kustomize binary bundled with the tarball to bypass older K3s parser bugs
        $applyCmd = getcwd().'/kustomize build '.escapeshellarg($overlayPath).' | kubectl apply -f -';

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
        $this->newLine();
        $this->line('  <fg=gray>To secure your browser, install the generated Certificate Authority:</>');
        $this->line('  <fg=cyan>1. Download the CA certificate to your computer:</> '.$certs['ca_crt']);
        $this->line('  <fg=cyan>2. Add it to your OS/Browser Trust Store.</>');

        return 0;
    }
}
