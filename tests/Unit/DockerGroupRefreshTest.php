<?php

/**
 * dockerGroupNeedsRefresh() shells out to `getent`/`id` rather than the posix
 * extension (not compiled into the standalone binary — see hostUid()). The
 * shared App\Traits\shell_exec mock (declared in ClusterContextTest.php)
 * lets us fake per-command output here via mock_shell_exec_callback, so both
 * branches are testable without touching real group membership.
 */

use App\Traits\InteractsWithDocker;

function dockerGroupHarness(): object
{
    return new class
    {
        use InteractsWithDocker;

        public function check(): bool
        {
            return $this->dockerGroupNeedsRefresh();
        }
    };
}

/**
 * Route each shell_exec call to canned output based on which command it is.
 *
 * @param  array<string, string|null>  $responses  keys: 'getent', 'un', 'gn', 'Gn'
 */
function mockDockerGroupCommands(array $responses): void
{
    $GLOBALS['mock_shell_exec_callback'] = function (string $command) use ($responses) {
        if (str_starts_with($command, 'getent group docker')) {
            return $responses['getent'] ?? null;
        }
        if (str_starts_with($command, 'id -un')) {
            return $responses['un'] ?? null;
        }
        if (str_starts_with($command, 'id -gn')) {
            return $responses['gn'] ?? null;
        }
        if (str_starts_with($command, 'id -Gn')) {
            return $responses['Gn'] ?? null;
        }

        return null;
    };
}

afterEach(function () {
    unset($GLOBALS['mock_shell_exec_callback']);
});

test('dockerGroupNeedsRefresh is true when the user is a member but docker is missing from the active session', function () {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n", // docker missing here — stale session
    ]);

    expect(dockerGroupHarness()->check())->toBeTrue();
});

test('dockerGroupNeedsRefresh is false when docker is already active in this session', function () {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo docker\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is true via primary group membership even when not listed as a secondary member', function () {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:\n", // no secondary members listed
        'un' => "james\n",
        'gn' => "docker\n", // but docker IS this user's primary group
        'Gn' => "james\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeTrue();
});

test('dockerGroupNeedsRefresh is false when the user is not a docker group member at all', function () {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:someoneelse\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is false when there is no docker group on this system', function () {
    mockDockerGroupCommands([
        'getent' => null,
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is false when id -un fails to resolve a user', function () {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => null,
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});
