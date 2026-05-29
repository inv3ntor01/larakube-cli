<?php

namespace App\Commands\Cloud;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class CloudProvisionCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cloud:provision';

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
        $this->laraKubeInfo('LaraKube Cloud Pilot: VPS Provisioner');
        $this->laraKubeWarn('Recommended: 1GB RAM minimum for stable K3s deployments.');
        $this->newLine();

        $ip = text(
            label: 'What is the IP address of your fresh VPS?',
            required: true,
            placeholder: 'e.g. 123.45.67.89'
        );

        $user = text(
            label: 'SSH User (must have sudo access)',
            default: 'root'
        );

        $port = text(
            label: 'SSH Port',
            default: '22'
        );

        $keyPath = text(
            label: 'Path to your SSH Private Key',
            default: home_path('.ssh/id_rsa')
        );

        // Resolve ~ in keyPath
        $keyPath = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $keyPath);

        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return 1;
        }

        // --- 🛡 GLOBAL SECURITY CONTEXT ---
        $email = $this->getEmail();
        if (! $email) {
            $email = text(
                label: 'What is your email address? (used for SSL/Let\'sEncrypt)',
                placeholder: 'admin@larakube.dev.test',
                required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.'
            );
            $this->setEmail($email);
        }

        $this->laraKubeInfo("Testing SSH connection to {$user}@{$ip}...");

        if (! $this->testSsh($user, $ip, $port, $keyPath)) {
            $this->laraKubeError('Could not connect to the server via SSH. Please check your credentials and try again.');

            return 1;
        }

        $this->laraKubeInfo('Connection successful!');

        // 1. Install K3s
        if (confirm('Install K3s on the remote server?', true)) {
            $this->installK3s($user, $ip, $port, $keyPath);
        }

        // 2. Create larakube user if it's root
        if ($user === 'root') {
            if (confirm('Create a dedicated "larakube" user with sudo access?', true)) {
                $this->createLaraKubeUser($user, $ip, $port, $keyPath);
            }
        }

        // 3. Sync Kubeconfig
        $contextName = "larakube-{$ip}";
        if (confirm('Sync remote Kubeconfig to your local machine?', true)) {
            $this->syncKubeconfig($user, $ip, $port, $keyPath, $contextName);
        }

        // 4. Deploy Traefik
        if (confirm('Deploy Traefik (Single-Node Hero)?', true)) {
            $this->deployTraefik($contextName);
        }

        $this->laraKubeInfo('✅ Provisioning complete!');
        $this->info('Your VPS is now a LaraKube-hardened K3s node.');

        return 0;
    }

    /**
     * Test the SSH connection.
     */
    protected function testSsh($user, $ip, $port, $keyPath): bool
    {
        $command = "ssh -o ConnectTimeout=5 -o BatchMode=yes -o StrictHostKeyChecking=no -i {$keyPath} -p {$port} {$user}@{$ip} 'echo success' 2>&1";
        $output = shell_exec($command);

        return trim($output ?? '') === 'success';
    }

    /**
     * Install K3s on the remote server.
     */
    protected function installK3s($user, $ip, $port, $keyPath): void
    {
        $this->laraKubeInfo('Hardening OS and Installing K3s on remote server...');

        // 1. Create Swap (Crucial for 512MB droplets)
        // 2. Enable IP Forwarding
        // 3. Install K3s (optimized for single-node)
        $remoteCommand = <<<'BASH'
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
    curl -sfL https://get.k3s.io | sh -s - --disable=traefik --write-kubeconfig-mode 644
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

        // Ensure we are using the correct context
        exec("kubectl config use-context {$contextName}");

        $namespace = 'traefik';
        shell_exec("kubectl create namespace {$namespace} --dry-run=client -o yaml | kubectl apply -f -");

        // 1. Create ConfigMap for Traefik dynamic configuration
        $tmpCertsYml = sys_get_temp_dir().'/traefik-certs.yml';
        file_put_contents($tmpCertsYml, view('traefik.dev-certs')->render());
        shell_exec("kubectl create configmap traefik-config -n {$namespace} --from-file=traefik-certs.yml={$tmpCertsYml} --dry-run=client -o yaml | kubectl apply -f -");

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
            shell_exec("kubectl create secret generic traefik-certificates -n {$namespace} --from-file=local-dev.pem={$tmpDevPem} --from-file=local-dev-key.pem={$tmpDevKeyPem} --dry-run=client -o yaml | kubectl apply -f -");
        } else {
            $this->laraKubeWarn('Could not find local dev certificates. Skipping SSL secret creation.');
        }

        // 3. Apply Traefik Cloud manifest
        $tmpInstall = sys_get_temp_dir().'/traefik-cloud.yaml';
        file_put_contents($tmpInstall, view('k8s.traefik-cloud', ['email' => $this->getEmail()])->render());
        shell_exec("kubectl apply -f {$tmpInstall} --request-timeout=60s --validate=false");

        $this->laraKubeInfo('Traefik deployed and configured with HostPort and ACME (Let\'sEncrypt).');

        @unlink($tmpCertsYml);
        @unlink($tmpDevPem);
        @unlink($tmpDevKeyPem);
        @unlink($tmpInstall);
    }

    /**
     * Run a command on the remote server via SSH.
     */
    protected function runRemoteCommand($user, $ip, $port, $keyPath, $remoteCommand): void
    {
        $sudo = $user !== 'root' ? 'sudo ' : '';
        $fullCommand = $sudo.'bash -c '.escapeshellarg($remoteCommand);
        $sshCommand = "ssh -i {$keyPath} -p {$port} {$user}@{$ip} ".escapeshellarg($fullCommand);
        passthru($sshCommand);
    }
}
