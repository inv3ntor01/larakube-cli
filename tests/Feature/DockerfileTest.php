<?php

use App\Data\ConfigData;

function renderDockerfile(array $overrides = []): string
{
    $config = ConfigData::from(array_merge([
        'name' => 'docktest',
        'serverVariation' => 'frankenphp',
        'phpVersion' => '8.4',
        'os' => 'alpine',
    ], $overrides));

    return view('docker.php', ['config' => $config])->render();
}

test('Node is installed in the base stage when SSR is enabled', function () {
    $dockerfile = renderDockerfile(['features' => ['ssr']]);

    expect($dockerfile)
        ->toContain('apk add --no-cache nodejs npm')
        // It must be in `base` (before the development stage) so deploy inherits it.
        ->and(strpos($dockerfile, 'apk add --no-cache nodejs npm'))
        ->toBeLessThan(strpos($dockerfile, 'AS development'));
});

test('Node is omitted entirely when SSR is not used', function () {
    $dockerfile = renderDockerfile(['features' => ['horizon', 'queues']]);

    expect($dockerfile)->not->toContain('nodejs npm');
});

test('chokidar is never installed', function () {
    expect(renderDockerfile(['features' => ['ssr']]))->not->toContain('chokidar');
});
