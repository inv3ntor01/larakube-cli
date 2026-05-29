<?php

use App\Data\ConfigData;
use App\Enums\CacheDriver;

/**
 * Managed-K8s overlay compatibility: optional per-env knobs that let a
 * generated overlay drop into a managed cluster (EKS/GKE/AKS). Every knob
 * must no-op to today's Single-Node-Hero output when unset — the snapshot
 * suite is the regression guard; these tests cover the override behavior.
 */
function eksConfig(array $envOverrides): ConfigData
{
    return ConfigData::from([
        'name' => 'eksapp',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'production' => array_merge(['hosts' => ['web' => 'eksapp.com']], $envOverrides),
        ],
    ]);
}

// --- Resolution getters (defaults mirror today's behavior) ---

test('overlay knobs default to current Single-Node-Hero behavior', function () {
    $config = new ConfigData(name: 'plain');

    expect($config->getNamespace('production'))->toBe('plain-production')
        ->and($config->getServiceAccount('production'))->toBeNull()
        ->and($config->getServiceAccountAnnotations('production'))->toBe([])
        ->and($config->getImagePullSecret('production'))->toBe('ghcr-login')
        ->and($config->getIngressAnnotations('production'))->toBe([]);
});

test('namespace override replaces the derived {name}-{env}', function () {
    $config = eksConfig(['namespace' => 'eksapp']);

    expect($config->getNamespace('production'))->toBe('eksapp')
        // other envs still derive normally
        ->and($config->getNamespace('local'))->toBe('eksapp-local');
});

test('imagePullSecret can be overridden or omitted entirely', function () {
    expect(eksConfig(['imagePullSecret' => 'dockerhub'])->getImagePullSecret('production'))
        ->toBe('dockerhub');

    expect(eksConfig(['omitImagePullSecret' => true])->getImagePullSecret('production'))
        ->toBeNull();
});

// --- Phase 1: imagePullSecret in the manifests ---

// The web-only deployment-patch is a single YAML doc; the helper returns it
// un-listed (no '---'). Normalize to the first/only document.
function prodWebPatch(ConfigData $config): array
{
    $patch = generateManifestsAsArray($config)['overlays/production/deployment-patch.yaml'];

    return $patch[0] ?? $patch;
}

test('Phase 1: production deployment-patch uses the default ghcr-login pull secret', function () {
    $web = prodWebPatch(eksConfig([]));
    expect($web['spec']['template']['spec']['imagePullSecrets'][0]['name'])->toBe('ghcr-login');
});

test('Phase 1: omitImagePullSecret drops the imagePullSecrets block (ECR/IRSA)', function () {
    $web = prodWebPatch(eksConfig(['omitImagePullSecret' => true]));
    expect($web['spec']['template']['spec'])->not->toHaveKey('imagePullSecrets');
});

test('Phase 1: a custom pull secret name flows into the patch', function () {
    $web = prodWebPatch(eksConfig(['imagePullSecret' => 'ecr-creds']));
    expect($web['spec']['template']['spec']['imagePullSecrets'][0]['name'])->toBe('ecr-creds');
});

// --- Phase 2: namespace override in the manifests ---

test('Phase 2: namespace override lands the overlay in an existing namespace', function () {
    $manifests = generateManifestsAsArray(eksConfig(['namespace' => 'eksapp']));

    // Overlay kustomization sets the overridden namespace…
    expect($manifests['overlays/production/kustomization.yaml']['namespace'])->toBe('eksapp');

    // …and the Namespace object it creates matches.
    expect($manifests['overlays/production/namespace.yaml']['metadata']['name'])->toBe('eksapp');

    // Local overlay is untouched by a production-only override.
    expect($manifests['overlays/local/kustomization.yaml']['namespace'])->toBe('eksapp-local');
});

test('Phase 2: in-cluster service FQDNs follow the overridden namespace', function () {
    $config = eksConfig(['namespace' => 'eksapp']);
    $config->setCacheDriver(CacheDriver::REDIS);

    expect($config->getInternalFqdn(CacheDriver::REDIS, 'production'))
        ->toEndWith('.eksapp.svc.cluster.local');

    // Default-namespace env still resolves the derived namespace.
    expect($config->getInternalFqdn(CacheDriver::REDIS, 'staging'))
        ->toEndWith('.eksapp-staging.svc.cluster.local');
});
