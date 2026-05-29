<?php

namespace App\Traits;

use function Laravel\Prompts\select;

trait InteractsWithEnvironments
{
    /**
     * Get the available environments for this project. Sole source of truth
     * is `.larakube.json` — so if the user renames "production" to "main" or
     * adds a "qa" env, `larakube heal` is the only step needed; no command
     * code references hardcoded env names. Falls back to the conventional
     * pair only when no project config exists yet (fresh init).
     */
    protected function getAvailableEnvironments(): array
    {
        $projectPath = getcwd();
        $config = method_exists($this, 'getProjectConfigObject')
            ? $this->getProjectConfigObject($projectPath)
            : null;

        $envs = $config?->getEnvironments() ?? [];

        return ! empty($envs) ? $envs : ['local', 'production'];
    }

    /**
     * Get the available environments excluding 'local'. Used by cloud
     * commands that, by definition, don't operate on the local cluster.
     */
    protected function getCloudEnvironments(): array
    {
        return array_values(array_filter(
            $this->getAvailableEnvironments(),
            fn (string $env) => $env !== 'local'
        ));
    }

    /**
     * Prompt the user to select an environment.
     */
    protected function askForEnvironment(string $label = 'Which environment would you like to target?', string $default = 'local'): string
    {
        return select(
            label: $label,
            options: $this->getAvailableEnvironments(),
            default: $default
        );
    }

    /**
     * Prompt for a non-local environment. Used by cloud/gha commands where
     * targeting 'local' makes no sense. Auto-selects when only one cloud
     * env exists; falls back to the first available env when there are none
     * (defensive — shouldn't happen in practice).
     */
    protected function askForCloudEnvironment(string $label = 'Which environment would you like to target?'): string
    {
        $envs = $this->getCloudEnvironments();

        if (count($envs) === 1) {
            return $envs[0];
        }

        if (empty($envs)) {
            return $this->askForEnvironment($label);
        }

        return select(
            label: $label,
            options: $envs,
            default: $envs[0],
        );
    }

    /**
     * Get the Kubernetes namespace for a given environment.
     */
    protected function getNamespace(string $environment, ?string $appName = null): string
    {
        // Use current working directory if appName not provided
        $projectPath = getcwd();

        // Load config
        $config = method_exists($this, 'getProjectConfigObject')
            ? $this->getProjectConfigObject($projectPath)
            : null;

        $appName = $appName ?? $config?->getName() ?? basename($projectPath);

        return "$appName-$environment";
    }
}
