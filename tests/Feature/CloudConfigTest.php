<?php

use App\Data\CloudData;
use App\Data\ConfigData;

test('setCloud stores connection config on the environment and getters read it back', function () {
    $config = new ConfigData(name: 'cloudy');

    $config->setCloud('production', [
        'ip' => '203.0.113.10',
        'user' => 'deploy',
        'port' => 2222,
        'key' => '/home/me/.ssh/prod',
    ]);

    // It lives on the environment, not a detached top-level map.
    expect($config->getEnvironment('production')?->cloud)->toBeInstanceOf(CloudData::class);

    expect($config->getCloudIp('production'))->toBe('203.0.113.10')
        ->and($config->getCloudUser('production'))->toBe('deploy')
        ->and($config->getCloudPort('production'))->toBe(2222)
        ->and($config->getCloudKey('production'))->toBe('/home/me/.ssh/prod');
});

test('getCloud helpers return sensible defaults for an env with no cloud config', function () {
    $config = new ConfigData(name: 'bare');

    expect($config->getCloud('production'))->toBeNull()
        ->and($config->getCloudIp('production'))->toBeNull()
        ->and($config->getCloudUser('production'))->toBe('larakube')
        ->and($config->getCloudPort('production'))->toBe(22)
        ->and($config->getCloudConfig('production'))->toBe([]);
});

test('legacy top-level cloud map is migrated into per-env cloud on load', function () {
    $config = ConfigData::from([
        'name' => 'legacy',
        'environments' => ['local' => [], 'production' => [], 'staging' => []],
        'cloud' => [
            'production' => ['ip' => '198.51.100.5', 'user' => 'larakube', 'port' => 22, 'key' => '/k'],
            'staging' => ['ip' => '198.51.100.6'],
        ],
    ]);

    // Connection config landed on each environment.
    expect($config->getCloudIp('production'))->toBe('198.51.100.5')
        ->and($config->getCloudIp('staging'))->toBe('198.51.100.6');

    // Top-level legacy field is cleared.
    expect($config->cloud)->toBe([]);
});

test('cloud config round-trips through saveToFile in the new per-env shape', function () {
    $dir = sys_get_temp_dir().'/larakube-cloud-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    // Build from a legacy blueprint, then persist.
    $config = ConfigData::from([
        'name' => 'roundtrip',
        'environments' => ['local' => [], 'production' => []],
        'cloud' => [
            'production' => ['ip' => '203.0.113.99', 'user' => 'deploy', 'port' => 22, 'key' => '/k'],
        ],
    ]);
    $config->saveToFile($dir);

    $raw = json_decode(file_get_contents($dir.'/'.ConfigData::CONFIG_FILE), true);

    // No legacy top-level cloud key on disk; config lives under the environment.
    expect($raw)->not->toHaveKey('cloud');
    expect($raw['environments']['production']['cloud']['ip'])->toBe('203.0.113.99');

    // Reloading yields the same resolved values.
    $reloaded = ConfigData::loadFromFile($dir);
    expect($reloaded->getCloudIp('production'))->toBe('203.0.113.99')
        ->and($reloaded->getCloudUser('production'))->toBe('deploy');

    // cleanup
    @unlink($dir.'/'.ConfigData::CONFIG_FILE);
    @rmdir($dir);
});
