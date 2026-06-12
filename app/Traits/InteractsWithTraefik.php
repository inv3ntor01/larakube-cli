<?php

namespace App\Traits;

trait InteractsWithTraefik
{
    use LaraKubeOutput, ManagesLocalCa;

    /**
     * Ensure Traefik and its dependencies are installed and configured.
     */
    protected function setupTraefik(bool $force = false): void
    {
        $this->laraKubeInfo('Synchronizing Traefik Ingress Controller...');

        $this->withSpin('Creating Traefik infrastructure (SSL & Config)...', function () {
            $this->createTraefikInfrastructure();

            return true;
        });

        $tmpInstall = sys_get_temp_dir().'/traefik-install.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-install')->render());

        $this->withSpin('Applying Traefik manifests...', function () use ($tmpInstall) {
            exec("kubectl apply -f {$tmpInstall}");

            return true;
        });

        $this->withSpin('Waiting for Traefik to be ready...', function () {
            exec('kubectl wait --for=condition=ready pod -l app=traefik -n traefik --timeout=60s');

            return true;
        });

        @unlink($tmpInstall);
    }

    /**
     * Create the ConfigMap and Secret required for Traefik local SSL.
     * Called once when Traefik is first installed.
     */
    protected function createTraefikInfrastructure(): void
    {
        $namespace = 'traefik';
        shell_exec("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");

        $this->ensureSystemCertExists();
        $this->applyTraefikCertResources($namespace);
    }

    /**
     * Ensure this app's cert is in the Traefik cert pool.
     * Called on every `larakube up` so new apps join automatically.
     */
    protected function refreshTraefikCerts(string $appName): void
    {
        $this->ensureAppCertExists($appName);
        $this->applyTraefikCertResources('traefik');
    }

    /**
     * Rebuild traefik-config ConfigMap and traefik-certificates Secret from all
     * locally-generated certs, then restart Traefik to pick up changes.
     */
    protected function applyTraefikCertResources(string $namespace): void
    {
        // 1. ConfigMap — dynamic YAML listing all cert pairs
        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, $this->buildTraefikCertsYml());
        // Server-side apply avoids storing base64 cert blobs in the
        // last-applied-configuration annotation (256 KB limit overflows with multiple certs).
        shell_exec("kubectl create configmap traefik-config -n {$namespace} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply --server-side --field-manager=larakube --force-conflicts -f -");
        @unlink($tmpCertsYml);

        // 2. Secret — all cert files from ~/.larakube/certificates/
        $fromFiles = ' --from-file=system-dev.pem='.escapeshellarg($this->getSystemCertPath())
            .' --from-file=system-dev-key.pem='.escapeshellarg($this->getSystemKeyPath());

        foreach ($this->getAllLocalAppCerts() as $appName => $paths) {
            $fromFiles .= ' --from-file='.escapeshellarg("{$appName}-dev.pem={$paths['crt']}");
            $fromFiles .= ' --from-file='.escapeshellarg("{$appName}-dev-key.pem={$paths['key']}");
        }

        shell_exec("kubectl create secret generic traefik-certificates -n {$namespace}{$fromFiles} --dry-run=client -o yaml | kubectl apply --server-side --field-manager=larakube --force-conflicts -f -");

        // 3. Restart Traefik to pick up changes (only if it exists)
        $exists = shell_exec("kubectl get deployment traefik -n {$namespace} 2>/dev/null");
        if ($exists) {
            shell_exec("kubectl rollout restart deployment traefik -n {$namespace}");
        }
    }

    /**
     * Check if Traefik is currently active in the cluster.
     */
    protected function isTraefikInstalled(): bool
    {
        return shell_exec('kubectl get svc traefik -n traefik 2>/dev/null') !== null;
    }

    /**
     * Completely remove Traefik and its cluster-scoped resources.
     */
    protected function destroyTraefik(): void
    {
        $this->laraKubeInfo('Destroying Traefik Ingress Controller...');

        $this->withSpin('Removing Traefik namespace and internal resources...', function () {
            exec('kubectl delete namespace traefik --wait=true 2>/dev/null');

            return true;
        });

        $this->withSpin('Cleaning up cluster-scoped RBAC permissions...', function () {
            exec('kubectl delete clusterrole traefik-ingress-controller 2>/dev/null');
            exec('kubectl delete clusterrolebinding traefik-ingress-controller 2>/dev/null');

            return true;
        });
    }
}
