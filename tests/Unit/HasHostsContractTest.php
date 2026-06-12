<?php

use App\Contracts\HasHosts;
use App\Contracts\HasPromptableHosts;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;

test('every host-publishing component declares its overrideable services', function () {
    // Cloud-overrideable components — must have entries in getHostServices.
    expect(LaravelFeature::REVERB->getHostServices())->toHaveKey('reverb')
        ->and(LaravelFeature::MAILPIT->getHostServices())->toHaveKey('mailpit')
        ->and(LaravelFeature::MONITORING->getHostServices())
        ->toHaveKey('grafana')
        ->toHaveKey('prometheus')
        ->and(ScoutDriver::MEILISEARCH->getHostServices())->toHaveKey('meilisearch')
        ->and(ScoutDriver::TYPESENSE->getHostServices())
        ->toHaveKey('typesense')
        ->toHaveKey('typesense-dashboard')
        ->and(StorageDriver::MINIO->getHostServices())
        ->toHaveKey('s3')
        ->toHaveKey('s3-console');
});

test('local-console components opt out of host overrides via empty getHostServices', function () {
    // DB and Cache consoles are local-only with baked-in .kube domains;
    // they MUST NOT show up in the env wizard's host-override prompts.
    expect(DatabaseDriver::MYSQL->getHostServices())->toBe([])
        ->and(DatabaseDriver::POSTGRESQL->getHostServices())->toBe([])
        ->and(CacheDriver::REDIS->getHostServices())->toBe([])
        ->and(CacheDriver::MEMCACHED->getHostServices())->toBe([])
        ->and(ServerVariation::FRANKENPHP->getHostServices())->toBe([])
        ->and(Blueprint::FILAMENT->getHostServices())->toBe([]);
});

test('database consoles do not leak into non-local ingress (the latent bug fix)', function () {
    $config = ConfigData::from([
        'name' => 'demo',
        'databases' => ['mysql'],
        'environments' => [
            'local' => [],
            'production' => ['hosts' => ['web' => 'example.com']],
        ],
    ]);

    // Local still gets the console (developer ergonomics).
    expect($config->getAllHosts('local'))->toHaveKey('mysql.demo.kube');

    // Production must NOT include the MySQL console — exposing admin UIs
    // through cloud ingress is a security smell.
    $prodHosts = $config->getAllHosts('production');
    expect(array_keys($prodHosts))->not->toContain('mysql.demo.kube')
        ->and(array_keys($prodHosts))->not->toContain('mysql-example.com');
});

test('DerivesHostsFromServices trait honours per-env overrides through getServiceHost', function () {
    $config = ConfigData::from([
        'name' => 'demo',
        'features' => ['reverb'],
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'example.com',
                    'reverb' => 'ws.example.com',
                ],
            ],
        ],
    ]);

    $hosts = LaravelFeature::REVERB->getHosts($config, 'production');

    expect($hosts)->toHaveKey('ws.example.com', 'Reverb WebSocket');
});

test('DerivesHostsFromServices trait falls back to prefix pattern when no override', function () {
    $config = ConfigData::from([
        'name' => 'demo',
        'features' => ['reverb'],
        'environments' => [
            'production' => ['hosts' => ['web' => 'example.com']],
        ],
    ]);

    $hosts = LaravelFeature::REVERB->getHosts($config, 'production');

    expect($hosts)->toHaveKey('reverb-example.com', 'Reverb WebSocket');
});

test('storage driver exposes both s3 and console as overrideable services', function () {
    $config = ConfigData::from([
        'name' => 'demo',
        'objectStorage' => 'minio',
        'environments' => [
            'production' => [
                'hosts' => [
                    'web' => 'example.com',
                    's3' => 'cdn.example.com',
                    // s3-console intentionally omitted — falls back to prefix.
                ],
            ],
        ],
    ]);

    $hosts = StorageDriver::MINIO->getHosts($config, 'production');

    expect($hosts)
        ->toHaveKey('cdn.example.com', 'MinIO S3 API')
        ->toHaveKey('s3-console-example.com', 'MinIO Console');
});

test('storage driver skips its manifests in envs where it is externally managed', function () {
    $config = ConfigData::from([
        'name' => 'demo',
        'objectStorage' => 'minio',
        'environments' => [
            'local' => [],
            'production' => ['managed' => ['minio']],
        ],
    ]);

    $files = StorageDriver::MINIO->getManifestFiles($config);

    // Local still deploys MinIO; production is skipped (managed via S3/Spaces).
    expect($files)->toHaveKey('local')
        ->and($files)->not->toHaveKey('production');
});

test('only client-facing endpoints are promptable for custom hosts', function () {
    // Reverb (ws) and S3 are worth a vanity subdomain prompt...
    expect(LaravelFeature::REVERB)->toBeInstanceOf(HasPromptableHosts::class)
        ->and(LaravelFeature::REVERB->getPromptableHostServices())->toHaveKey('reverb')
        ->and(StorageDriver::MINIO)->toBeInstanceOf(HasPromptableHosts::class)
        ->and(StorageDriver::MINIO->getPromptableHostServices())->toHaveKey('s3');

    // The MinIO admin console is not promptable; only the S3 API is.
    expect(StorageDriver::MINIO->getPromptableHostServices())->not->toHaveKey('s3-console');

    // Search drivers do NOT implement HasPromptableHosts at all, so the env
    // wizard never prompts for a Meilisearch/Typesense console host (they
    // still publish a derived ingress host via getHostServices()).
    expect(ScoutDriver::MEILISEARCH)->not->toBeInstanceOf(HasPromptableHosts::class)
        ->and(ScoutDriver::TYPESENSE)->not->toBeInstanceOf(HasPromptableHosts::class);

    // Mailpit and monitoring dashboards belong to LaravelFeature (which does
    // implement the interface) but expose no promptable services.
    expect(LaravelFeature::MAILPIT->getPromptableHostServices())->toBe([])
        ->and(LaravelFeature::MONITORING->getPromptableHostServices())->toBe([]);
});

test('all HasHosts implementers conform to the new contract', function () {
    // Catches future enums that implement HasHosts but forget getHostServices.
    $implementers = [
        LaravelFeature::REVERB,
        LaravelFeature::MAILPIT,
        ScoutDriver::DATABASE,
        StorageDriver::GARAGE,
        DatabaseDriver::SQLITE,
        CacheDriver::DATABASE,
        ServerVariation::FPM_NGINX,
        Blueprint::FILAMENT,
    ];

    foreach ($implementers as $component) {
        expect($component)->toBeInstanceOf(HasHosts::class);
        expect($component->getHostServices())->toBeArray();
    }
});
