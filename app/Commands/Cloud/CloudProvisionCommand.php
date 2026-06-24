<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\InteractsWithServerHardening;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudProvisionCommand extends Command
{
    use InteractsWithProjectConfig, InteractsWithRemoteSsh, InteractsWithServerHardening, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:init
        {target? : What to provision — "vps" (default) or "doks". Omit to be asked.}
        {--context= : (DOKS only) target a specific kube-context}';

    /**
     * Backward-compatible alias for the pre-rename command name.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:provision'];

    /**
     * The console command description.
     */
    protected $description = 'Secures and prepares a fresh VPS for LaraKube (K3s Single-Node)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        // Which target? Explicit arg ("vps"/"doks") wins; otherwise ask.
        $target = $this->argument('target') ?: select(
            label: 'What are you provisioning?',
            options: [
                'vps' => 'VPS / bare server (SSH + k3s, single-node)',
                'doks' => 'DigitalOcean Kubernetes (managed, multi-node)',
            ],
            default: 'vps',
        );

        // DOKS is a separate flow — delegate to its dedicated command.
        if ($target === 'doks') {
            return (int) $this->call('cloud:init:doks', array_filter([
                '--context' => $this->option('context'),
            ]));
        }

        if ($target !== 'vps') {
            $this->laraKubeError("Unknown provisioning target: '{$target}'. Use 'vps' or 'doks'.");

            return 1;
        }

        $this->laraKubeInfo('LaraKube Cloud Pilot: VPS Provisioner');
        $this->laraKubeWarn('Recommended: 1GB RAM minimum for stable K3s deployments.');
        $this->newLine();

        $ip = text(
            label: 'What is the IP address of your fresh VPS?',
            required: true,
            placeholder: 'e.g. 123.45.67.89',
        );

        $user = text(
            label: 'SSH User (must have sudo access)',
            default: 'root',
        );

        $port = text(
            label: 'SSH Port',
            default: '22',
        );

        $keyPath = text(
            label: 'Path to your SSH Private Key',
            default: home_path('.ssh/id_rsa'),
        );

        // Resolve ~ in keyPath
        $keyPath = str_replace('~', home_path(), $keyPath);

        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        // --- 🛡 GLOBAL SECURITY CONTEXT ---
        $email = $this->getEmail();
        if (! $email) {
            $email = text(
                label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                placeholder: 'admin@example.com',
                required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.',
            );
            $this->setEmail($email);
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");

        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeError('Could not connect to the server via SSH. Please check your credentials and try again.');

            return 1;
        }

        $this->laraKubeInfo('Connection successful!');

        $config = $this->getProjectConfigObject(getcwd());

        // 1. Install K3s
        if (confirm('Install K3s on the remote server?', true)) {
            $this->installK3s($user, $ip, $port, $keyPath, $config);
        }

        // 2. Create larakube user if it's root
        if ($user === 'root') {
            if (confirm('Create a dedicated "larakube" user with sudo access?', true)) {
                $this->createLaraKubeUser($user, $ip, $port, $keyPath);
            }
        }

        // 3. Harden the server (firewall + fail2ban + auto-updates + key-only SSH)
        if (confirm('Harden the server now (UFW firewall, fail2ban, auto-updates, key-only SSH)?', true)) {
            $this->hardenServer($user, $ip, (int) $port, $keyPath);
        }

        // 4. Optionally close remote root login (only once "larakube" is a working
        // sudo login, so we never strand the box). If we do close it, every step
        // after this must connect as "larakube" — root SSH no longer works.
        if ($user === 'root' && confirm('Disable remote root SSH login? ("larakube" becomes your login — recommended)', true)) {
            if ($this->lockDownRootLogin($user, $ip, (int) $port, $keyPath)) {
                $user = 'larakube';
            }
        }

        // 5. Sync Kubeconfig (as $user — now "larakube" if root login was closed)
        $contextName = "larakube-{$ip}";
        if (confirm('Sync remote Kubeconfig to your local machine?', true)) {
            $this->syncKubeconfig($user, $ip, $port, $keyPath, $contextName);
        }

        // 6. Deploy Traefik
        if (confirm('Deploy Traefik (Single-Node Hero)?', true)) {
            $this->deployTraefik($contextName);
        }

        $this->laraKubeInfo('✅ Provisioning complete!');
        $this->info('Your VPS is now a LaraKube-hardened K3s node.');

        return 0;
    }

    /**
     * Install K3s on the remote server.
     */
    protected function installK3s($user, $ip, $port, $keyPath, $config): void
    {
        $this->laraKubeInfo('Hardening OS and Installing K3s on remote server...');

        $k3sVersion = escapeshellarg($config->k3sVersion ?? 'v1.30.4+k3s1');

        // 1. Create Swap (Crucial for 512MB droplets)
        // 2. Enable IP Forwarding
        // 3. Install K3s (optimized for single-node)
        $remoteCommand = <<<BASH
    if [ ! -f /swapfile ]; then
    echo "Creating 1GB Swap file for stability..."
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab
    fi

    echo "Enabling IP Forwarding..."
    sysctl -w net.ipv4.ip_forward=1
    grep -qxF 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' | tee -a /etc/sysctl.conf

    echo "Installing K3s..."
    curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION={$k3sVersion} sh -s - \
      --disable=traefik \
      --write-kubeconfig-mode 644 \
      --kubelet-arg=fail-swap-on=false
    BASH;

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand);

        $this->laraKubeInfo('K3s installed and OS hardened.');
    }

    /**
     * Create a dedicated LaraKube user with sudo access.
     */
    protected function createLaraKubeUser($user, $ip, $port, $keyPath): void
    {
        $this->laraKubeInfo('Creating "larakube" user...');

        $pubKeyPath = $keyPath.'.pub';
        if (! file_exists($pubKeyPath)) {
            $this->laraKubeWarn("Public key not found at {$pubKeyPath}. Skipping user creation.");

            return;
        }

        $pubKey = trim(file_get_contents($pubKeyPath));

        $remoteCommand = <<<BASH
if ! id "larakube" &>/dev/null; then
    useradd -m -s /bin/bash larakube
    usermod -aG sudo larakube
    mkdir -p /home/larakube/.ssh
    echo "{$pubKey}" > /home/larakube/.ssh/authorized_keys
    chown -R larakube:larakube /home/larakube/.ssh
    chmod 700 /home/larakube/.ssh
    chmod 600 /home/larakube/.ssh/authorized_keys
    echo "larakube ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/larakube
fi
BASH;

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand);
        $this->laraKubeInfo('User "larakube" created and configured.');
    }

    /**
     * Harden the freshly provisioned node: UFW (SSH/HTTP/HTTPS/k3s-API + cluster
     * CIDRs), fail2ban, and key-only SSH. The script (built by
     * InteractsWithServerHardening) allows the SSH port before enabling UFW, so
     * this never strands the in-flight connection.
     */
    protected function hardenServer($user, $ip, int $port, $keyPath): void
    {
        $this->laraKubeInfo('Hardening server (firewall, fail2ban, SSH)...');

        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->hardenServerScript($port));

        $this->laraKubeInfo('✅ Hardened: UFW (SSH/80/443/6443 + pod & service CIDRs), fail2ban, auto-updates, key-only SSH.');
        $this->info('   Note: k3s API (6443) is open to the internet — restricting it to your IP is a recommended follow-up.');
    }

    /**
     * Close remote root SSH login — but ONLY after proving the "larakube" user
     * can both log in (same key) and run sudo, so we never cut the last remote
     * admin path. If either check fails, we leave root login enabled and warn.
     */
    protected function lockDownRootLogin($user, $ip, int $port, $keyPath): bool
    {
        $this->laraKubeInfo('Verifying the "larakube" login works before disabling root...');

        if (! $this->testSsh('larakube', $ip, $port, $keyPath)) {
            $this->laraKubeWarn('Could not SSH as "larakube" — leaving root login ENABLED to avoid lockout.');
            $this->info('   (Did you create the larakube user, and does your key have a .pub sibling?)');

            return false;
        }

        if (! $this->canSudo('larakube', $ip, $port, $keyPath)) {
            $this->laraKubeWarn('"larakube" cannot passwordless-sudo — leaving root login ENABLED to avoid lockout.');

            return false;
        }

        // Safe: we're still connected as root here, so this only affects FUTURE logins.
        $this->runRemoteCommand($user, $ip, $port, $keyPath, $this->disableRootLoginScript());

        $this->laraKubeInfo('✅ Remote root login disabled. Using the "larakube" user from now on.');

        return true;
    }

    /**
     * Sync remote Kubeconfig to local machine.
     */
    protected function syncKubeconfig($user, $ip, $port, $keyPath, $contextName): void
    {
        $this->laraKubeInfo('Syncing Kubeconfig...');

        $localKubeConfig = home_path('.kube/config');
        $backupPath = home_path('.kube/config.bak.'.time());

        if (file_exists($localKubeConfig)) {
            copy($localKubeConfig, $backupPath);
            $this->info("  🛡 Local kubeconfig backed up to {$backupPath}");
        } else {
            if (! is_dir(home_path('.kube'))) {
                mkdir(home_path('.kube'), 0700, true);
            }
        }

        // Fetch remote config
        $tmpRemoteConfig = tempnam(sys_get_temp_dir(), 'k3s_remote');
        exec("scp -i {$keyPath} -P {$port} {$user}@{$ip}:/etc/rancher/k3s/k3s.yaml {$tmpRemoteConfig}");

        if (! file_exists($tmpRemoteConfig) || filesize($tmpRemoteConfig) === 0) {
            $this->laraKubeError('Failed to fetch remote kubeconfig.');

            return;
        }

        $configContent = file_get_contents($tmpRemoteConfig);

        // Update 127.0.0.1 to server IP
        $configContent = str_replace('127.0.0.1', $ip, $configContent);

        // Change context name to larakube-{ip}
        $configContent = str_replace('default', $contextName, $configContent);

        file_put_contents($tmpRemoteConfig, $configContent);

        // --- 🛡 SECURE MERGE ENGINE ---
        // We use the KUBECONFIG env var trick to let kubectl handle the YAML merging logic safely
        if (file_exists($localKubeConfig)) {
            $mergeCmd = "KUBECONFIG={$localKubeConfig}:{$tmpRemoteConfig} kubectl config view --flatten";
            $mergedContent = shell_exec($mergeCmd);

            if ($mergedContent) {
                file_put_contents($localKubeConfig, $mergedContent);
            } else {
                $this->laraKubeError('Failed to merge Kubeconfig. Manual intervention required.');
            }
        } else {
            copy($tmpRemoteConfig, $localKubeConfig);
        }

        unlink($tmpRemoteConfig);

        $this->laraKubeInfo("Kubeconfig synced and merged. Context: {$contextName}");
        $this->info("You can now run: kubectl config use-context {$contextName}");
    }

    /**
     * Deploy Traefik to the remote cluster.
     */
    protected function deployTraefik($contextName): void
    {
        $this->laraKubeInfo('Deploying Traefik (Single-Node Hero) to remote cluster...');

        $kubectl = 'kubectl --context '.escapeshellarg($contextName);
        $namespace = 'traefik';

        shell_exec("{$kubectl} create namespace {$namespace} --dry-run=client -o yaml | {$kubectl} apply -f -");

        // 1. Create ConfigMap for Traefik dynamic configuration
        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, view('traefik.dev-certs')->render());
        shell_exec("{$kubectl} create configmap traefik-config -n {$namespace} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | {$kubectl} apply -f -");

        // 2. Create Secret for SSL certificates
        $certDir = base_path('resources/views/traefik/certificates');
        $tmpDevPem = sys_get_temp_dir().'/local-dev.pem';
        $tmpDevKeyPem = sys_get_temp_dir().'/local-dev-key.pem';

        // Ensure paths work in PHAR or Dev
        $devPemContent = @file_get_contents("{$certDir}/local-dev.pem");
        $devKeyPemContent = @file_get_contents("{$certDir}/local-dev-key.pem");

        if ($devPemContent && $devKeyPemContent) {
            file_put_contents($tmpDevPem, $devPemContent);
            file_put_contents($tmpDevKeyPem, $devKeyPemContent);
            shell_exec("{$kubectl} create secret generic traefik-certificates -n {$namespace} --from-file=local-dev.pem={$tmpDevPem} --from-file=local-dev-key.pem={$tmpDevKeyPem} --dry-run=client -o yaml | {$kubectl} apply -f -");
        } else {
            $this->laraKubeWarn('Could not find local dev certificates. Skipping SSL secret creation.');
        }

        // 3. Apply Traefik Cloud manifest
        $tmpInstall = sys_get_temp_dir().'/traefik-cloud.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-cloud', ['email' => $this->getEmail()])->render());
        shell_exec("{$kubectl} apply -f {$tmpInstall} --request-timeout=60s --validate=false");

        $this->laraKubeInfo('Traefik deployed and configured with HostPort and ACME (Let\'sEncrypt).');

        @unlink($tmpCertsYml);
        @unlink($tmpDevPem);
        @unlink($tmpDevKeyPem);
        @unlink($tmpInstall);
    }
}
