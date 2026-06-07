<?php

use App\Data\ConfigData;
use App\Enums\DeploymentStrategy;
use App\Enums\ServerVariation;

test('Strategy: single-node cloud env gets RWO storage + data PVCs', function () {
    $config = new ConfigData(name: 'strat-single');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::SINGLE_NODE);

    $manifests = generateManifestsAsArray($config);

    // App PVCs live per-environment, not in base/.
    expect($manifests)->not->toHaveKey('base/volumes.yaml')
        ->and($manifests)->toHaveKey('overlays/production/app-volumes.yaml');

    // Single-node: both the shared storage PVC and the data PVC, ReadWriteOnce.
    $prod = $manifests['overlays/production/app-volumes.yaml'];
    expect($prod)->toHaveCount(2)
        ->and($prod[0]['spec']['accessModes'][0])->toBe('ReadWriteOnce')
        ->and($prod[1]['spec']['accessModes'][0])->toBe('ReadWriteOnce');

    // No emptyDir swap on single-node.
    expect($manifests)->not->toHaveKey('overlays/production/storage-emptydir.yaml');
});

test('Strategy: multi-node has no shared PVC — app pods use a per-pod emptyDir', function () {
    $config = new ConfigData(name: 'strat-multi');
    $config->setServerVariation(ServerVariation::FPM_NGINX);
    $config->setStrategy(DeploymentStrategy::MULTI_NODE_HA);

    $manifests = generateManifestsAsArray($config);

    // No shared storage PVC on multi-node (block storage can't do RWX across nodes)…
    expect($manifests)->not->toHaveKey('overlays/production/app-volumes.yaml')
        // …an emptyDir patch replaces the app pods' storage volume instead.
        ->and($manifests)->toHaveKey('overlays/production/storage-emptydir.yaml');

    // Local is always single-node → its shared storage PVC stays ReadWriteOnce.
    $local = $manifests['overlays/local/app-volumes.yaml'];
    expect($local[0]['spec']['accessModes'][0])->toBe('ReadWriteOnce');
});

test('scheduler CronJob gets a cloud wait override that excludes managed services', function () {
    $config = ConfigData::from([
        'name' => 'sched-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'features' => ['scheduler'],
        'environments' => [
            'local' => [],
            'production' => [
                'hosts' => ['web' => 'sched.example'],
                'managed' => ['postgres'],   // externalized → the wait must not nc it
            ],
        ],
    ]);

    $patch = generateManifestsAsArray($config)['overlays/production/deployment-patch.yaml'];
    $cron = collect($patch)->firstWhere('kind', 'CronJob');

    expect($cron)->not->toBeNull();
    $cmd = $cron['spec']['jobTemplate']['spec']['template']['spec']['initContainers'][0]['command'][2];
    expect($cmd)
        ->toContain('curl -sf http://web/up')   // waits for the web pod (migrations)
        ->not->toContain('postgres')            // managed in this env → never waited on
        ->not->toContain('\/');                 // unescaped slashes (kustomize-parseable)
});
