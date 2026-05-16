<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasLifecycleHooks
{
    public function onPostInstall(string $projectPath, ?ConfigData $context = null): void;

    /**
     * @return string[]
     */
    public function getPostInstallInstructions(?ConfigData $config = null): array;
}
