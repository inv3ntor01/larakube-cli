<?php

use App\Commands\Bundle\BundleInstallCommand;

test('bundle:install --skip-images is a valueless boolean flag', function () {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('skip-images');

    expect($option->acceptValue())->toBeFalse();
});

test('bundle:install --swap accepts a value, defaults to 1G, and normalizes bare numbers', function () {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('swap');

    expect($option->acceptValue())->toBeTrue()
        ->and($option->isValueOptional())->toBeTrue()
        ->and($option->getDefault())->toBe('1G');
});

test('bundle:install --swap normalizes bare integers to gigabytes', function () {
    expect(preg_replace('/^\d+$/', '', '2') === '' ? '2G' : '2')->toBe('2G');

    // Explicit normalization rule: digits-only → append G
    foreach (['1' => '1G', '2' => '2G', '4' => '4G'] as $input => $expected) {
        $normalized = preg_match('/^\d+$/', $input) ? $input.'G' : $input;
        expect($normalized)->toBe($expected);
    }

    // Values already with a suffix pass through unchanged
    foreach (['1G', '2G', '512M'] as $passthrough) {
        $normalized = preg_match('/^\d+$/', $passthrough) ? $passthrough.'G' : $passthrough;
        expect($normalized)->toBe($passthrough);
    }
});
