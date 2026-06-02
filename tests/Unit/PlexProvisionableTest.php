<?php

/**
 * The PlexProvisionable contract lets the plex commands ask the driver enums
 * "are you a Commons service, and are you wired up yet?" instead of hardcoding
 * 'postgres'/'redis'. The service name is just the driver's own value (no
 * remapping), so distinct backends stay distinct and can coexist.
 */

use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexCatalog(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('a Commons service name is just the driver value (no remapping)', function () {
    expect(DatabaseDriver::POSTGRESQL->commonsServiceName())->toBe('postgres')
        ->and(CacheDriver::REDIS->commonsServiceName())->toBe('redis')
        ->and(ScoutDriver::MEILISEARCH->commonsServiceName())->toBe('meilisearch')   // the enum value, not a shortened 'meili'
        ->and(StorageDriver::SEAWEEDFS->commonsServiceName())->toBe('seaweedfs')
        ->and(StorageDriver::MINIO->commonsServiceName())->toBe('minio');            // distinct from seaweedfs → can coexist
});

test('non-shareable drivers have no Commons service', function () {
    expect(DatabaseDriver::SQLITE->commonsServiceName())->toBeNull()   // local file
        ->and(CacheDriver::DATABASE->commonsServiceName())->toBeNull() // runs in the app's own DB
        ->and(ScoutDriver::DATABASE->commonsServiceName())->toBeNull();
});

test('plex-readiness reflects what is actually wired today', function () {
    expect(DatabaseDriver::POSTGRESQL->isPlexReady())->toBeTrue()
        ->and(DatabaseDriver::MYSQL->isPlexReady())->toBeFalse()        // mapped, not yet wired
        ->and(CacheDriver::REDIS->isPlexReady())->toBeTrue()
        ->and(ScoutDriver::MEILISEARCH->isPlexReady())->toBeTrue()
        ->and(ScoutDriver::TYPESENSE->isPlexReady())->toBeFalse()
        ->and(StorageDriver::SEAWEEDFS->isPlexReady())->toBeFalse()  // S3 manifest/provisioning is the next slice
        ->and(StorageDriver::MINIO->isPlexReady())->toBeFalse();
});

test('the catalog lists every shareable service, including coexisting S3 backends', function () {
    $catalog = plexCatalog()->commonsServiceCatalog();

    // ready (wired today) services
    expect($catalog['postgres']['ready'])->toBeTrue()
        ->and($catalog['redis']['ready'])->toBeTrue()
        ->and($catalog['meilisearch']['ready'])->toBeTrue();

    // each S3 backend is its OWN entry (so they can coexist once wired), not
    // collapsed into one — all not-ready until the Commons S3 slice lands.
    expect($catalog)->toHaveKeys(['seaweedfs', 'minio', 'garage'])
        ->and($catalog['seaweedfs']['ready'])->toBeFalse()
        ->and($catalog['minio']['ready'])->toBeFalse()
        ->and($catalog['garage']['ready'])->toBeFalse();

    // mapped-but-not-ready services are still listed
    expect($catalog['mysql']['ready'])->toBeFalse()
        ->and($catalog['typesense']['ready'])->toBeFalse();

    // non-shareable drivers are excluded entirely
    expect($catalog)->not->toHaveKey('sqlite')
        ->and($catalog)->not->toHaveKey('database');
});

test('projectCommonsServices returns only the project\'s plex-ready services', function () {
    $config = new ConfigData(
        name: 'app-four',
        database: DatabaseDriver::POSTGRESQL,    // ready → included
        cacheDriver: CacheDriver::REDIS,         // ready → included
        objectStorage: StorageDriver::SEAWEEDFS, // maps to a service but not wired yet → excluded
    );

    expect(plexCatalog()->projectCommonsServices($config))->toBe(['postgres', 'redis']);

    // A project on the default (database) cache + no DB shares nothing.
    expect(plexCatalog()->projectCommonsServices(new ConfigData(name: 'plain')))->toBe([]);
});
