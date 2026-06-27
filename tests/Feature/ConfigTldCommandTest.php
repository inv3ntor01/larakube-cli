<?php

use Illuminate\Support\Facades\Artisan;

function configTldChdir(string $dir): string
{
    $original = getcwd();
    chdir($dir);

    return $original;
}

test('config:tld status shows only the global TLD outside a project', function () {
    $tempDir = sys_get_temp_dir().'/config-tld-noproject-'.uniqid();
    mkdir($tempDir, 0755, true);
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tempDir));
    }

    expect($output)->toContain('Local TLD:')
        ->and($output)->not->toContain("project's TLD");
});

test('config:tld status shows the project TLD following the global default', function () {
    $tempDir = sys_get_temp_dir().'/config-tld-followsglobal-'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/.larakube.json', json_encode(['name' => 'demo']));
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tempDir));
    }

    expect($output)->toContain('Global Local TLD:')
        ->and($output)->toContain("This project's TLD:")
        ->and($output)->toContain('follows the global default');
});

test('config:tld status shows a pinned project TLD override', function () {
    $tempDir = sys_get_temp_dir().'/config-tld-override-'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents($tempDir.'/.larakube.json', json_encode(['name' => 'demo', 'localTld' => 'test']));
    $original = configTldChdir($tempDir);

    try {
        Artisan::call('config:tld');
        $output = Artisan::output();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tempDir));
    }

    expect($output)->toContain("This project's TLD:")
        ->and($output)->toContain('.test')
        ->and($output)->toContain('pinned override');
});
