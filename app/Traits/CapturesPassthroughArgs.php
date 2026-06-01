<?php

namespace App\Traits;

trait CapturesPassthroughArgs
{
    /**
     * Detect whether an in-pod command line is invoking a PHP test runner.
     * Used by proxy commands (art, php, exec) to redirect through
     * `larakube test`, which strips DB env vars and defaults to in-memory
     * SQLite — preventing RefreshDatabase from wiping the dev DB.
     *
     * Recognizes:
     *   - `vendor/bin/pest`, `vendor/bin/phpunit` (with optional `./` prefix)
     *   - `artisan test` subcommand
     *
     * Deliberately does NOT match:
     *   - `composer require pest` (package install, not test run)
     *   - `phpunit-watcher` (different binary)
     *   - `artisan test:install` / other `test:*` subcommands
     */
    public static function looksLikeTestRunner(string $command): bool
    {
        // Strip the shell-escape quotes that capturePassthroughArgs added so
        // the regex sees the bare command tokens.
        $stripped = str_replace(["'", '"'], '', $command);

        if (preg_match('#(?:^|\s)\.{0,2}/?vendor/bin/(?:pest|phpunit)(?:\s|$)#', $stripped)) {
            return true;
        }

        if (preg_match('/\bartisan\s+test(?:\s|$)/', $stripped)) {
            return true;
        }

        return false;
    }

    /**
     * Parse $_SERVER['argv'] for a passthrough command's trailing args.
     *
     * Filters out the listed larakube-specific options (capturing their values
     * for the caller) and rehydrates the `__LARAKUBE_PASSTHROUGH_HELP__`
     * placeholder that the `larakube` entry script injects in place of
     * `--help`/`-h` so Symfony Console doesn't intercept it.
     *
     * @param  string|array<int, string>  $triggers  Command name(s) to locate in argv (e.g. 'art', or ['art', 'artisan']).
     * @param  array<int, string>  $knownOptions  Long option names (no '--' prefix) whose `--name=value` form should be consumed and surfaced via the returned `options` map. Each defaults to the value of $this->option($name), so callers can also use Laravel's option binding.
     * @return array{command: string, options: array<string, string|null>}
     */
    protected function capturePassthroughArgs(string|array $triggers, array $knownOptions = ['environment']): array
    {
        $rawArgs = $_SERVER['argv'] ?? [];
        $triggers = (array) $triggers;

        $cmdIndex = false;
        foreach ($triggers as $trigger) {
            $cmdIndex = array_search($trigger, $rawArgs, true);
            if ($cmdIndex !== false) {
                break;
            }
        }

        $options = [];
        foreach ($knownOptions as $name) {
            $options[$name] = $this->option($name);
        }

        if ($cmdIndex === false) {
            // Fallback to Laravel's {commands*} positional argument binding.
            $commands = (array) ($this->argument('commands') ?? []);

            return ['command' => implode(' ', $commands), 'options' => $options];
        }

        $passedArgs = array_slice($rawArgs, $cmdIndex + 1);
        $commands = [];

        foreach ($passedArgs as $arg) {
            $consumed = false;
            foreach ($knownOptions as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $options[$name] = substr($arg, strlen($prefix));
                    $consumed = true;
                    break;
                }
            }
            if ($consumed) {
                continue;
            }

            if ($arg === '__LARAKUBE_PASSTHROUGH_HELP__') {
                $arg = '--help';
            }

            $commands[] = $arg;
        }

        // Shell-escape each arg before joining. The resulting string flows
        // through PHP → host shell → kubectl exec → pod's `sh -c "..."`. Args
        // containing shell metacharacters (parens, semicolons, dollar signs,
        // spaces, etc.) — common in tinker --execute, complex composer/npm
        // invocations — would otherwise be reinterpreted by the inner shell.
        // escapeshellarg wraps each in single quotes; quoting is a no-op for
        // simple args and a correctness fix for everything else.
        return [
            'command' => implode(' ', array_map('escapeshellarg', $commands)),
            'options' => $options,
        ];
    }

    /**
     * Re-dispatch the current invocation through `larakube test`, forwarding
     * known TestCommand options if they appeared in the original argv. Other
     * args (--filter, --testsuite, --coverage, etc.) are picked up by
     * TestCommand's own argv parsing and forwarded to phpunit/pest.
     */
    protected function delegateToTestCommand(): int
    {
        $this->line('  <fg=cyan>↳ Routing through `larakube test` so your dev DB is safe.</>');
        $this->newLine();

        $callOptions = [];
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === '--db' || $arg === '--with-db') {
                $callOptions['--db'] = true;

                continue;
            }
            if (str_starts_with($arg, '--environment=')) {
                $callOptions['--environment'] = substr($arg, strlen('--environment='));
            }
        }

        return $this->call('test', $callOptions);
    }
}
