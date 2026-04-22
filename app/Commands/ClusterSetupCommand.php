<?php

namespace App\Commands;

use App\Traits\InteractsWithInternalDatabase;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ClusterSetupCommand extends Command
{
    use InteractsWithInternalDatabase, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:setup {--volume= : A specific host directory to mount into the cluster (e.g. ~/Codes)}';

    /**
     * The console command description.
     */
    protected $description = 'Install and configure a local Kubernetes cluster (k3d)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Installer');

        // 1. Check for Docker
        if (shell_exec('docker ps 2>&1') === null) {
            $this->laraKubeError('Docker is not running. Please start Docker/OrbStack and try again.');

            return 1;
        }

        // 2. Select Engine
        $engine = select(
            label: 'Which Kubernetes engine would you like to use?',
            options: [
                'k3d' => 'k3d (k3s in Docker - Recommended)',
                'k3s' => 'k3s (Native Linux Service)',
            ],
            default: 'k3d'
        );

        if ($engine === 'k3d') {
            if (! $this->checkPortsAvailable([80, 443])) {
                return 1;
            }

            return $this->installK3d();
        }

        return $this->installK3s();
    }

    /**
     * Check if the required local ports are free.
     */
    protected function checkPortsAvailable(array $ports): bool
    {
        $occupied = [];
        foreach ($ports as $port) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($connection)) {
                $occupied[] = $port;
                fclose($connection);
            }
        }

        if (! empty($occupied)) {
            $this->laraKubeError('Port Conflict Detected!');
            $this->line('  The following ports are already in use: '.implode(', ', $occupied));
            $this->line('');
            $this->warn('  💡 This is likely caused by OrbStack\'s built-in Kubernetes or another local server.');
            $this->info('  👉 Solution: Disable Kubernetes in your OrbStack/Docker settings and try again.');
            $this->line('');

            return false;
        }

        return true;
    }

    protected function installK3d(): int
    {
        // Check if k3d is installed
        if (shell_exec('which k3d') === null) {
            $this->laraKubeInfo('k3d not found. Installing via official script...');
            passthru('curl -s https://raw.githubusercontent.com/k3d-io/k3d/main/install.sh | bash');
        }

        $clusterName = 'larakube';

        // Check if cluster already exists
        $clusters = shell_exec('k3d cluster list --no-headers 2>/dev/null');
        if (str_contains($clusters ?? '', $clusterName)) {
            $this->laraKubeInfo("Cluster '{$clusterName}' already exists.");
            if (confirm('Would you like to recreate it?', false)) {
                $this->withSpin('Deleting existing cluster...', fn () => exec("k3d cluster delete {$clusterName}"));
            } else {
                return 0;
            }
        }

        // --- 🛡 SECURE WORKSPACE BRIDGE ---
        $defaultPath = dirname(getcwd());
        $workspace = $this->option('volume') ?? text(
            label: 'Which host directory contains your Laravel projects?',
            placeholder: 'e.g. '.$defaultPath,
            default: $defaultPath,
            hint: 'This directory will be mounted into the Kubernetes nodes so your code can be seen by the pods.'
        );

        // Resolve to absolute path to ensure k3d is happy
        $workspace = realpath(str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $workspace));

        if (! $workspace || ! is_dir($workspace)) {
            $this->laraKubeError("Invalid workspace directory: {$workspace}");

            return 1;
        }

        $this->laraKubeInfo('Creating LaraKube local cluster...');
        $this->info("  🛡 Scoped workspace bridge: {$workspace}");

        // Create k3d cluster with standard ports exposed
        // And mount the workspace so hostPath mounts work correctly
        $command = "k3d cluster create {$clusterName} ".
                   '-p "80:80@loadbalancer" '.
                   '-p "443:443@loadbalancer" '.
                   "-v \"{$workspace}:{$workspace}@all\" ".
                   '--agents 1 '.
                   '--k3s-arg "--disable=traefik@server:*" '.
                   '--wait';

        passthru($command);

        $this->logActivity('Local cluster setup completed', ['engine' => 'k3d', 'cluster_name' => $clusterName, 'workspace' => $workspace]);

        $this->laraKubeInfo('✅ Local cluster is ready!');
        $this->info('You can now use larakube up to deploy your projects.');

        return 0;
    }

    protected function installK3s(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->laraKubeError('Native k3s installation is only supported on Linux. Please use k3d instead.');

            return 1;
        }

        $this->laraKubeInfo('Installing native k3s...');
        passthru('curl -sfL https://get.k3s.io | sh -');

        $this->laraKubeInfo('Waiting for node to be ready...');
        passthru('sudo k3s kubectl wait --for=condition=ready node --all --timeout=60s');

        $this->logActivity('Local cluster setup completed', ['engine' => 'k3s_native']);

        return 0;
    }
}
