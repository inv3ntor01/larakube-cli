<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Local manifest builds default to `kubectl kustomize` / `kubectl apply -k` — kubectl's
 * EMBEDDED kustomize, whose version varies wildly across machines (a recent macOS/OrbStack
 * kubectl ships v5; k3s/WSL often ship one too old to parse the multi-document `patches:`
 * files we emit). On a mixed team that means manifests build on one laptop and fail — or
 * render differently — on another. To make builds identical everywhere, the CLI pins its
 * OWN standalone kustomize under ~/.larakube/bin (isolated, no sudo, never shadows a system
 * binary) and builds with it on every platform.
 */
trait InteractsWithKustomize
{
    /** The CLI's own pinned standalone kustomize — isolated, never shadows a system binary. */
    protected function managedKustomizePath(): string
    {
        return home_path('.larakube/bin/kustomize');
    }

    /** The single kustomize version every machine standardises on. */
    protected function pinnedKustomizeVersion(): string
    {
        return (new ConfigData)->kustomizeVersion ?? 'v5.6.0';
    }

    /**
     * The kustomize binary to BUILD with, or null to fall back to `kubectl kustomize`
     * (only when our standalone couldn't be installed — e.g. offline). After
     * ensureKustomizeReady() this is normally the pinned standalone on every platform.
     */
    protected function kustomizeBin(): ?string
    {
        $bin = $this->managedKustomizePath();

        return (is_file($bin) && is_executable($bin)) ? $bin : null;
    }

    /** Build an overlay dir to stdout: standalone `kustomize build` or `kubectl kustomize`. */
    protected function kustomizeBuildCommand(string $path): string
    {
        $bin = $this->kustomizeBin();

        return $bin !== null
            ? escapeshellarg($bin).' build '.escapeshellarg($path)
            : 'kubectl kustomize '.escapeshellarg($path);
    }

    /** Apply an overlay dir, using the same kustomize the build path resolves to. */
    protected function kustomizeApplyCommand(string $path): string
    {
        $bin = $this->kustomizeBin();

        return $bin !== null
            ? escapeshellarg($bin).' build '.escapeshellarg($path).' | kubectl apply -f -'
            : 'kubectl apply -k '.escapeshellarg($path);
    }

    /**
     * Ensure the pinned standalone kustomize is installed for this host, so every machine
     * (macOS, Linux, WSL) builds manifests with the SAME kustomize version — no more
     * "works on my Mac, breaks on their WSL". Re-installs when the pinned version changed
     * (e.g. after a CLI upgrade). Cheap after the first run (a single `kustomize version`
     * check). Best-effort: if the download fails (offline), builds fall back to
     * `kubectl kustomize`.
     */
    protected function ensureKustomizeReady(bool $verbose = true): void
    {
        $version = $this->pinnedKustomizeVersion();
        $bin = $this->managedKustomizePath();

        if (is_file($bin) && is_executable($bin)) {
            $out = (string) shell_exec(escapeshellarg($bin).' version 2>/dev/null');
            if (str_contains($out, $version)) {
                return;   // already on the pinned version
            }
        }

        $this->installManagedKustomize($version, $verbose);
    }

    /** Download the pinned kustomize into ~/.larakube/bin (no sudo, no system changes). */
    protected function installManagedKustomize(string $version, bool $verbose): void
    {
        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux';   // WSL reports linux

        $bin = $this->managedKustomizePath();
        $binDir = dirname($bin);
        @mkdir($binDir, 0755, true);

        if ($verbose) {
            $this->laraKubeInfo("Installing kustomize {$version} (pinned, so every machine builds manifests identically)...");
        }

        $url = "https://github.com/kubernetes-sigs/kustomize/releases/download/kustomize%2F{$version}/kustomize_{$version}_{$os}_{$arch}.tar.gz";
        exec('curl -sL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' kustomize 2>/dev/null');
        @chmod($bin, 0755);

        // Confirm it runs the pinned version; otherwise drop it so the build cleanly
        // falls back to `kubectl kustomize` rather than using a broken/partial binary.
        $out = (is_file($bin) && is_executable($bin))
            ? (string) shell_exec(escapeshellarg($bin).' version 2>/dev/null')
            : '';

        if (str_contains($out, $version)) {
            if ($verbose) {
                $this->laraKubeInfo('✅ kustomize ready at '.$bin);
            }

            return;
        }

        @unlink($bin);
        if ($verbose) {
            $this->laraKubeWarn("Could not install kustomize {$version} automatically (offline?).");
            $this->laraKubeLine('  👉 Builds will use your kubectl\'s kustomize for now; install kustomize v5+ for consistent results.');
        }
    }
}
