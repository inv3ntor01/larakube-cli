<?php

/**
 * Pure-logic tests for the Plex Commons spec — the shape that drives the
 * manifest renderer and survives the plex:export → plex:init --from round-trip.
 * The kubectl-touching parts of InteractsWithPlex belong in a cluster smoke test,
 * not here.
 */

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
        ->and($spec['services']['meili']['enabled'])->toBeFalse();
});

test('--with-meili turns on Meilisearch', function () {
    $p = plexSpec();

    expect($p->enabledCommonsServices($p->defaultCommonsSpec(true)))
        ->toBe(['postgres', 'redis', 'meili']);
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
