<?php

use Laravel\Prompts\Prompt;

function portableProject(): string
{
    $tmp = sys_get_temp_dir().'/larakube-portable-'.uniqid();
    mkdir($tmp, 0755, true);
    file_put_contents("$tmp/.larakube.json", json_encode(['name' => 'demo']));

    return $tmp;
}

test('portable command writes the wrapper script and guide', function () {
    $original = getcwd();
    $tmp = portableProject();
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true])->assertExitCode(0);

        expect(file_exists("$tmp/larakube.sh"))->toBeTrue()
            ->and(file_exists("$tmp/LOCAL_DEV.md"))->toBeTrue()
            ->and(is_executable("$tmp/larakube.sh"))->toBeTrue();

        $script = file_get_contents("$tmp/larakube.sh");
        expect($script)
            ->toContain('cmd_up()')
            ->toContain('cmd_artisan()')
            ->toContain('cmd_watch()')
            ->toContain('jq -r \'.name\' .larakube.json');

        $guide = file_get_contents("$tmp/LOCAL_DEV.md");
        expect($guide)->toContain('Local development without the LaraKube CLI');
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tmp));
    }
});

test('portable command --script-only writes the script but not the guide', function () {
    $original = getcwd();
    $tmp = portableProject();
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true, '--script-only' => true])->assertExitCode(0);

        expect(file_exists("$tmp/larakube.sh"))->toBeTrue()
            ->and(file_exists("$tmp/LOCAL_DEV.md"))->toBeFalse();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tmp));
    }
});

test('portable command keeps an existing file when the user declines', function () {
    $original = getcwd();
    $tmp = portableProject();
    file_put_contents("$tmp/larakube.sh", "# my customized version\n");
    chdir($tmp);

    // Non-interactive: decline the overwrite prompt.
    Prompt::fallbackUsing(fn () => false);

    try {
        $this->artisan('portable')->assertExitCode(0);

        // The customized script is preserved...
        expect(file_get_contents("$tmp/larakube.sh"))->toContain('# my customized version');
        // ...while the missing guide is still written.
        expect(file_exists("$tmp/LOCAL_DEV.md"))->toBeTrue();
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tmp));
    }
});

test('portable command --force overwrites an existing script', function () {
    $original = getcwd();
    $tmp = portableProject();
    file_put_contents("$tmp/larakube.sh", "# stale\n");
    chdir($tmp);

    try {
        $this->artisan('portable', ['--force' => true])->assertExitCode(0);
        expect(file_get_contents("$tmp/larakube.sh"))
            ->not->toContain('# stale')
            ->toContain('cmd_up()');
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($tmp));
    }
});
