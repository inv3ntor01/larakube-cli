<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Local manifest builds use `kubectl kustomize` / `kubectl apply -k` — kubectl's EMBEDDED
 * kustomize. That's fine on a recent kubectl (macOS/OrbStack ship v5), but k3s/WSL often
 * ship one too old to parse the multi-document `patches:` files we emit, so builds fail.
 * The team only needs everyone on kustomize v5+, not the exact same patch version — so we
 * install a pinned standalone kustomize (isolated under ~/.larakube/bin, no sudo) ONLY when
 * the machine's own kustomize is too old, and use it for builds. Up-to-date machines use
 * their own and download nothing.
 */
trait InteractsWithKustomize
{
    /** The CLI's own pinned standalone kustomize — isolated, never shadows a system binary. */
    protected function managedKustomizePath(): string
    {
        return home_path('.larakube/bin/kustomize');
    }

    /** The kustomize version we install when the machine's own is too old. */
    protected function pinnedKustomizeVersion(): string
    {
        return (new ConfigData)->kustomizeVersion ?? 'v5.6.0';
    }

    /**
     * The kustomize binary to BUILD with, or null to fall back to `kubectl kustomize`.
     * Non-null only when we installed our standalone (because the machine's own kustomize
     * was too old). Recent-kubectl machines keep this null and use their embedded one.
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
     * Ensure a kustomize that can build our manifests is available. Installs the pinned
     * standalone ONLY when the machine's own kustomize is too old (typical on k3s/WSL);
     * recent-kubectl machines (macOS/OrbStack, up-to-date Linux) use their own and download
     * nothing. Cheap: a single `kubectl version` check, or an instant return once our
     * standalone is present. Best-effort — if the download fails, builds fall back to
     * `kubectl kustomize`.
     */
    protected function ensureKustomizeReady(bool $verbose = true): void
    {
        // We already installed our standalone on a prior run — use it.
        if ($this->kustomizeBin() !== null) {
            return;
        }

        // The machine's own kubectl ships a new-enough kustomize (v5+)? Use it, no download.
        if ($this->embeddedKustomizeIsRecent()) {
            return;
        }

        // Too old (k3s/WSL) to parse our multi-doc patches — install the pinned standalone.
        $this->installManagedKustomize($this->pinnedKustomizeVersion(), $verbose);
    }

    /** Is kubectl's embedded kustomize new enough (v5+) to build our manifests? */
    protected function embeddedKustomizeIsRecent(): bool
    {
        $json = (string) shell_exec('kubectl version --client -o json 2>/dev/null');
        if ($json === '') {
            return false;   // no kubectl, or too old to report — treat as old, install ours
        }

        $data = json_decode($json, true);
        $version = is_array($data) ? (string) ($data['kustomizeVersion'] ?? '') : '';

        return $this->kustomizeVersionIsRecent($version);
    }

    /** Pure: does a kustomize version string report major v5 or newer? */
    protected function kustomizeVersionIsRecent(string $version): bool
    {
        return preg_match('/v(\d+)\./', $version, $m) === 1 && (int) $m[1] >= 5;
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
            $this->laraKubeInfo("Your kubectl's kustomize is too old to build these manifests (need v5+) — installing a standalone kustomize {$version}...");
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
