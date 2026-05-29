<?php

use App\Contracts\HasHosts;
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
    // DB and Cache consoles are local-only with baked-in dev.test pattern;
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
    expect($config->getAllHosts('local'))->toHaveKey('mysql-demo.dev.test');

    // Production must NOT include the MySQL console — exposing admin UIs
    // through cloud ingress is a security smell.
    $prodHosts = $config->getAllHosts('production');
    expect(array_keys($prodHosts))->not->toContain('mysql-demo.dev.test')
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
