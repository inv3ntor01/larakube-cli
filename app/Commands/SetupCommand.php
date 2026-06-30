<?php

namespace App\Commands;

use App\Traits\DetectsWsl;
use App\Traits\InstallsK9s;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use DetectsWsl, InstallsK9s, InteractsWithOs, LaraKubeOutput;

    protected $signature = 'setup';

    protected $description = 'First-time setup: install Docker Engine, k3s cluster, and optional tools';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Environment Setup');

        if (! $this->isLinux()) {
            $this->laraKubeError('larakube setup only runs on Linux and WSL2.');
            $this->newLine();
            $this->line('  <fg=gray>On macOS, use OrbStack or Docker Desktop\'s built-in Kubernetes.</>');
            $this->line('  <fg=gray>On Windows, open your WSL2 terminal and run this command there.</>');

            return 1;
        }

        // Step 1 — Docker Engine
        if (! $this->ensureDockerInstalled()) {
            return 1;
        }

        $this->newLine();

        // Step 2 — k3s cluster (delegates entirely to cluster:setup)
        $result = $this->call('cluster:setup');

        if ($result !== 0) {
            return $result;
        }

        $this->newLine();

        // Step 3 — k9s (optional terminal UI for browsing the cluster)
        $this->offerK9s();

        return 0;
    }

    protected function ensureDockerInstalled(): bool
    {
        $hasDocker = trim((string) shell_exec('command -v docker 2>/dev/null')) !== '';

        if ($hasDocker) {
            exec('docker info 2>/dev/null', $_, $code);

            if ($code === 0) {
                $os = trim((string) shell_exec('docker info --format \'{{.OperatingSystem}}\' 2>/dev/null'));

                if (str_contains($os, 'Docker Desktop')) {
                    $this->laraKubeWarn('Docker Desktop detected.');
                    $this->line('  LaraKube works with it, but Docker Engine installed directly in WSL2 is more reliable.');
                    $this->line('  See: <fg=cyan>https://cli.larakube.app/onboarding/operating-systems/windows</>');
                } else {
                    $this->laraKubeInfo('Docker Engine already installed and running.');
                }

                return true;
            }

            $this->laraKubeInfo('Docker found — starting the engine...');
            passthru('sudo systemctl start docker 2>/dev/null', $startCode);

            if ($startCode !== 0) {
                $this->laraKubeError('Could not start Docker. Run: sudo systemctl start docker');

                return false;
            }

            $this->laraKubeInfo('✅ Docker Engine running.');

            return true;
        }

        $this->laraKubeInfo('Installing Docker Engine...');
        passthru('curl -fsSL https://get.docker.com | sh', $installCode);

        if ($installCode !== 0) {
            $this->laraKubeError('Docker Engine installation failed. See output above.');

            return false;
        }

        // Add the current user to the docker group so docker works without sudo.
        $user = getenv('USER') ?: get_current_user();
        if ($user) {
            shell_exec('sudo usermod -aG docker '.escapeshellarg((string) $user).' 2>/dev/null');
        }

        shell_exec('sudo systemctl enable --now docker 2>/dev/null');

        $this->laraKubeInfo('✅ Docker Engine installed.');
        $this->laraKubeWarn("You've been added to the <fg=cyan>docker</> group.");
        $this->line('  Run <fg=cyan>newgrp docker</> or open a new terminal before using docker commands.');

        return true;
    }

    protected function offerK9s(): void
    {
        if ($this->resolveK9sBin() !== null) {
            $this->laraKubeInfo('k9s already installed.');

            return;
        }

        $this->line('  <fg=yellow>💡 Optional:</> <fg=cyan>k9s</> is a terminal UI for browsing your cluster.');

        if (! confirm('Install k9s now?', default: true)) {
            $this->line('  <fg=gray>Skipped — install it later with: larakube k9s</>');

            return;
        }

        $this->installK9s();
    }
}
