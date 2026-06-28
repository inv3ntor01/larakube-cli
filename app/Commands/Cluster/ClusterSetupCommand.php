<?php

namespace App\Commands\Cluster;

use App\Traits\InstallsK3s;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use App\Traits\PrunesKubeContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class ClusterSetupCommand extends Command
{
    use InstallsK3s, InteractsWithKustomize, InteractsWithOs, LaraKubeOutput, PrunesKubeContext;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:setup {--volume= : A specific host directory to mount into the cluster (e.g. ~/Codes)}';

    /**
     * The console command description.
     */
    protected $description = 'Install and configure a local Kubernetes cluster (native k3s on Linux, k3d on macOS/Windows)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Installer');

        // 1. Select engine. Native k3s is the lightest option and the default on
        //    Linux; k3d (k3s-in-Docker) pays a container/VM tax on top of k3s and
        //    is the only choice on macOS/Windows (no native Linux kernel there).
        if ($this->isLinux()) {
            $engine = select(
                label: 'Which Kubernetes engine would you like to use?',
                options: [
                    'k3s' => 'k3s (native Linux service — lightest, recommended)',
                    'k3d' => 'k3d (k3s in Docker — heavier; for throwaway clusters)',
                ],
                default: 'k3s',
            );
        } else {
            $this->laraKubeLine('  <fg=gray>Native k3s needs a Linux kernel — using k3d (k3s in Docker).</>');
            $this->laraKubeLine('  <fg=gray>Tip: OrbStack\'s built-in Kubernetes is k3s-based and lighter than k3d;</>');
            $this->laraKubeLine('  <fg=gray>if you run it, `larakube context` can target it instead of creating a k3d cluster.</>');
            $engine = 'k3d';
        }

        // 2. k3d needs Docker running + the ingress ports free; native k3s needs neither.
        if ($engine === 'k3d') {
            if (shell_exec('docker ps 2>&1') === null) {
                $this->laraKubeError('Docker is not running. Please start Docker/OrbStack and try again.');

                return 1;
            }

            if (! $this->checkPortsAvailable([80, 443])) {
                return 1;
            }

            $result = $this->installK3d();
        } else {
            $result = $this->installK3s();
        }

        // 3. Make sure a kustomize that can build our multi-doc patches is available:
        //    probes the machine's own kustomize and installs a pinned standalone only when
        //    it can't build them (k3s/WSL, or an older v5 like v5.0.4). A capable kubectl
        //    (recent macOS/OrbStack) uses its own — no download. Runs on the "already
        //    exists" path too.
        if ($result === 0) {
            $this->ensureKustomizeReady();
        }

        return $result;
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

        // --- 🛡️ UNIVERSAL WORKSPACE BRIDGE ---
        // Instead of asking for a specific folder, we mount the entire user root
        // so that projects can be located anywhere (Desktop, Codes, etc.)
        $userRoot = $this->isDarwin() ? '/Users' : '/home';

        // Clear any stale k3d-larakube kubeconfig entry from a prior cluster (e.g.
        // destroyed by an older CLI without this cleanup) so the fresh create + merge
        // yields a clean, connectable context. Safe here — we're committed to creating
        // a new cluster, so no live context is being removed.
        $this->pruneKubeContext(['k3d-larakube']);

        $this->laraKubeInfo('Creating LaraKube local cluster...');
        $this->info("  🛡 Universal workspace bridge: {$userRoot}");

        $k3dImage = 'rancher/k3s:'.str_replace('+', '-', $this->k3sVersion());

        // Create k3d cluster with standard ports exposed
        // And mount the user root so hostPath mounts work correctly
        $command = "k3d cluster create {$clusterName} ".
                   '-p "80:80@loadbalancer" '.
                   '-p "443:443@loadbalancer" '.
                   "-v \"{$userRoot}:{$userRoot}@all\" ".
                   "--image {$k3dImage} ".
                   '--agents 1 '.
                   '--k3s-arg "--disable=traefik@server:*" '.
                   '--wait';

        passthru($command);

        $this->laraKubeInfo('✅ Local cluster is ready!');
        $this->info('You can now use larakube up to deploy your projects.');

        return 0;
    }

    protected function installK3s(): int
    {
        if (! $this->isLinux()) {
            $this->laraKubeError('Native k3s installation is only supported on Linux. Please use k3d instead.');

            return 1;
        }

        $this->laraKubeInfo('Installing native k3s...');
        // K3S_KUBECONFIG_MODE=644 makes /etc/rancher/k3s/k3s.yaml readable by your
        // user (k3s defaults to 0600/root-only), so a plain `kubectl` and the merge
        // below work without sudo.
        passthru($this->k3sInstallCommand($this->k3sVersion(), ['--disable=traefik'], ['K3S_KUBECONFIG_MODE' => '644'], sudo: true), $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('k3s installation failed. Please review the output above.');

            return 1;
        }

        // k3s registers its Node asynchronously after the service starts. Running
        // `kubectl wait` before the Node object exists fails immediately with
        // "no matching resources found", so poll until it appears, then wait for
        // it to become Ready.
        $this->laraKubeInfo('Waiting for node to be ready...');

        $nodeAppeared = false;
        for ($i = 0; $i < 30; $i++) {
            if (trim((string) shell_exec('sudo k3s kubectl get nodes --no-headers 2>/dev/null')) !== '') {
                $nodeAppeared = true;
                break;
            }
            sleep(2);
        }

        if ($nodeAppeared) {
            passthru('sudo k3s kubectl wait --for=condition=ready node --all --timeout=120s');
        } else {
            $this->laraKubeWarn('Timed out waiting for the k3s node to register. It may still come up shortly.');
        }

        // On an ALREADY-installed k3s the installer prints "No change detected so
        // skipping service start" — so K3S_KUBECONFIG_MODE is never applied (the
        // mode is only written when k3s (re)starts). Force the kubeconfig readable
        // so a re-run actually heals the 0600 permission instead of looping.
        passthru('sudo chmod 644 /etc/rancher/k3s/k3s.yaml 2>/dev/null');

        // k3s writes its kubeconfig to /etc/rancher/k3s/k3s.yaml (root-owned) and
        // never touches ~/.kube/config — so kubectl and `larakube context` can't
        // see it until we merge it in. Prune any stale k3s-larakube entry first so
        // the fresh merge starts clean (no dangling current-context from a prior run).
        $this->pruneKubeContext(['k3s-larakube']);
        $this->mergeK3sKubeconfig();

        $this->laraKubeInfo('✅ Native k3s cluster is ready!');
        $this->info('You can now use larakube up to deploy your projects.');

        return 0;
    }

    /**
     * Merge the k3s kubeconfig into the user's ~/.kube/config so kubectl and
     * `larakube context` can see and select it. k3s names every entry "default";
     * we rename the context/cluster/user to "k3s-larakube" so it won't collide
     * with other configs and is easy to recognize.
     */
    protected function mergeK3sKubeconfig(): void
    {
        $source = '/etc/rancher/k3s/k3s.yaml';

        $raw = shell_exec('sudo cat '.escapeshellarg($source).' 2>/dev/null');

        if (empty($raw)) {
            $this->laraKubeWarn("Could not read the k3s kubeconfig at {$source}.");
            $this->line('  👉 Merge it into ~/.kube/config manually to use it with kubectl.');

            return;
        }

        // Rename the "default" context/cluster/user. Each replacement is anchored
        // to its YAML key and line end, so base64 cert data is never touched.
        $contextName = 'k3s-larakube';
        $raw = preg_replace('/^(\s*(?:- )?name: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(\s*cluster: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(\s*user: )default$/m', '${1}'.$contextName, $raw);
        $raw = preg_replace('/^(current-context: )default$/m', '${1}'.$contextName, $raw);

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (! $home) {
            $this->laraKubeWarn('Could not determine your home directory; skipping kubeconfig merge.');

            return;
        }

        $kubeDir = $home.'/.kube';
        $kubeConfig = $kubeDir.'/config';

        if (! is_dir($kubeDir)) {
            @mkdir($kubeDir, 0755, true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_k3s');
        file_put_contents($tmp, $raw);

        // List the existing config first so its other contexts survive the merge;
        // --flatten inlines the cert data into a single self-contained file.
        $kubeconfigEnv = file_exists($kubeConfig) ? $kubeConfig.':'.$tmp : $tmp;
        $merged = shell_exec('KUBECONFIG='.escapeshellarg($kubeconfigEnv).' kubectl config view --flatten 2>/dev/null');

        @unlink($tmp);

        if (empty($merged)) {
            $this->laraKubeWarn('Failed to merge the k3s kubeconfig automatically.');

            return;
        }

        file_put_contents($kubeConfig, $merged);
        @chmod($kubeConfig, 0600);

        // Target ~/.kube/config explicitly — a bare `kubectl` would use $KUBECONFIG
        // (often k3s's own file), where this context doesn't exist.
        exec('KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config use-context '.escapeshellarg($contextName).' 2>/dev/null');

        // Verify the context actually landed — the flatten/merge can silently no-op.
        $contexts = array_filter(explode("\n", trim((string) shell_exec(
            'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config get-contexts -o name 2>/dev/null',
        ))));
        if (! in_array($contextName, $contexts, true)) {
            $this->laraKubeWarn("Merge did not produce the '{$contextName}' context in ~/.kube/config.");
            $this->laraKubeLine('  👉 Ensure /etc/rancher/k3s/k3s.yaml is readable, then re-run `larakube cluster:setup`.');

            return;
        }

        $this->laraKubeInfo("Merged k3s into ~/.kube/config as context <fg=cyan>{$contextName}</>.");

        // A KUBECONFIG env var pointing elsewhere (e.g. at k3s's own
        // /etc/rancher/k3s/k3s.yaml, which the k3s installer suggests exporting)
        // SHADOWS this merge — kubectl/larakube would read that file (context
        // "default") and never see "k3s-larakube".
        $envKubeconfig = (string) getenv('KUBECONFIG');
        if ($envKubeconfig !== '' && realpath($envKubeconfig) !== realpath($kubeConfig)) {
            $this->laraKubeWarn("Heads up: your KUBECONFIG points at {$envKubeconfig}, which hides this merge.");
            $this->laraKubeLine('  👉 Run `unset KUBECONFIG` (and remove any KUBECONFIG=… line from ~/.bashrc) so kubectl uses ~/.kube/config.');
        }
    }
}
