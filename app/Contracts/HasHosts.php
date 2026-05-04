<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasHosts
{
    /**
     * Get the host URLs for the component.
     *
     * @param  ConfigData  $config  The project configuration.
     * @return array<string, string> Key: Host URL, Value: Description or Type.
     */
    public function getHosts(ConfigData $config): array;
}
