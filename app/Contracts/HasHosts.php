<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasHosts
{
    /**
     * Resolved host URLs for the component in the given environment.
     * Typically derived from getHostServices() via $config->getServiceHost()
     * (the DerivesHostsFromServices trait provides this canonical impl);
     * components that publish env-gated, non-overrideable hosts (e.g.
     * local-only database consoles) override directly.
     *
     * @return array<string, string> Key: resolved host, Value: human label.
     */
    public function getHosts(ConfigData $config, string $environment = 'local'): array;

    /**
     * Declarative list of services this component exposes for ingress that
     * users can override on a per-environment basis (via
     * EnvironmentData::$hosts). The wizard iterates this map to ask
     * "do you want a custom subdomain for {label} in {env}?".
     *
     * Local-only consoles (database UIs, Mailpit, etc.) typically return []
     * here even when they publish hosts in getHosts() — they're baked into
     * the local .kube domains and aren't meaningful in cloud envs.
     *
     * @return array<string, string> Key: service name, Value: human label.
     */
    public function getHostServices(): array;
}
