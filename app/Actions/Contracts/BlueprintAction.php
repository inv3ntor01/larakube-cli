<?php

namespace App\Actions\Contracts;

interface BlueprintAction
{
    /**
     * Gather blueprint-specific configuration from the user.
     */
    public function gatherConfig(): array;

    /**
     * Apply the blueprint to the project.
     */
    public function apply(string $projectPath, string $k8sPath, string $appName, array $context = []): void;

    /**
     * Get the installation commands for the blueprint.
     */
    public function getInstallCommands(array $context = []): array;

    /**
     * Get the required PHP extensions for the blueprint.
     */
    public function getPhpExtensions(): array;

    /**
     * Get any post-installation instructions for the user.
     */
    public function getPostInstallInstructions(): array;

    /**
     * Get the categorized list of manifest files to register.
     *
     * return [
     *   'base' => ['file1.yaml'],
     *   'local' => ['file2.yaml'],
     *   'patches' => ['patch1.yaml']
     * ]
     */
    public function getManifestFiles(): array;
}
