<?php

use Illuminate\Support\Facades\Http;

test('update command detects if version is up to date', function () {
    config(['app.version' => 'v0.2.0']);

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([
            'tag_name' => 'v0.2.0',
        ], 200),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('Current version:')
        ->expectsOutputToContain('Checking for latest version...')
        ->expectsOutputToContain('You are already using the latest version!')
        ->assertExitCode(0);
});

test('update command handles update availability and cancellation', function () {
    config(['app.version' => 'v0.1.0']);

    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([
            'tag_name' => 'v0.2.0',
        ], 200),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('A new version is available:')
        ->expectsConfirmation('Do you want to update now?', 'no')
        ->assertExitCode(0);
});

test('update command fails gracefully on GitHub API failure', function () {
    Http::fake([
        'api.github.com/repos/luchavez-technologies/larakube-cli/releases/latest' => Http::response([], 500),
    ]);

    $this->artisan('update')
        ->expectsOutputToContain('Failed to fetch the latest version from GitHub.')
        ->assertExitCode(1);
});
