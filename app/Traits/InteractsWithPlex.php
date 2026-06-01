<?php

namespace App\Traits;

/**
 * Shared helpers for the Plex feature — the multi-tenant "Commons" (shared
 * Postgres/Redis/Meili) that several LaraKube projects join.
 *
 * The Commons is cluster-owned and self-describing: its spec lives in a
 * `plex-commons` ConfigMap in the `larakube-shared` namespace, so these helpers
 * read truth from the cluster rather than any repo. The spec-shaping helpers are
 * pure (no I/O) so they can be unit-tested.
 */
trait InteractsWithPlex
{
    /**
     * The default Commons spec. Postgres + Redis are always on (the $12/2GB
     * sweet spot); Meilisearch is opt-in (it's the RAM hog). Pure.
     */
    public function defaultCommonsSpec(bool $withMeili = false): array
    {
        return $this->normalizeCommonsSpec([
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'meili' => ['enabled' => $withMeili],
            ],
        ]);
    }

    /**
     * Fill a (possibly partial or imported) spec with defaults and a stable
     * shape, so the manifest renderer and `plex:export` always see complete
     * values and a round-trip (export → init --from) is lossless. Pure.
     */
    public function normalizeCommonsSpec(array $spec): array
    {
        $defaults = [
            'postgres' => ['image' => 'postgres:17.9', 'port' => 5432, 'storage' => '10Gi'],
            'redis' => ['image' => 'redis:7.4', 'port' => 6379],
            'meili' => ['image' => 'getmeili/meilisearch:v1.10', 'port' => 7700, 'storage' => '5Gi'],
        ];

        $given = $spec['services'] ?? [];
        $resolved = [];

        foreach ($defaults as $name => $default) {
            $service = is_array($given[$name] ?? null) ? $given[$name] : [];
            $resolved[$name] = array_merge($default, $service);

            // Postgres + Redis default-on; Meili default-off — unless the spec
            // says otherwise explicitly.
            $resolved[$name]['enabled'] = (bool) ($service['enabled']
                ?? in_array($name, ['postgres', 'redis'], true));
        }

        return [
            'version' => $spec['version'] ?? 1,
            'services' => $resolved,
        ];
    }

    /**
     * The names of the Commons services that are enabled. Pure.
     *
     * @return array<int, string>
     */
    public function enabledCommonsServices(array $spec): array
    {
        return array_keys(array_filter(
            $spec['services'] ?? [],
            fn ($service) => (bool) ($service['enabled'] ?? false),
        ));
    }

    /**
     * The namespace that hosts the shared Commons services.
     */
    protected function plexNamespace(): string
    {
        return 'larakube-shared';
    }

    /**
     * Read the live Commons spec from the cluster, or null if the Commons has
     * not been initialized (no `plex-commons` ConfigMap).
     */
    protected function getCommonsSpec(): ?array
    {
        $ns = $this->plexNamespace();
        $json = trim((string) shell_exec(
            "kubectl get configmap plex-commons -n {$ns} -o jsonpath='{.data.commons\\.json}' 2>/dev/null",
        ));

        if ($json === '') {
            return null;
        }

        $spec = json_decode($json, true);

        return is_array($spec) ? $spec : null;
    }
}
