<?php

namespace App\Traits;

use App\Contracts\HasPromptableHosts;

use function Laravel\Prompts\text;

/**
 * Shared host wizard: prompt for an environment's client-facing hosts — the
 * optional web host plus any HasPromptableHosts service overrides (Reverb's
 * WebSocket host, an object-storage S3/CDN host) exposed by the env's components.
 * Extracted so `env`, the air-gapped bundle installer, and any future flow reuse
 * one prompt instead of re-implementing it. Admin consoles (search dashboards,
 * Mailpit, metrics) are intentionally NOT prompted — they get a derived ingress
 * host and stay editable by hand in .larakube.json.
 */
trait PromptsForHosts
{
    /**
     * @param  iterable<object>  $components  the env's resolved components
     * @return array<string, string> [service => host] for values entered (blanks omitted)
     */
    protected function promptForHosts(string $envName, iterable $components, ?string $webDefault = null): array
    {
        $hosts = [];

        // Web host: optional. Empty = no host configured (env still works on internal .kube domains).
        $webHost = text(
            label: "Web host for {$envName} (optional, e.g. staging.example.com)",
            placeholder: 'leave blank to skip',
            default: $webDefault ?? '',
            required: false,
        );
        if ($webHost !== '') {
            $hosts['web'] = $webHost;
        }

        // Per-service overrides — only genuinely client-facing endpoints worth a
        // vanity subdomain (Reverb WS host, an object-storage S3/CDN host).
        foreach ($components as $component) {
            if (! $component instanceof HasPromptableHosts) {
                continue;
            }
            foreach ($component->getPromptableHostServices() as $service => $label) {
                $override = text(
                    label: "Custom host for {$label} in {$envName} (optional)",
                    placeholder: 'leave blank to derive from web host',
                    required: false,
                );
                if ($override !== '') {
                    $hosts[$service] = $override;
                }
            }
        }

        return $hosts;
    }
}
