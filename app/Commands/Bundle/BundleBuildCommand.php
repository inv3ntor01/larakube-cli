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
                            {--arch=amd64 : Target CPU architecture (amd64|arm64) — match the customer server, not your Mac}
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

        $env = (string) $this->argument('environment');
        if ($config->getEnvironment($env) === null) {
            $this->laraKubeError("Unknown environment '{$env}'. Pick one of: ".implode(', ', $config->getCloudEnvironments()));

            return 1;
        }

        $platform = $this->normalizeArch((string) $this->option('arch'));
        if ($platform === null) {
            $this->laraKubeError("Unsupported --arch '{$this->option('arch')}' — use amd64 or arm64.");

            return 1;
        }
        $arch = str_replace('linux/', '', $platform);

        $images = $this->bundleImages($config);
        $allImages = array_merge([$images['app']], $images['dependencies']);
        $name = $config->getName();
        $outDir = $config->getPath()."/dist/{$name}-{$env}-{$arch}-bundle";

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
                passthru('docker image inspect '.escapeshellarg($image).' >/dev/null 2>&1 || docker pull --platform '.escapeshellarg($platform).' '.escapeshellarg($image));
            }
            $this->line('  <fg=gray>save</> '.$image);
            passthru('docker save '.escapeshellarg($image).' -o '.escapeshellarg($tar), $code);
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
        }
        passthru('cp '.escapeshellarg($config->getPath().'/.larakube.json').' '.escapeshellarg("$outDir/.larakube.json"));

        // 4. bundle.json
        file_put_contents(
            "$outDir/bundle.json",
            json_encode($this->bundleManifest($config, $env, $arch, $allImages), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->newLine();
        $this->laraKubeInfo('✅ Bundle assembled: '.$outDir);
        $this->laraKubeWarn('Next: k3s artifacts + the larakube binary + install.sh aren\'t bundled yet — see bundle:install.');

        return 0;
    }
}
