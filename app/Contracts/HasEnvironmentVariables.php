<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasEnvironmentVariables
{
    /**
     * Get the environment variables for the component.
     *
     * @param  ConfigData|null  $config  The project configuration.
     * @return array<string, string>
     */
    public function getEnvironmentVariables(?ConfigData $config = null): array;
}
