<?php

namespace App\Traits;

/**
 * Let the `cluster:*` commands accept an ENVIRONMENT name (e.g. "production") and
 * expand it to that env's namespace (`{name}-{env}`, honoring any override) when
 * run inside a project — matching the other env-aware commands (cloud:deploy,
 * plex:join, …). A value that isn't one of the project's environments is treated
 * as a literal namespace, so an admin can still target any namespace from outside
 * a project.
 *
 * The using class must also use InteractsWithProjectConfig.
 */
trait ResolvesNamespaceArg
{
    protected function resolveNamespaceArg(string $value): string
    {
        $config = $this->getProjectConfig(getcwd());

        return $config !== null && $config->getEnvironment($value) !== null
            ? $config->getNamespace($value)
            : $value;
    }
}
