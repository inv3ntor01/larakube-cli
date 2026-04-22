<?php

namespace App\Actions\Contracts;

interface FeatureAction
{
    /**
     * Get the list of shell commands to run inside the container for installation.
     */
    public function getInstallCommands(array $context = []): array;

    /**
     * Handle any host-side tasks after the container commands have finished (e.g., updating .env).
     */
    public function onPostInstall(string $projectPath, array $context = []): void;

    public function updateK8s(string $k8sPath, string $appName, array $context = []): void;

    public function updateDockerCompose(string $projectPath, array $context = []): void;

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
