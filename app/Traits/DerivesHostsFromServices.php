<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Canonical implementation of HasHosts::getHosts() for components whose
 * published hosts always equal their overrideable services. Each service
 * name from getHostServices() is resolved through ConfigData::getServiceHost,
 * which honours per-env explicit overrides (EnvironmentData::$hosts[$service])
 * and falls back to the appropriate prefix pattern.
 *
 * Use this trait on every HasHosts implementer where local-vs-cloud behaviour
 * differs only by host pattern, not by which services exist. Components that
 * need to suppress hosts in some envs (e.g. database consoles that should
 * only ever publish in local) should define getHosts() themselves and skip
 * this trait.
 */
trait DerivesHostsFromServices
{
    public function getHosts(ConfigData $config, string $environment = 'local'): array
    {
        $hosts = [];
        foreach ($this->getHostServices() as $service => $label) {
            $hosts[$config->getServiceHost($service, $environment)] = $label;
        }

        return $hosts;
    }
}
