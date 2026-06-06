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

    protected $description = 'Provision a DigitalOcean Kubernetes (DOKS) cluster with Traefik and Let\'s Encrypt TLS';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Provision DigitalOcean Kubernetes (DOKS)');
        $this->newLine();

        $context = $this->option('context') ?: $this->askForClusterContext();
        if (! $context) {
            $this->laraKubeError('No Kubernetes context selected.');

            return 1;
        }

        $this->line("  <fg=gray>Target context:</> <fg=cyan>{$context}</>");
        $this->newLine();

        // Collected up front — the ACME resolver is configured at install time.
        $email = text(
            label: 'Email for Let\'s Encrypt certificate notices',
            placeholder: 'you@example.com',
            required: true,
            validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.',
        );

        if (! confirm('Install Traefik + Let\'s Encrypt (HTTP-01) on this cluster?', true)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        $this->newLine();
        $this->laraKubeInfo('Installing Traefik with a Let\'s Encrypt (ACME) resolver...');
        if ($this->installTraefik($context, $email) !== 0) {
            $this->laraKubeError('Traefik installation failed.');

            return 1;
        }

        $this->laraKubeInfo('Waiting for the LoadBalancer IP...');
        $ip = $this->waitForLoadBalancerIp($context);
        if (! $ip) {
            $this->laraKubeError('LoadBalancer IP not assigned within 120s. Check cluster resources, then retry.');

            return 1;
        }

        $this->laraKubeInfo("✅ LoadBalancer IP: <fg=cyan>{$ip}</>");
        $this->newLine();
        $this->displayNextSteps($ip);

        return 0;
    }

    /** Install Traefik with a persistent ACME (Let's Encrypt, HTTP-01) resolver. */
    private function installTraefik(string $context, string $email): int
    {
        exec('kubectl --context '.escapeshellarg($context).' get deployment -n traefik traefik 2>/dev/null', $out, $code);
        if ($code === 0) {
            $this->laraKubeInfo('ℹ️  Traefik is already installed — skipping. (Re-install to change ACME settings.)');

            return 0;
        }

        exec('helm repo add traefik https://traefik.github.io/charts 2>/dev/null');
        exec('helm repo update 2>/dev/null');

        // persistence → a PVC (on DOKS\'s default do-block-storage) holds acme.json
        // across restarts/nodes; certResolver "letsencrypt" issues per-router certs.
        $install = 'helm install traefik traefik/traefik '
            .'--namespace traefik --create-namespace '
            .'--set service.type=LoadBalancer '
            .'--set ingressClass.enabled=true '
            .'--set ingressClass.isDefaultClass=true '
            .'--set persistence.enabled=true '
            .'--set persistence.size=128Mi '
            .'--set '.escapeshellarg('certificatesResolvers.letsencrypt.acme.email='.$email).' '
            .'--set '.escapeshellarg('certificatesResolvers.letsencrypt.acme.storage=/data/acme.json').' '
            .'--set '.escapeshellarg('certificatesResolvers.letsencrypt.acme.httpChallenge.entryPoint=web').' '
            .'--set '.escapeshellarg('ports.websecure.tls.certResolver=letsencrypt');

        passthru($install, $code);

        return $code === 0 ? 0 : 1;
    }

    private function waitForLoadBalancerIp(string $context): ?string
    {
        $maxAttempts = 60; // 120s at 2s/attempt
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

    private function displayNextSteps(string $ip): void
    {
        $this->line('  <fg=green>Next steps:</>');
        $this->newLine();
        $this->line('  1️⃣  <fg=yellow>Point your domain at the LoadBalancer IP</> (A record):');
        $this->line("       <fg=cyan>app.example.com  A  {$ip}</>");
        $this->newLine();
        $this->line('  2️⃣  <fg=yellow>Configure your env for DOKS</> in <fg=cyan>.larakube.json</>:');
        $this->line('       "ingress": "traefik",');
        $this->line('       "strategy": "multi-node-ha",');
        $this->line('       "hosts": { "web": "app.example.com" },');
        $this->line('       "storageClass": "do-block-storage",');
        $this->line('       "registry": { "provider": "ghcr" },');
        $this->line('       "ingressAnnotations": {');
        $this->line('         "traefik.ingress.kubernetes.io/router.entrypoints": "websecure",');
        $this->line('         "traefik.ingress.kubernetes.io/router.tls.certresolver": "letsencrypt"');
        $this->line('       }');
        $this->line('     <fg=gray>(the last block tells Traefik to fetch a Let\'s Encrypt cert for this app)</>');
        $this->newLine();
        $this->line('  3️⃣  <fg=yellow>Deploy</> once DNS resolves:');
        $this->line('       larakube cloud:deploy production');
        $this->newLine();
    }
}
