<?php

/**
 * Pure-logic tests for the multi-node shared-storage guard: which worker pods
 * share the RWO app-storage PVC, and when an env counts as multi-node. The
 * interactive prompt + the kubectl node probe are exercised by a real deploy.
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\LaravelFeature;
use App\Traits\GuardsSharedStorage;

function storageGuard(): object
{
    return new class
    {
        use GuardsSharedStorage;

        public function workers(ConfigData $c, string $e): array
        {
            return $this->sharedStorageWorkers($c, $e);
        }

        public function multi(ConfigData $c, string $e, ?string $ctx): bool
        {
            return $this->isMultiNode($c, $e, $ctx);
        }
    };
}

test('shared-storage workers are the PVC-mounting features enabled in the env', function () {
    $config = new ConfigData(name: 'app');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->addFeature(LaravelFeature::HORIZON, LaravelFeature::OCTANE, LaravelFeature::SCOUT);

    expect(storageGuard()->workers($config, 'production'))
        ->toContain('horizon')      // mounts the shared PVC
        ->not->toContain('octane')  // in-process, no shared-PVC pod
        ->not->toContain('scout');
});

test('a web-only app has no shared-storage workers', function () {
    $config = new ConfigData(name: 'app');
    $config->setDatabase(DatabaseDriver::POSTGRESQL);

    expect(storageGuard()->workers($config, 'production'))->toBe([]);
});

test('multi-node-ha strategy is multi-node without probing the cluster', function () {
    $config = new ConfigData(name: 'app');
    $config->setStrategy(DeploymentStrategy::MULTI_NODE_HA);

    expect(storageGuard()->multi($config, 'production', null))->toBeTrue();
});

test('single-node strategy with no cluster context is not multi-node', function () {
    $config = new ConfigData(name: 'app');
    $config->setStrategy(DeploymentStrategy::SINGLE_NODE);

    expect(storageGuard()->multi($config, 'production', null))->toBeFalse();
});
