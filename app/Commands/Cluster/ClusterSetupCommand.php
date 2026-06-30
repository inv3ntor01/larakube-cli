<?php

namespace App\Commands\Cluster;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK3s;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use App\Traits\PrunesKubeContext;
use LaravelZero\Framework\Commands\Command;

class ClusterSetupCommand extends Command
{
    use DetectsWsl, InstallsK3s, InteractsWithKustomize, InteractsWithOs, LaraKubeOutput, PrunesKubeContext;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cluster:setup {--volume= : A specific host directory to mount into the cluster (e.g. ~/Codes)}';

    /**
     * The console command description.
     */
    protected $description = 'Install and configure a local Kubernetes cluster (native k3s on Linux/WSL2)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Local Cluster Installer');

        // 1. k3s needs a Linux kernel — WSL2 qualifies. macOS/Windows users should
        //    use Docker Desktop's built-in Kubernetes, OrbStack, or a cloud target.
        if (! $this->isLinux()) {
            $this->laraKubeError('Native k3s needs a Linux kernel.');
            $this->newLine();
            $this->line('  <fg=gray>Your options:</>');
            $this->line('  1. Use Docker Desktop\'s built-in Kubernetes (Settings → Kubernetes → Enable).');
            $this->line('  2. Use OrbStack on macOS — it has built-in k3s-based Kubernetes.');
            $this->line('  3. Deploy to a cloud target via `larakube cloud:init`.');

            return 1;
        }

        // 2. WSL2 is Linux under the hood — k3s works natively there too.
        $where = $this->isWsl() ? 'WSL2' : 'Linux';
        $this->laraKubeInfo("Installing native k3s on {$where}...");

        if ($this->isWsl()) {
            $this->unmountDockerDesktopHost();
        }

        $result = $this->installK3s();

        // 3. Make sure a kustomize that can build our multi-doc patches is available:
        //    probes the machine's own kustomize and installs a pinned standalone only when
        //    it can't build them (k3s/WSL, or an older v5 like v5.0.4). A capable kubectl
        //    (recent macOS/OrbStack) uses its own — no download. Runs on the "already
        //    exists" path too.
        if ($result === 0) {
            $this->ensureKustomizeReady();
            $this->warnIfNewerK3sAvailable();
        }

        return $result;
    }

    protected function installK3s(): int
    {
        $this->laraKubeInfo('Installing native k3s...');
        // --write-kubeconfig-mode=644 is a k3s server flag that makes k3s always write
        // /etc/rancher/k3s/k3s.yaml with 644 permissions — survives service restarts.
        // K3S_KUBECONFIG_MODE=644 is the installer env equivalent, applied when the
        // service is (re)started; both are set so re-runs without "No change detected"
        // also get the right mode.
        passthru($this->k3sInstallCommand($this->k3sVersion(), ['--disable=traefik', '--write-kubeconfig-mode=644'], ['K3S_KUBECONFIG_MODE' => '644'], sudo: true), $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('k3s installation failed. Please review the output above.');

            return 1;
        }

        // k3s registers its Node asynchronously after the service starts. Running
        // `kubectl wait` before the Node object exists fails immediately with
        // "no matching resources found", so poll until it appears, then wait for
        // it to become Ready.
        // WSL2 first boot is slower (kernel modules, containerd init) and routinely
        // needs 90–120s; native Linux is usually done in 20–30s.
        $this->laraKubeInfo('Waiting for node to be ready...');

        $maxAttempts = $this->isWsl() ? 90 : 40; // 180s on WSL2, 80s on Linux
        $nodeAppeared = false;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (trim((string) shell_exec('sudo k3s kubectl get nodes --no-headers 2>/dev/null')) !== '') {
                $nodeAppeared = true;
                break;
            }
            sleep(2);
        }

        if (! $nodeAppeared) {
            $this->laraKubeWarn('Timed out waiting for the k3s node to register.');
            $this->line('  k3s is still initializing — this is normal on first boot in WSL2.');
            $this->line('  Wait a moment, then re-run <fg=cyan>larakube cluster:setup</> to complete setup.');
            $this->line('  You can check live progress with: <fg=cyan>sudo journalctl -u k3s -f</>');
            // Still chmod in case the file exists — it won't be overwritten once k3s
            // fully starts (--write-kubeconfig-mode=644 handles future restarts).
            passthru('sudo chmod 644 /etc/rancher/k3s/k3s.yaml 2>/dev/null');

            return 1;
        }

        passthru('sudo k3s kubectl wait --for=condition=ready node --all --timeout=120s');

        // Belt-and-suspenders: --write-kubeconfig-mode=644 is set as a server flag so
        // k3s writes it 644 on every restart, but chmod here heals re-runs where the
        // installer skips restarting the service ("No change detected").
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
     * Docker Desktop's WSL integration mounts C:\Program Files\Docker\Docker\resources
     * at /Docker/host using 9p. The Windows path contains a space that Docker Desktop
     * does NOT escape inside the mount options, so /proc/mounts ends up with a 7-token
     * line where k3s's ContainerManager parser expects 6 — crashing on startup.
     * Unmounting before the installer runs (or before k3s restarts) sidesteps the issue.
     * Docker Desktop automatically remounts /Docker/host once it detects it gone, so
     * image builds are unaffected after k3s is up.
     */
    protected function unmountDockerDesktopHost(): void
    {
        if (! file_exists('/Docker/host')) {
            return;
        }

        $mounted = str_contains((string) shell_exec('grep -q " /Docker/host " /proc/mounts && echo yes 2>/dev/null'), 'yes');

        if (! $mounted) {
            return;
        }

        $this->laraKubeInfo('Unmounting /Docker/host (Docker Desktop WSL mount) before k3s starts...');
        shell_exec('sudo umount /Docker/host 2>/dev/null');
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
