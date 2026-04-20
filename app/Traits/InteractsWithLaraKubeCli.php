<?php

namespace App\Traits;

trait InteractsWithLaraKubeCli
{
    /**
     * Get the resolved LaraKube binary path for the current environment.
     */
    protected function getLaraKubeBinary(): string
    {
        // 1. If we are in the development container, use the source path
        if (file_exists('/larakube/larakube')) {
            return 'php /larakube/larakube';
        }

        // 2. Use the current executing binary path (Self-referential)
        // This ensures the standalone binary (e.g. /usr/local/bin/larakube) calls itself correctly.
        $self = $_SERVER['argv'][0] ?? 'larakube';

        // If it's a relative path, try to make it absolute for reliability
        if (file_exists($self)) {
            return realpath($self);
        }

        return $self;
    }

    /**
     * Get the raw list of all available LaraKube commands.
     */
    protected function listCliCommands(): string
    {
        $bin = $this->getLaraKubeBinary();

        return shell_exec("{$bin} list --raw") ?? '';
    }

    /**
     * Get the help output for a specific command.
     */
    protected function getCliCommandHelp(string $command): string
    {
        $bin = $this->getLaraKubeBinary();

        return shell_exec("{$bin} help {$command}") ?? "No help found for command: {$command}";
    }

    /**
     * Execute a LaraKube command with built-in safety and automation flags.
     */
    protected function executeCliCommand(string $command): array
    {
        $bin = $this->getLaraKubeBinary();

        // Security: Remove 'larakube' prefix if the AI included it, we will add it back correctly
        $command = preg_replace('/^larakube\s+/', '', $command);

        $finalCommand = "{$bin} {$command}";

        // Add non-interactive flags automatically
        if (! str_contains($finalCommand, '--no-interaction')) {
            $finalCommand .= ' --no-interaction';
        }

        // Force destruction for safety/automation
        if (str_contains($finalCommand, ' down') && ! str_contains($finalCommand, '--force')) {
            $finalCommand .= ' --force';
        }

        exec($finalCommand, $output, $resultCode);

        return [
            'command' => $finalCommand,
            'output' => implode("\n", $output),
            'exit_code' => $resultCode,
            'success' => $resultCode === 0,
        ];
    }
}
