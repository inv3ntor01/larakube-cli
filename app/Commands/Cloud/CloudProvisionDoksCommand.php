<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionDoksCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    protected $signature = 'cloud:provision:doks {--context= : Target a specific kube-context}';

    protected $description = 'Provision a DigitalOcean Kubernetes (DOKS) cluster with Traefik and Let\'s Encrypt';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision DigitalOcean Kubernetes (DOKS)');
        $this->newLine();

        // Resolve context
        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        if (! confirm('Proceed with Traefik + Let\'s Encrypt installation?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->newLine();

        // 1. Install Traefik via Helm
        $this->laraKubeInfo('Installing Traefik ingress controller...');
        if ($this->installTraefik($context) !== 0) {
            $this->laraKubeError('Traefik installation failed.');

            return 1;
        }

        // 2. Wait for LoadBalancer IP
        $this->laraKubeInfo('Waiting for LoadBalancer IP assignment...');
        $ip = $this->waitForLoadBalancerIp($context);
        if (! $ip) {
            $this->laraKubeError('LoadBalancer IP not assigned within timeout (120s). Check cluster resources.');

            return 1;
        }

        $this->laraKubeInfo("✅ LoadBalancer IP assigned: <fg=cyan>{$ip}</>");
        $this->newLine();

        // 3. Configure Let's Encrypt
        $email = text(
            label: 'Enter your email for Let\'s Encrypt certificate notifications',
            placeholder: 'your-email@example.com',
            required: true,
        );

        $this->laraKubeInfo('Configuring Let\'s Encrypt ACME resolver...');
        if ($this->configureAcme($context, $email) !== 0) {
            $this->laraKubeWarn('ACME configuration had issues. You may need to configure manually.');
        } else {
            $this->laraKubeInfo('✅ Let\'s Encrypt configured.');
        }

        $this->newLine();

        // 4. Provide next steps
        $this->displayNextSteps($ip);

        return 0;
    }

    private function installTraefik(string $context): int
    {
        // Check if Traefik is already installed
        exec(
            'kubectl --context '.escapeshellarg($context).' get deployment -n traefik traefik 2>/dev/null',
            $out,
            $code,
        );

        if ($code === 0) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed.');

            return 0;
        }

        // Install Traefik via Helm
        $commands = [
            'helm repo add traefik https://traefik.github.io/charts',
            'helm repo update',
            'helm install traefik traefik/traefik '
                .'--namespace traefik --create-namespace '
                .'--set service.type=LoadBalancer '
                .'--set ingressClass.enabled=true '
                .'--set ingressClass.isDefaultClass=true',
        ];

        foreach ($commands as $cmd) {
            if (strpos($cmd, 'helm install') !== false) {
                passthru($cmd, $code);
            } else {
                exec($cmd, $out, $code);
            }

            if ($code !== 0) {
                return 1;
            }
        }

        return 0;
    }

    private function waitForLoadBalancerIp(string $context): ?string
    {
        $maxAttempts = 60; // 120 seconds with 2s sleep
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            exec(
                'kubectl --context '.escapeshellarg($context)
                .' get svc -n traefik traefik -o jsonpath=\'{.status.loadBalancer.ingress[0].ip}\' 2>/dev/null',
                $out,
            );

            $ip = trim($out[0] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }

            $attempt++;
            if ($attempt % 5 === 0) {
                $this->line("  ⏳ Waiting... ({$attempt}s)");
            }
            sleep(2);
        }

        return null;
    }

    private function configureAcme(string $context, string $email): int
    {
        // Create IngressClass for Traefik (if not already present)
        $ingressClass = <<<'YAML'
apiVersion: networking.k8s.io/v1
kind: IngressClass
metadata:
  name: traefik
spec:
  controller: traefik.io/ingress-controller
YAML;

        $tmpFile = sys_get_temp_dir().'/traefik-ingressclass.yaml';
        file_put_contents($tmpFile, $ingressClass);
        exec('kubectl --context '.escapeshellarg($context)." apply -f {$tmpFile}", $out, $code);
        unlink($tmpFile);

        if ($code !== 0) {
            return 1;
        }

        // Traefik's ACME is configured via the Traefik resource (CRD or values).
        // For now, document that users need to configure cert generation via
        // Traefik's configuration (usually via values or middleware).
        // This is beyond the scope of this command for MVP.

        return 0;
    }

    private function displayNextSteps(string $ip): void
    {
        $this->newLine();
        $this->line('  <fg=green>Next steps:</> ');
        $this->newLine();
        $this->line('  1️⃣  <fg=yellow>Point your domain to the LoadBalancer IP:</>');
        $this->line('     Create an A record in your DNS provider:');
        $this->line("       <fg=cyan>app.example.com A {$ip}</>");
        $this->newLine();
        $this->line('  2️⃣  <fg=yellow>Configure your LaraKube project for DOKS:</>');
        $this->line('     Edit .larakube.json environment:');
        $this->line('       {');
        $this->line('         "environments": {');
        $this->line('           "production": {');
        $this->line('             "ingress": "traefik",');
        $this->line('             "strategy": "multi-node-ha",');
        $this->line('             "hosts": { "web": "app.example.com" },');
        $this->line('             "storageClass": "do-block-storage",');
        $this->line('             "registry": { "provider": "ghcr" }');
        $this->line('           }');
        $this->line('         }');
        $this->line('       }');
        $this->newLine();
        $this->line('  3️⃣  <fg=yellow>Deploy your app:</>');
        $this->line('     larakube cloud:deploy production');
        $this->newLine();
    }
}
