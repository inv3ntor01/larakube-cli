<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface AsDependency
{
    /**
     * Get the host(s) and port(s) this dependency exposes.
     *
     * @param  ConfigData  $config  The project configuration.
     * @return array<string, int> Key is host, Value is port.
     */
    public function getDependencyConfig(ConfigData $config): array;
}
