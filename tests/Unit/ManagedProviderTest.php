<?php

use App\Enums\ManagedProvider;

test('each managed provider maps to its default storage class', function () {
    expect(ManagedProvider::DOKS->defaultStorageClass())->toBe('do-block-storage');
    expect(ManagedProvider::EKS->defaultStorageClass())->toBe('gp3');
    expect(ManagedProvider::GKE->defaultStorageClass())->toBe('standard');
    expect(ManagedProvider::AKS->defaultStorageClass())->toBe('managed-csi');
    expect(ManagedProvider::CIVO->defaultStorageClass())->toBe('civo-volume');
    expect(ManagedProvider::LKE->defaultStorageClass())->toBe('linode-block-storage');
    expect(ManagedProvider::CUSTOM->defaultStorageClass())->toBeNull();
});
