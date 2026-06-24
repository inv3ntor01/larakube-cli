<?php

use App\Commands\Config\ConfigTldCommand;
use App\Data\GlobalConfigData;

test('GlobalConfigData defaults to kube TLD', function () {
    $config = new GlobalConfigData;

    expect($config->getLocalTld())->toBe('kube');
});

test('GlobalConfigData setLocalTld strips leading dot and lowercases', function () {
    $config = new GlobalConfigData;

    $config->setLocalTld('.LOCALHOST');

    expect($config->getLocalTld())->toBe('localhost');
});

test('GlobalConfigData setLocalTld trims whitespace', function () {
    $config = new GlobalConfigData;

    $config->setLocalTld('  test  ');

    expect($config->getLocalTld())->toBe('test');
});

test('GlobalConfigData from() reads localTld', function () {
    $config = GlobalConfigData::from(['localTld' => 'localhost']);

    expect($config->getLocalTld())->toBe('localhost');
});

test('GlobalConfigData toArray writes localTld', function () {
    $config = new GlobalConfigData;
    $config->setLocalTld('test');

    expect($config->toArray()['localTld'])->toBe('test');
});

test('GlobalConfigData from() falls back to kube when localTld absent', function () {
    $config = GlobalConfigData::from([]);

    expect($config->getLocalTld())->toBe(GlobalConfigData::DEFAULT_TLD);
});

test('config:tld command has a tld argument that defaults to null', function () {
    $definition = (new ConfigTldCommand)->getDefinition();

    expect($definition->hasArgument('tld'))->toBeTrue()
        ->and($definition->getArgument('tld')->isRequired())->toBeFalse();
});

test('ALLOWED_TLDS contains kube and localhost', function () {
    expect(GlobalConfigData::ALLOWED_TLDS)
        ->toContain('kube')
        ->toContain('localhost')
        ->toContain('test');
});
