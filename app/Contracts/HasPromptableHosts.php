<?php

namespace App\Contracts;

/**
 * Marks a component that exposes one or more services worth offering a
 * per-environment custom-hostname prompt for in the `larakube env` wizard.
 *
 * This is deliberately narrower than HasHosts::getHostServices(): that method
 * declares every ingress host a component publishes (including dev consoles
 * and dashboards), whereas this one is only the client-facing endpoints a
 * user realistically gives a vanity subdomain — the Reverb WebSocket host
 * (ws.example.com), an object-storage S3/CDN host (cdn.example.com), and the
 * like.
 *
 * Admin consoles (search dashboards, Mailpit, metrics) intentionally do NOT
 * implement this: they still get a derived ingress host via getHosts(), but
 * the wizard won't pester the user to name them. They remain editable by hand
 * in .larakube.json if a custom host is ever wanted.
 */
interface HasPromptableHosts
{
    /**
     * Client-facing services worth a per-env custom-host prompt.
     *
     * @return array<string, string> service value => human label
     */
    public function getPromptableHostServices(): array;
}
