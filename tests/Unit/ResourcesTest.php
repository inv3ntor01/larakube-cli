<?php

use App\Data\ConfigData;

test('getResources merges code default <- env default <- component override', function () {
    $config = ConfigData::from([
        'name' => 'res-test',
        'environments' => [
            'production' => [
                'resources' => [
                    'default' => ['requests' => ['memory' => '512Mi'], 'limits' => ['memory' => '1Gi']],
                    'horizon' => ['limits' => ['memory' => '2Gi']],
                ],
            ],
        ],
    ]);

    // web: code-default cpu, env-default memory.
    expect($config->getResources('production', 'web'))->toBe([
        'requests' => ['cpu' => '100m', 'memory' => '512Mi'],
        'limits' => ['cpu' => '1', 'memory' => '1Gi'],
    ]);

    // horizon: inherits the env default, overrides only its memory limit.
    expect($config->getResources('production', 'horizon'))->toBe([
        'requests' => ['cpu' => '100m', 'memory' => '512Mi'],
        'limits' => ['cpu' => '1', 'memory' => '2Gi'],
    ]);

    // An env with no resources key → pure code default.
    $bare = ConfigData::from(['name' => 'x', 'environments' => ['production' => []]]);
    expect($bare->getResources('production', 'web'))->toBe(ConfigData::DEFAULT_RESOURCES);
});

test('isValidQuantity accepts k8s quantities and rejects typos', function () {
    expect(ConfigData::isValidQuantity('100m'))->toBeTrue()
        ->and(ConfigData::isValidQuantity('1'))->toBeTrue()
        ->and(ConfigData::isValidQuantity('1.5'))->toBeTrue()
        ->and(ConfigData::isValidQuantity('256Mi'))->toBeTrue()
        ->and(ConfigData::isValidQuantity('1Gi'))->toBeTrue()
        ->and(ConfigData::isValidQuantity('1gb'))->toBeFalse()
        ->and(ConfigData::isValidQuantity('abc'))->toBeFalse()
        ->and(ConfigData::isValidQuantity(''))->toBeFalse();
});
