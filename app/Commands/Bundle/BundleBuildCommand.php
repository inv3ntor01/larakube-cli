<?php

namespace App\Commands\Bundle;

use App\Traits\AssemblesBundle;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithRemoteDeploy;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Build-side: assemble a self-contained air-gapped install kit for an on-prem
 * customer. Derives the image set from the blueprint (so it never drifts), builds
 * the app image for the target arch, saves every image to a tarball, copies the
 * manifests, and writes bundle.json. The customer later runs `bundle:install`
 * offline. (k3s artifacts + the bootstrap are a follow-up step.)
 */
class BundleBuildCommand extends Command
{
    use AssemblesBundle, InteractsWithProjectConfig, InteractsWithRemoteDeploy, LaraKubeOutput;

    protected $signature = 'bundle:build
                            {environment=production : The environment to bundle}
                            {--arch= : Target CPU architecture (amd64|arm64) — match the customer server, not your Mac}
                            {--update : Build a lightweight update bundle (skips K3s and dependency images)}
                            {--tar : Compress the bundle into a .tar.gz file after assembly}
                            {--dry-run : Show the plan (images, layout) without building or saving anything}';

    protected $description = 'Assemble a self-contained air-gapped install kit (images + manifests) for an on-prem customer';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube project.');

            return 1;
        }

        // Auto-detect offline environments when the user relies on the default.
        $explicitEnv = $this->hasArgument('environment') && $this->argument('environment') !== 'production';
        $env = (string) $this->argument('environment');

        if (! $explicitEnv) {
            $offlineEnvs = collect($config->environments)
                ->filter(fn ($e) => $e->offline ?? false)
                ->keys()
                ->all();

            if (count($offlineEnvs) === 1) {
                $env = $offlineEnvs[0];
                $this->laraKubeInfo("Auto-selected offline environment: {$env}");
            } elseif (count($offlineEnvs) > 1) {
                $env = \Laravel\Prompts\select(
                    label: 'Multiple offline environments found. Which one?',
                    options: $offlineEnvs,
                );
            }
        }

        if ($config->getEnvironment($env) === null) {
            $this->laraKubeError("Unknown environment '{$env}'. Pick one of: ".implode(', ', $config->getCloudEnvironments()));

            return 1;
        }

        $envData = $config->getEnvironment($env);
        if (! ($envData->offline ?? false)) {
            $this->laraKubeWarn("Environment '{$env}' is not marked as offline. Consider: larakube env {$env} --offline");
        }

        $archOption = $this->option('arch');
        if (! $archOption) {
            $archOption = \Laravel\Prompts\select(
                label: 'What is the target CPU architecture of the customer server?',
                options: ['amd64' => 'amd64 (Intel/AMD - standard for most VPS/servers)', 'arm64' => 'arm64 (Apple Silicon / Graviton)'],
                default: 'amd64',
            );
        }

        $platform = $this->normalizeArch((string) $archOption);
        if ($platform === null) {
            $this->laraKubeError("Unsupported --arch '{$archOption}' — use amd64 or arm64.");

            return 1;
        }
        $arch = str_replace('linux/', '', $platform);

        $images = $this->bundleImages($config);
        $images['app'] = $config->getName().':'.$env.'-latest';
        $allImages = $this->option('update') ? [$images['app']] : array_merge([$images['app']], $images['dependencies']);
        $name = $config->getName();
        $timestamp = date('Ymd-His');
        $outDir = $config->getPath()."/dist/{$name}-{$env}-{$arch}-bundle-{$timestamp}";

        $this->laraKubeInfo("Air-gapped bundle — {$name} · {$env} · {$arch}");
        $this->newLine();
        $this->line('  <fg=gray>Output:</> '.$outDir);
        $this->line('  <fg=gray>App image (built):</> <fg=cyan>'.$images['app'].'</>');
        $this->line('  <fg=gray>Dependency images (saved):</>');
        foreach ($images['dependencies'] as $img) {
            $this->line('    • <fg=cyan>'.$img.'</>  <fg=gray>→ images/'.$this->imageTarName($img).'</>');
        }
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->laraKubeInfo('Dry run — nothing built or saved.');

            return 0;
        }

        @mkdir("$outDir/images", 0755, true);
        @mkdir("$outDir/manifests", 0755, true);

        $gitignorePath = $config->getPath().'/.gitignore';
        if (file_exists($gitignorePath)) {
            $gitignore = file_get_contents($gitignorePath);
            if (! str_contains($gitignore, '/dist')) {
                file_put_contents($gitignorePath, rtrim($gitignore)."\n/dist\n");
            }
        }

        $dockerignorePath = $config->getPath().'/.dockerignore';
        if (file_exists($dockerignorePath)) {
            $dockerignore = file_get_contents($dockerignorePath);
            if (! str_contains($dockerignore, '/dist')) {
                file_put_contents($dockerignorePath, rtrim($dockerignore)."\n/dist\n");
            }
        }

        if (! $this->runPreDeploymentSteps($config)) {
            return 1;
        }

        // 1. Build the app image for the TARGET arch (the customer's, not the Mac's).
        $this->laraKubeInfo("Building app image for {$platform}…");
        passthru($this->buildProductionImageCommand($images['app'], $config->getPath().'/Dockerfile.php', $config->getPath(), $platform), $code);
        if ($code !== 0) {
            $this->laraKubeError('App image build failed.');

            return 1;
        }

        // 2. Pull (dependencies, for the target arch) then save every image to a tarball.
        foreach ($allImages as $index => $image) {
            $tar = "$outDir/images/".$this->imageTarName($image);
            if ($index > 0) {
                $this->line('  <fg=gray>pull</> '.$image.' for '.$platform);
                passthru('docker pull --platform '.escapeshellarg($platform).' '.escapeshellarg($image));
            }
            $this->line('  <fg=gray>save</> '.$image);
            passthru('docker save --platform '.escapeshellarg($platform).' '.escapeshellarg($image).' -o '.escapeshellarg($tar), $code);
            if ($code !== 0) {
                $this->laraKubeError("Failed to save {$image} — is it built/pulled and is Docker running?");

                return 1;
            }
        }

        // 3. Manifests — copy the generated kustomize tree (image refs stay `<app>:latest`;
        //    `bundle:install` imports the saved app image under that exact name, IfNotPresent).
        $k8s = $config->getK8sPath();
        if (is_dir($k8s)) {
            passthru('cp -R '.escapeshellarg($k8s.'/.').' '.escapeshellarg("$outDir/manifests"));

            // Prune overlays for other environments to avoid leaking configs
            foreach (array_keys($config->getEnvironments()) as $otherEnv) {
                if ($otherEnv !== $env) {
                    $overlayPath = "$outDir/manifests/overlays/{$otherEnv}";
                    if (is_dir($overlayPath)) {
                        passthru('rm -rf '.escapeshellarg($overlayPath));
                    }
                }
            }

        }
        passthru('cp '.escapeshellarg($config->getPath().'/.larakube.json').' '.escapeshellarg("$outDir/.larakube.json"));
        if (file_exists($config->getPath().'/.env.example')) {
            passthru('cp '.escapeshellarg($config->getPath().'/.env.example').' '.escapeshellarg("$outDir/.env.example"));
        }

        $isUpdate = $this->option('update');
        $k3sVersion = $config->k3sVersion ?? 'v1.30.4+k3s1';
        $kustomizeVersion = $config->kustomizeVersion ?? 'v5.6.0';
        $kArch = $arch === 'arm64' ? 'arm64' : 'amd64';

        $this->laraKubeInfo("Downloading kustomize standalone binary ({$kustomizeVersion})...");
        $kustomizeUrl = "https://github.com/kubernetes-sigs/kustomize/releases/download/kustomize%2F{$kustomizeVersion}/kustomize_{$kustomizeVersion}_linux_{$kArch}.tar.gz";
        passthru('curl -sL '.escapeshellarg($kustomizeUrl).' | tar -xz -C '.escapeshellarg($outDir).' kustomize');
        passthru('chmod +x '.escapeshellarg("$outDir/kustomize"));

        if (! $isUpdate) {
            // 4. Offline k3s artifacts & larakube binary
            $this->laraKubeInfo("Downloading k3s artifacts ({$k3sVersion}) for offline install...");

            $k3sBinaryUrl = "https://github.com/k3s-io/k3s/releases/download/{$k3sVersion}/k3s".($arch === 'arm64' ? '-arm64' : '');
            $k3sImagesUrl = "https://github.com/k3s-io/k3s/releases/download/{$k3sVersion}/k3s-airgap-images-{$arch}.tar";
            $k3sInstallUrl = 'https://get.k3s.io';

            $this->line('  <fg=gray>download</> k3s binary');
            passthru('curl -sL '.escapeshellarg($k3sBinaryUrl).' -o '.escapeshellarg("$outDir/k3s"));
            passthru('chmod +x '.escapeshellarg("$outDir/k3s"));

            $this->line('  <fg=gray>download</> k3s-airgap-images');
            passthru('curl -sL '.escapeshellarg($k3sImagesUrl).' -o '.escapeshellarg("$outDir/k3s-airgap-images.tar"));

            $this->line('  <fg=gray>download</> k3s-install.sh');
            passthru('curl -sL '.escapeshellarg($k3sInstallUrl).' -o '.escapeshellarg("$outDir/k3s-install.sh"));
            passthru('chmod +x '.escapeshellarg("$outDir/k3s-install.sh"));
        }

        $binArch = $arch === 'amd64' ? 'x64' : 'arm';
        $binaryName = "larakube-linux-{$binArch}";

        $this->line("  <fg=gray>download</> larakube binary (Linux {$binArch})");
        $binaryUrl = "https://github.com/luchavez-technologies/larakube-cli/releases/latest/download/{$binaryName}";
        passthru('curl -sL '.escapeshellarg($binaryUrl).' -o '.escapeshellarg("$outDir/larakube"));

        passthru('chmod +x '.escapeshellarg("$outDir/larakube"));

        // 5. bundle.json
        $manifestData = $this->bundleManifest($config, $env, $arch, $allImages);
        $manifestData['k3sVersion'] = $k3sVersion;
        file_put_contents(
            "$outDir/bundle.json",
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle assembled: '.$outDir);

        if ($this->option('tar')) {
            $this->laraKubeInfo('Compressing bundle into a .tar.gz archive...');
            $tarFile = $outDir.'.tar.gz';
            $baseDir = dirname($outDir);
            $folderName = basename($outDir);

            passthru('tar -czf '.escapeshellarg($tarFile).' -C '.escapeshellarg($baseDir).' '.escapeshellarg($folderName), $tarCode);

            if ($tarCode === 0) {
                $this->laraKubeInfo('✅ Bundle compressed successfully: '.$tarFile);
                $this->laraKubeInfo('You can safely delete the uncompressed folder: rm -rf '.escapeshellarg($outDir));

                // Generate instructions file
                $cmd = $isUpdate ? 'bundle:update' : 'bundle:install';
                $instructionsFile = $outDir.'-INSTRUCTIONS.txt';
                $instructions = <<<TXT
=========================================================
LARAKUBE AIR-GAPPED BUNDLE INSTRUCTIONS
=========================================================

1. TRANSFER THE BUNDLE TO THE SERVER
   If your server has SSH enabled, transfer the bundle securely with rsync (which shows a progress bar):
   rsync -P {$folderName}.tar.gz username@your-server-ip:~/

2. EXTRACT THE BUNDLE
   SSH into your server and run the following command to extract it:
   tar -xzf {$folderName}.tar.gz

3. RUN THE DEPLOYMENT
   cd {$folderName}
   sudo ./larakube {$cmd}

=========================================================
TXT;
                file_put_contents($instructionsFile, $instructions);
                $this->laraKubeInfo('✅ Generated instructions: '.$instructionsFile);
            } else {
                $this->laraKubeError('Failed to compress the bundle.');
            }
        }

        $cmd = $isUpdate ? 'bundle:update' : 'bundle:install';
        $this->laraKubeInfo("Next step: copy the bundle to the target server and run `sudo ./larakube {$cmd}`.");

        return 0;
    }
}
