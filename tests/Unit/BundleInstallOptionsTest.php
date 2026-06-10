<?php

use App\Commands\Bundle\BundleInstallCommand;

test('bundle:install --skip-images is a valueless boolean flag', function () {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('skip-images');

    expect($option->acceptValue())->toBeFalse();
});

test('bundle:install --swap accepts a value (is not a boolean flag)', function () {
    $option = (new BundleInstallCommand)->getDefinition()->getOption('swap');

    expect($option->acceptValue())->toBeTrue()
        ->and($option->isValueOptional())->toBeTrue();
});
