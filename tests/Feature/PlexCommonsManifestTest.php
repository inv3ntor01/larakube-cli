<?php

/**
 * Renders the actual Plex Commons blade so a compile error or a broken @if
 * can't slip through to the droplet. Asserts the spec drives which services
 * appear in the manifest.
 */

use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithPlex;

function plexManifest(array $spec): string
{
    $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return view('k8s.plex.commons', [
        'spec' => $spec,
        'specJsonIndented' => preg_replace('/^/m', '    ', $json),
    ])->render();
}

function plexHelper(): object
{
    return new class
    {
        use InteractsWithPlex;
    };
}

test('the default Commons manifest has Postgres + Redis, embeds the spec, and omits Meili', function () {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec(false));

    expect($yaml)
        ->toContain('kind: ConfigMap')
        ->toContain('name: plex-commons')
        ->toContain('commons.json: |')          // spec is embedded (self-describing)
        ->toContain('name: postgres')
        ->toContain('image: postgres:17.9')
        ->toContain('claimName: postgres-data')
        ->toContain('name: redis')
        ->toContain('image: redis:7.4')
        ->not->toContain('name: meilisearch');
});

test('--with-meili adds the Meilisearch service to the manifest', function () {
    $yaml = plexManifest(plexHelper()->defaultCommonsSpec(true));

    expect($yaml)
        ->toContain('image: '.ScoutDriver::MEILISEARCH->getDockerImage())  // in lockstep with the enum, not a stale literal
        ->toContain('claimName: meilisearch-data');
});

test('enabling object storage adds the SeaweedFS S3 service to the manifest', function () {
    $spec = plexHelper()->normalizeCommonsSpec(['services' => ['seaweedfs' => ['enabled' => true]]]);
    $yaml = plexManifest($spec);

    expect($yaml)
        ->toContain('name: seaweedfs')
        ->toContain('image: '.StorageDriver::SEAWEEDFS->getDockerImage())
        ->toContain('claimName: seaweedfs-data')
        ->toContain('"-s3"');  // the S3 gateway is enabled
});
