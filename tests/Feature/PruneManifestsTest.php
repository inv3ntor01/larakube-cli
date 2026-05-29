<?php

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithArchitecturalEngine;
use Laravel\Prompts\Prompt;

/**
 * Build a scaffolded project in a temp dir and return [config, k8sPath, harness].
 * The harness exposes the trait so tests can call pruneStaleManifests directly.
 */
function scaffoldForPrune(ConfigData $config): array
{
    Prompt::fallbackUsing(fn () => true);

    $tempDir = sys_get_temp_dir().'/larakube-prune-'.uniqid();
    mkdir($tempDir, 0755, true);
    $config->setPath($tempDir);

    $harness = new class
    {
        use GeneratesProjectInfrastructure, InteractsWithArchitecturalEngine;

        public function line($s, $st = null, $v = null) {}

        public function info($s, $v = null) {}

        public function warn($s, $v = null) {}

        public function error($s, $v = null) {}

        public function newLine($c = 1) {}

        public function withSpin($t, $cb)
        {
            return $cb();
        }

        public function laraKubeInfo($t) {}

        public function runScaffolding(ConfigData $config)
        {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, dryRun: false);
        }

        public function prune(ConfigData $config): array
        {
            return $this->pruneStaleManifests($config);
        }
    };

    $harness->runScaffolding($config);

    return [$config, $config->getK8sPath(), $harness, $tempDir];
}

test('prune removes a stray manifest but keeps referenced and locked ones', function () {
    $config = ConfigData::from([
        'name' => 'prune',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => ['hosts' => ['web' => 'prune.com']]],
    ]);

    [$config, $k8sPath, $harness, $tempDir] = scaffoldForPrune($config);

    // Drop two stray files into the production overlay: one plain, one locked.
    file_put_contents("$k8sPath/overlays/production/orphan.yaml", "kind: ConfigMap\n");
    file_put_contents("$k8sPath/overlays/production/kept.yaml", "kind: ConfigMap\n");
    $config->addLockedFile("$k8sPath/overlays/production/kept.yaml");

    $pruned = $harness->prune($config);

    expect($pruned)->toContain('overlays/production/orphan.yaml')
        ->and(file_exists("$k8sPath/overlays/production/orphan.yaml"))->toBeFalse()
        // Locked stray survives.
        ->and(file_exists("$k8sPath/overlays/production/kept.yaml"))->toBeTrue()
        // Referenced manifests survive.
        ->and(file_exists("$k8sPath/overlays/production/kustomization.yaml"))->toBeTrue()
        ->and(file_exists("$k8sPath/base/kustomization.yaml"))->toBeTrue();

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('prune removes overlay directories for environments dropped from the blueprint', function () {
    $config = ConfigData::from([
        'name' => 'prune2',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => ['hosts' => ['web' => 'p.com']], 'staging' => []],
    ]);

    [$config, $k8sPath, $harness, $tempDir] = scaffoldForPrune($config);

    expect(is_dir("$k8sPath/overlays/staging"))->toBeTrue();

    // Drop staging from the blueprint, then prune.
    $config->removeEnvironment('staging');
    $pruned = $harness->prune($config);

    expect(is_dir("$k8sPath/overlays/staging"))->toBeFalse()
        ->and(collect($pruned)->contains(fn ($p) => str_starts_with($p, 'overlays/staging/')))->toBeTrue()
        // Surviving envs untouched.
        ->and(is_dir("$k8sPath/overlays/production"))->toBeTrue()
        ->and(is_dir("$k8sPath/overlays/local"))->toBeTrue();

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('prune leaves a dropped-env directory in place when it holds a locked file', function () {
    $config = ConfigData::from([
        'name' => 'prune3',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => ['hosts' => ['web' => 'p.com']], 'qa' => []],
    ]);

    [$config, $k8sPath, $harness, $tempDir] = scaffoldForPrune($config);

    $config->addLockedFile("$k8sPath/overlays/qa/kustomization.yaml");
    $config->removeEnvironment('qa');
    $harness->prune($config);

    // The locked file (and therefore its directory) is preserved.
    expect(file_exists("$k8sPath/overlays/qa/kustomization.yaml"))->toBeTrue();

    exec('rm -rf '.escapeshellarg($tempDir));
});
