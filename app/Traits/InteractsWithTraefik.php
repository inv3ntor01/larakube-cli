<?php

namespace App\Traits;

trait InteractsWithTraefik
{
    use LaraKubeOutput;

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

        $stubPath = base_path('resources/stubs/k8s/traefik-install.yaml.stub');
        $tmpInstall = sys_get_temp_dir().'/traefik-install.yaml';
        file_put_contents($tmpInstall, file_get_contents($stubPath));

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
     */
    protected function createTraefikInfrastructure(): void
    {
        $namespace = 'traefik';
        shell_exec("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");

        // 1. Create ConfigMap for Traefik configuration
        $traefikYml = base_path('resources/stubs/traefik/dev/traefik.yml.stub');
        $traefikCertsYml = base_path('resources/stubs/traefik/dev/traefik-certs.yml.stub');

        $tmpYml = sys_get_temp_dir().'/traefik.yml';
        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpYml, file_get_contents($traefikYml));
        file_put_contents($tmpCertsYml, file_get_contents($traefikCertsYml));

        shell_exec("kubectl create configmap traefik-config -n {$namespace} --from-file=traefik.yml={$tmpYml} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply -f -");

        // 2. Create Secret for SSL certificates
        $certDir = base_path('resources/stubs/traefik/dev/certificates');
        $tmpDevPem = sys_get_temp_dir().'/local-dev.pem';
        $tmpDevKeyPem = sys_get_temp_dir().'/local-dev-key.pem';
        file_put_contents($tmpDevPem, file_get_contents("{$certDir}/local-dev.pem"));
        file_put_contents($tmpDevKeyPem, file_get_contents("{$certDir}/local-dev-key.pem"));

        shell_exec("kubectl create secret generic traefik-certificates -n {$namespace} --from-file=local-dev.pem={$tmpDevPem} --from-file=local-dev-key.pem={$tmpDevKeyPem} --dry-run=client -o yaml | kubectl apply -f -");

        @unlink($tmpYml);
        @unlink($tmpCertsYml);
        @unlink($tmpDevPem);
        @unlink($tmpDevKeyPem);
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
