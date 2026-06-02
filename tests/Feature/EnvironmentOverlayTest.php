<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;

test('a custom environment generates its own complete overlay', function () {
    $config = ConfigData::from([
        'name' => 'envgen',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'features' => ['reverb'],
        'environments' => [
            'local' => [],
            'production' => ['hosts' => ['web' => 'envgen.com']],
            'staging' => [
                'ingress' => 'nginx',
                'hosts' => ['web' => 'stg.envgen.com'],
            ],
        ],
    ]);

    $manifests = generateManifestsAsArray($config);

    // The staging overlay exists and is namespaced to staging.
    expect($manifests)->toHaveKey('overlays/staging/kustomization.yaml');
    expect($manifests['overlays/staging/kustomization.yaml']['namespace'])->toBe('envgen-staging');

    // Its ingress reflects staging's own controller + host, not production's.
    expect($manifests)->toHaveKey('overlays/staging/ingress-patch.yaml');
    $ingress = $manifests['overlays/staging/ingress-patch.yaml'];
    expect($ingress['spec']['ingressClassName'])->toBe('nginx')
        ->and($ingress['spec']['rules'][0]['host'])->toBe('stg.envgen.com');

    // Production overlay keeps its own host (regression guard for the fix).
    expect($manifests['overlays/production/ingress-patch.yaml']['spec']['rules'][0]['host'])
        ->toBe('envgen.com');
});

test('an all-environment feature (Reverb) reaches a custom env', function () {
    // Regression for the bug where defaultEnvironments() hardcoded
    // [local, production], excluding Reverb from staging/qa entirely.
    $config = ConfigData::from([
        'features' => ['reverb', 'horizon'],
        'environments' => [
            'local' => [],
            'staging' => [],
        ],
    ]);

    expect($config->getFeatures('staging'))
        ->toContain(LaravelFeature::REVERB)
        ->toContain(LaravelFeature::HORIZON);

    // Reverb's deployment lives in base (shared by every overlay), so the
    // staging overlay picks it up via ../../base.
    $manifests = generateManifestsAsArray(ConfigData::from([
        'name' => 'rev',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'features' => ['reverb'],
        'environments' => ['local' => [], 'staging' => []],
    ]));

    expect($manifests)->toHaveKey('base/reverb-deployment.yaml')
        ->and($manifests['overlays/staging/kustomization.yaml']['resources'])->toContain('../../base');
});

test('SSR applies to every cloud env, not only production', function () {
    expect(LaravelFeature::SSR->appliesToEnvironment('staging'))->toBeTrue()
        ->and(LaravelFeature::SSR->appliesToEnvironment('production'))->toBeTrue()
        ->and(LaravelFeature::SSR->appliesToEnvironment('local'))->toBeFalse();
});

test('per-environment strategy lets each cloud env pick its own PVC access mode', function () {
    // Multi-VPC reality: production is an HA cluster (RWX), staging is a single
    // box (RWO). Local is always single-node regardless.
    $config = ConfigData::from([
        'name' => 'perenv',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'strategy' => 'single-node',
        'environments' => [
            'local' => [],
            'production' => [
                'hosts' => ['web' => 'perenv.com'],
                'strategy' => 'multi-node-ha',
            ],
            'staging' => [
                'hosts' => ['web' => 'stg.perenv.com'],
                'strategy' => 'single-node',
            ],
        ],
    ]);

    $manifests = generateManifestsAsArray($config);

    // Production overrides the project default → ReadWriteMany.
    expect($manifests['overlays/production/app-volumes.yaml'][0]['spec']['accessModes'][0])
        ->toBe('ReadWriteMany');

    // Staging keeps single-node → ReadWriteOnce.
    expect($manifests['overlays/staging/app-volumes.yaml'][0]['spec']['accessModes'][0])
        ->toBe('ReadWriteOnce');

    // Local is always ReadWriteOnce.
    expect($manifests['overlays/local/app-volumes.yaml'][0]['spec']['accessModes'][0])
        ->toBe('ReadWriteOnce');
});

test('a managed service is removed from the env that manages it via a delete-patch', function () {
    $config = ConfigData::from([
        'name' => 'mgd',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'production' => [
                'hosts' => ['web' => 'mgd.com'],
                'managed' => ['postgres'],
            ],
        ],
    ]);

    $manifests = generateManifestsAsArray($config);

    // Base still ships the Postgres deployment (local keeps using it).
    expect($manifests)->toHaveKey('base/postgres-deployment.yaml');

    // Production gets ONE SINGLE-document delete-patch PER resource (Deployment,
    // Service). kustomize/kyaml panics on a multi-document $patch:delete file under
    // the `patches:` field, so we must never bundle them — the old single
    // `postgres-managed-delete.yaml` shape is gone.
    expect($manifests)
        ->toHaveKey('overlays/production/postgres-managed-delete-deployment.yaml')
        ->toHaveKey('overlays/production/postgres-managed-delete-service.yaml')
        ->not->toHaveKey('overlays/production/postgres-managed-delete.yaml');

    // Each file is a single doc (parsed to a map, not a list), so kustomize is happy.
    $deploymentDelete = $manifests['overlays/production/postgres-managed-delete-deployment.yaml'];
    expect($deploymentDelete['kind'])->toBe('Deployment')
        ->and($deploymentDelete['$patch'])->toBe('delete')
        ->and($deploymentDelete['metadata']['name'])->toBe('postgres');

    $serviceDelete = $manifests['overlays/production/postgres-managed-delete-service.yaml'];
    expect($serviceDelete['kind'])->toBe('Service')
        ->and($serviceDelete['$patch'])->toBe('delete')
        ->and($serviceDelete['metadata']['name'])->toBe('postgres');

    // Both are registered as patches in the production overlay.
    expect($manifests['overlays/production/kustomization.yaml']['patches'])
        ->toContain(['path' => 'postgres-managed-delete-deployment.yaml'])
        ->toContain(['path' => 'postgres-managed-delete-service.yaml']);

    // And Postgres volumes are NOT registered as a production resource.
    expect($manifests['overlays/production/kustomization.yaml']['resources'] ?? [])
        ->not->toContain('postgres-volumes.yaml');

    // The volume file is not even written to disk for a managed env (no
    // stray, unreferenced manifest left behind).
    expect($manifests)->not->toHaveKey('overlays/production/postgres-volumes.yaml');
});
