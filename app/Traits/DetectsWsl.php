<?php

namespace App\Traits;

trait DetectsWsl
{
    /**
     * Whether we're running inside WSL — where the Windows side (hosts file and
     * certificate trust store) must be targeted too, not just the Linux ones.
     *
     * Matches WSL2 (lowercase "microsoft" in /proc/version, e.g.
     * "…-microsoft-standard-WSL2") as well as WSL1 ("Microsoft"). The previous
     * case-sensitive `str_contains(..., 'Microsoft')` checks missed WSL2
     * entirely — so `larakube trust` installed the CA into the Linux store
     * instead of the Windows Root store, and the Windows browser never trusted
     * the local HTTPS cert.
     */
    protected function isWsl(): bool
    {
        if (getenv('WSL_DISTRO_NAME')) {
            return true;
        }

        return is_file('/proc/version')
            && str_contains(strtolower((string) @file_get_contents('/proc/version')), 'microsoft');
    }

    /**
     * Whether Kubernetes is provided by Docker Desktop injected into WSL2.
     *
     * When Docker Desktop's "Enable Kubernetes" is on and its WSL2 integration
     * is active, the kube context is set to `docker-desktop` and the Docker
     * daemon is shared with the host — no separate cluster runtime is needed.
     */
    protected function isDockerDesktopKubernetesOnWsl(): bool
    {
        if (! $this->isWsl()) {
            return false;
        }

        $context = trim(shell_exec('kubectl config current-context 2>/dev/null') ?? '');

        return $context === 'docker-desktop';
    }

    /**
     * Whether the Docker CLI is available on this machine.
     */
    protected function hasDockerCli(): bool
    {
        return trim((string) shell_exec('command -v docker 2>/dev/null')) !== '';
    }

    /**
     * Whether the running Docker daemon is Docker Desktop (not Colima, OrbStack,
     * native Docker Engine, etc.). Returns false if the daemon is unreachable.
     */
    protected function isDockerDesktop(): bool
    {
        $info = shell_exec('docker info --format "{{.OperatingSystem}}" 2>/dev/null');

        return str_contains(strtolower($info ?? ''), 'docker desktop');
    }

    /**
     * On WSL2, whether Docker Desktop's daemon is reachable from inside the
     * distro (via WSL2 integration). This tells us the Docker engine is present
     * even if Kubernetes isn't enabled in Docker Desktop.
     */
    protected function hasDockerDesktopOnWsl(): bool
    {
        if (! $this->isWsl()) {
            return false;
        }

        return $this->hasDockerCli() && $this->isDockerDesktop();
    }
}
