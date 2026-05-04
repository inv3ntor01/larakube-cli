<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasDependencies
{
    /**
     * Get the list of components this feature must wait for.
     *
     * @param  ConfigData  $config  The project configuration.
     * @return AsDependency[]
     */
    public function getDependencies(ConfigData $config): array;
}
