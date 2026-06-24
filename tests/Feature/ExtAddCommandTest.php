<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Artisan;

/**
 * Spin up a throwaway LaraKube project (.larakube.json + optional Dockerfile.php),
 * chdir into it (ext:add resolves the project from getcwd()), run the callback,
 * then always restore the original cwd and delete the temp dir.
 */
function extAddInProject(?string $dockerfile, callable $fn): void
{
    $dir = sys_get_temp_dir().'/ext-add-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.larakube.json', json_encode(['name' => 'demo']));
    if ($dockerfile !== null) {
        file_put_contents($dir.'/Dockerfile.php', $dockerfile);
    }

    $original = getcwd();
    chdir($dir);

    try {
        $fn($dir);
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
}

test('ext:add writes the extension to .larakube.json', function () {
    extAddInProject("FROM php:8.5\nRUN install-php-extensions gd\n", function (string $dir) {
        $code = Artisan::call('ext:add', ['extension' => 'imagick']);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getAdditionalExtensions())->toContain('imagick');
    });
});

test('ext:add appends to the existing install-php-extensions line, preserving prior extensions', function () {
    extAddInProject("FROM php:8.5\nRUN install-php-extensions gd\n", function (string $dir) {
        Artisan::call('ext:add', ['extension' => 'imagick']);

        $dockerfile = file_get_contents($dir.'/Dockerfile.php');
        expect($dockerfile)->toContain('RUN install-php-extensions gd imagick');
    });
});

test('ext:add is idempotent — re-adding does not duplicate the extension', function () {
    extAddInProject("FROM php:8.5\nRUN install-php-extensions gd\n", function (string $dir) {
        Artisan::call('ext:add', ['extension' => 'imagick']);
        Artisan::call('ext:add', ['extension' => 'imagick']);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getAdditionalExtensions())->toBe(['imagick'])
            ->and(substr_count((string) file_get_contents($dir.'/Dockerfile.php'), 'imagick'))->toBe(1);
    });
});

test('ext:add warns when Dockerfile.php has no install-php-extensions line', function () {
    extAddInProject("FROM php:8.5\n", function (string $dir) {
        $code = Artisan::call('ext:add', ['extension' => 'imagick']);
        $output = Artisan::output();

        // Config is still updated; only the Dockerfile rewrite is skipped with a warning.
        expect($code)->toBe(0)
            ->and(ConfigData::loadFromFile($dir)->getAdditionalExtensions())->toContain('imagick')
            ->and($output)->toContain('install-php-extensions');
    });
});

test('ext:add fails cleanly outside a LaraKube project', function () {
    $dir = sys_get_temp_dir().'/ext-add-noproject-'.uniqid();
    mkdir($dir, 0755, true);
    $original = getcwd();
    chdir($dir);

    try {
        $code = Artisan::call('ext:add', ['extension' => 'imagick']);
        expect($code)->toBe(1);
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
});
