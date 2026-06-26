<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Local manifest builds use `kubectl kustomize` / `kubectl apply -k`, i.e. kubectl's
 * EMBEDDED kustomize. On k3s/WSL that embedded version is often too old to parse the
 * modern `patches:` field, so builds fail; macOS/OrbStack ship a current kustomize and
 * are fine. This trait installs a standalone kustomize ONLY when the embedded one is
 * too old (isolated under ~/.larakube, never touching system binaries) and routes the
 * build/apply through it when present — so users who already have a good kustomize are
 * never disturbed.
 */
trait InteractsWithKustomize
{
    /** The CLI's own standalone kustomize — isolated, never shadows a system binary. */
    protected function managedKustomizePath(): string
    {
        return home_path('.larakube/bin/kustomize');
    }

    /** Records that the host's embedded `kubectl kustomize` already handles modern syntax. */
    protected function kustomizeOkMarker(): string
    {
        return home_path('.larakube/.kustomize-embedded-ok');
    }

    /**
     * The kustomize binary to BUILD with, or null to fall back to `kubectl kustomize`.
     * Non-null only once ensureKustomizeReady() has installed our standalone because the
     * embedded one was too old.
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
     * Ensure a kustomize that can parse the `patches:` field is available for local
     * builds, installing a standalone binary only when the host's embedded one is too
     * old. No-op on macOS (always current), when our standalone is already installed,
     * or once the embedded one has been confirmed good — so it never disturbs users who
     * don't need it, and adds no per-run cost after the first check.
     */
    protected function ensureKustomizeReady(bool $verbose = true): void
    {
        // macOS/OrbStack always ship a current kustomize — never touch them.
        if (PHP_OS_FAMILY === 'Darwin') {
            return;
        }

        // Already installed our standalone, or already confirmed the embedded one is fine.
        if ($this->kustomizeBin() !== null || is_file($this->kustomizeOkMarker())) {
            return;
        }

        // Does the host's embedded `kubectl kustomize` understand the `patches:` field?
        if ($this->kustomizeHandlesPatches('kubectl kustomize')) {
            @mkdir(dirname($this->kustomizeOkMarker()), 0755, true);
            @touch($this->kustomizeOkMarker());

            return;
        }

        // Too old (classic on k3s/WSL) — install our own standalone under ~/.larakube.
        $this->installManagedKustomize($verbose);
    }

    /**
     * Functional probe: build a tiny overlay that uses the `patches:` field. Version-
     * agnostic — it tests the exact feature that breaks rather than parsing versions.
     * $buildPrefix is "kubectl kustomize" or "<bin> build".
     */
    protected function kustomizeHandlesPatches(string $buildPrefix): bool
    {
        $dir = sys_get_temp_dir().'/lk-kustomize-probe-'.uniqid();
        @mkdir($dir, 0755, true);

        file_put_contents($dir.'/deploy.yaml', implode("\n", [
            'apiVersion: apps/v1',
            'kind: Deployment',
            'metadata:',
            '  name: probe',
            'spec:',
            '  replicas: 1',
            '  selector:',
            '    matchLabels:',
            '      app: probe',
            '  template:',
            '    metadata:',
            '      labels:',
            '        app: probe',
            '    spec:',
            '      containers:',
            '        - name: c',
            '          image: nginx',
        ])."\n");

        file_put_contents($dir.'/kustomization.yaml', implode("\n", [
            'resources:',
            '  - deploy.yaml',
            'patches:',
            '  - target:',
            '      kind: Deployment',
            '      name: probe',
            '    patch: |-',
            '      apiVersion: apps/v1',
            '      kind: Deployment',
            '      metadata:',
            '        name: probe',
            '      spec:',
            '        replicas: 2',
        ])."\n");

        exec($buildPrefix.' '.escapeshellarg($dir).' 2>/dev/null', $out, $code);

        @unlink($dir.'/deploy.yaml');
        @unlink($dir.'/kustomization.yaml');
        @rmdir($dir);

        return $code === 0 && $out !== [];
    }

    /** Download a standalone kustomize into ~/.larakube/bin (no sudo, no system changes). */
    protected function installManagedKustomize(bool $verbose): void
    {
        $version = (new ConfigData)->kustomizeVersion ?? 'v5.6.0';
        $machine = php_uname('m');
        $arch = in_array($machine, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os = 'linux';   // Darwin already returned above; WSL reports linux.

        $binDir = dirname($this->managedKustomizePath());
        @mkdir($binDir, 0755, true);

        if ($verbose) {
            $this->laraKubeInfo("Your kubectl's embedded kustomize is too old to parse modern manifests (the `patches:` field) — installing a standalone kustomize ({$version})...");
        }

        $url = "https://github.com/kubernetes-sigs/kustomize/releases/download/kustomize%2F{$version}/kustomize_{$version}_{$os}_{$arch}.tar.gz";
        exec('curl -sL '.escapeshellarg($url).' | tar -xz -C '.escapeshellarg($binDir).' kustomize 2>/dev/null');
        @chmod($this->managedKustomizePath(), 0755);

        $bin = $this->kustomizeBin();
        if ($bin !== null && $this->kustomizeHandlesPatches(escapeshellarg($bin).' build')) {
            if ($verbose) {
                $this->laraKubeInfo('✅ Installed standalone kustomize at '.$bin);
                $this->laraKubeLine('  <fg=gray>larakube uses it for local manifest builds; your system tools are untouched.</>');
            }

            return;
        }

        // Couldn't install or verify — remove any partial binary so we fall back cleanly.
        @unlink($this->managedKustomizePath());
        if ($verbose) {
            $this->laraKubeWarn('Could not install a standalone kustomize automatically.');
            $this->laraKubeLine('  👉 Install kustomize v5+ manually so `larakube up` can build manifests that use the `patches:` field.');
        }
    }
}
