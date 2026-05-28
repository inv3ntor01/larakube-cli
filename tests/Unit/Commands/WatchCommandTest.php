<?php

use App\Commands\WatchCommand;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/larakube-watch-'.uniqid();
    mkdir($this->tmp, 0755, true);
    mkdir($this->tmp.'/app', 0755, true);
    file_put_contents($this->tmp.'/app/User.php', '<?php');
    file_put_contents($this->tmp.'/.env', 'APP_ENV=local');
});

afterEach(function () {
    @unlink($this->tmp.'/app/User.php');
    @rmdir($this->tmp.'/app');
    @unlink($this->tmp.'/.env');
    @rmdir($this->tmp);
});

test('computeHash changes when a watched file mtime changes', function () {
    $before = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    touch($this->tmp.'/app/User.php', time() + 100);

    $after = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    expect($before)->not->toBe($after);
});

test('computeHash is stable when nothing changes', function () {
    $first = WatchCommand::computeHash(['app', '.env'], $this->tmp);
    $second = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    expect($first)->toBe($second);
});

test('computeHash silently skips paths that do not exist', function () {
    $hash = WatchCommand::computeHash(['app', 'nonexistent-dir', '.env'], $this->tmp);

    expect($hash)->toBeString()->not->toBeEmpty();
});

test('computeHash recurses into subdirectories', function () {
    mkdir($this->tmp.'/app/Models', 0755, true);
    file_put_contents($this->tmp.'/app/Models/Post.php', '<?php');

    $before = WatchCommand::computeHash(['app'], $this->tmp);

    touch($this->tmp.'/app/Models/Post.php', time() + 100);

    $after = WatchCommand::computeHash(['app'], $this->tmp);

    expect($before)->not->toBe($after);

    @unlink($this->tmp.'/app/Models/Post.php');
    @rmdir($this->tmp.'/app/Models');
});
