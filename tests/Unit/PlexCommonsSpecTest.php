<?php

/**
 * Pure-logic tests for the Plex Commons spec — the shape that drives the
 * manifest renderer and survives the plex:export → plex:init --from round-trip.
 * The kubectl-touching parts of InteractsWithPlex belong in a cluster smoke test,
 * not here.
 */

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexSpec(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('the default spec enables Postgres + Redis and leaves Meili off', function () {
    $p = plexSpec();
    $spec = $p->defaultCommonsSpec(false);

    expect($p->enabledCommonsServices($spec))->toBe(['postgres', 'redis'])
        ->and($spec['services']['postgres']['image'])->toBe('postgres:17.9')
        ->and($spec['services']['postgres']['storage'])->toBe('10Gi')
        ->and($spec['services']['redis']['port'])->toBe(6379)
        ->and($spec['services']['meilisearch']['enabled'])->toBeFalse();
});

test('Commons service images/ports derive from the driver enums (no drift)', function () {
    $spec = plexSpec()->defaultCommonsSpec(true)['services'];

    expect($spec['postgres']['image'])->toBe(DatabaseDriver::POSTGRESQL->getDockerImage())
        ->and($spec['postgres']['port'])->toBe(DatabaseDriver::POSTGRESQL->dbPort())
        ->and($spec['redis']['image'])->toBe(CacheDriver::REDIS->getDockerImage())
        ->and($spec['redis']['port'])->toBe(CacheDriver::REDIS->dbPort())
        ->and($spec['meilisearch']['image'])->toBe(ScoutDriver::MEILISEARCH->getDockerImage())  // stays in lockstep, no stale literal
        ->and($spec['meilisearch']['port'])->toBe(ScoutDriver::MEILISEARCH->port())
        ->and($spec['seaweedfs']['image'])->toBe(StorageDriver::SEAWEEDFS->getDockerImage())
        ->and($spec['seaweedfs']['port'])->toBe(StorageDriver::SEAWEEDFS->port());
});

test('--with-meili turns on Meilisearch', function () {
    $p = plexSpec();

    expect($p->enabledCommonsServices($p->defaultCommonsSpec(true)))
        ->toBe(['postgres', 'redis', 'meilisearch']);
});

test('normalize fills defaults for a partial spec and respects an explicit disable', function () {
    $p = plexSpec();

    $spec = $p->normalizeCommonsSpec([
        'services' => [
            'postgres' => ['enabled' => false],   // explicitly off
            'redis' => ['storage' => 'ignored'],  // partial — defaults should fill image/port
        ],
    ]);

    expect($p->enabledCommonsServices($spec))->toBe(['redis'])
        ->and($spec['services']['postgres']['enabled'])->toBeFalse()
        ->and($spec['services']['redis']['image'])->toBe('redis:7.4')   // default filled
        ->and($spec['services']['redis']['port'])->toBe(6379);
});

test('normalize is idempotent so export → init --from is lossless', function () {
    $p = plexSpec();

    $once = $p->defaultCommonsSpec(true);

    expect($p->normalizeCommonsSpec($once))->toEqual($once);
});
