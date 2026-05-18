<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasHosts
{
    /**
     * Get the host URLs for the component.
     *
     * @param  ConfigData  $config  The project configuration.
     * @param  string  $environment  The environment (local, production).
     * @return array<string, string> Key: Host URL, Value: Description or Type.
     */
    public function getHosts(ConfigData $config, string $environment = 'local'): array;
}
