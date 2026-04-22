<?php

namespace App\Actions\Contracts;

interface DatabaseAction
{
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
