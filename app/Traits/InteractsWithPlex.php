<?php

namespace App\Traits;

use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;

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
     * Kube-context the plex commands operate against — the environment's OWN
     * context, set by the command (so we never switch the global context). Null
     * means the current context (e.g. plex:init's operator-picked selection).
     */
    protected ?string $plexContext = null;

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
        // Images/ports are derived from the SAME driver enums the rest of LaraKube
        // uses, so the Commons never drifts from the project defaults (e.g. Meili's
        // version stays in lockstep with ScoutDriver instead of a stale literal).
        $defaults = [
            'postgres' => ['image' => DatabaseDriver::POSTGRESQL->getDockerImage(), 'port' => DatabaseDriver::POSTGRESQL->dbPort(), 'storage' => '10Gi'],
            'redis' => ['image' => CacheDriver::REDIS->getDockerImage(), 'port' => CacheDriver::REDIS->dbPort()],
            'meili' => ['image' => ScoutDriver::MEILISEARCH->getDockerImage(), 'port' => ScoutDriver::MEILISEARCH->port(), 'storage' => '5Gi'],
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
     * Turn an app name into a safe SQL identifier reused for the tenant's
     * database AND login role (e.g. "app-one" → "app_one"). Pure.
     */
    public function plexTenantIdentifier(string $appName): string
    {
        $id = strtolower(trim($appName));
        $id = (string) preg_replace('/[^a-z0-9]+/', '_', $id);
        $id = trim($id, '_');

        // SQL identifiers must start with a letter; prefix if not.
        if ($id === '' || ! preg_match('/^[a-z]/', $id)) {
            $id = 'app_'.$id;
        }

        return substr($id, 0, 63); // Postgres identifier length cap.
    }

    /**
     * Pick the lowest free Redis logical-DB index (0..max-1), or null if the
     * Commons Redis is full. Pure.
     *
     * @param  array<int, int>  $used
     */
    public function allocateRedisDbIndex(array $used, int $max = 16): ?int
    {
        for ($i = 0; $i < $max; $i++) {
            if (! in_array($i, $used, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Merge KEY=VALUE pairs into existing .env content — replacing a key in place
     * (even if commented) or appending it. Pure, so it's unit-testable and works
     * for any `.env.{env}` (syncEnvFile only handles .env / .env.production).
     *
     * @param  array<string, int|string>  $values
     */
    public function applyEnvValues(string $content, array $values): string
    {
        $lines = $content === '' ? [] : explode("\n", $content);
        $out = [];
        $done = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($values as $key => $value) {
                if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*/', $line)) {
                    $out[] = "{$key}={$value}";
                    $done[] = $key;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $out[] = $line;
            }
        }

        foreach ($values as $key => $value) {
            if (! in_array($key, $done, true)) {
                $out[] = "{$key}={$value}";
            }
        }

        return implode("\n", $out);
    }

    /**
     * The .env values a tenant needs to reach the Commons. Pure.
     *
     * @param  array<int, string>  $services
     * @return array<string, int|string>
     */
    public function commonsEnvValues(string $tenant, string $password, ?int $redisIndex, array $services): array
    {
        $ns = $this->plexNamespace();
        $values = [];

        if (in_array('postgres', $services, true)) {
            $values['DB_HOST'] = "postgres.{$ns}.svc.cluster.local";
            $values['DB_PORT'] = 5432;
            $values['DB_DATABASE'] = $tenant;
            $values['DB_USERNAME'] = $tenant;
            $values['DB_PASSWORD'] = $password;
        }

        if (in_array('redis', $services, true)) {
            $values['REDIS_HOST'] = "redis.{$ns}.svc.cluster.local";
            $values['REDIS_PORT'] = 6379;
            if ($redisIndex !== null) {
                $values['REDIS_DB'] = $redisIndex;
            }
        }

        return $values;
    }

    /**
     * Idempotent SQL that creates a tenant's database, login role, and grant in
     * the Commons Postgres. Piped to `psql` over stdin (so `\gexec` works). Pure.
     * $db/$role are pre-sanitized identifiers; the password is single-quote escaped.
     */
    public function buildPostgresTenantSql(string $db, string $role, string $password): string
    {
        $pw = str_replace("'", "''", $password);

        return implode("\n", [
            // Role first — the database is created OWNED BY it.
            "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN CREATE ROLE \"{$role}\" LOGIN PASSWORD '{$pw}'; END IF; END \$\$;",
            "ALTER ROLE \"{$role}\" PASSWORD '{$pw}';",
            // Tenant OWNS its database, so it can create its own schema/tables and
            // run migrations (Postgres 15+ locks down the public schema for
            // non-owners) — full per-tenant isolation, no shared-schema grants.
            "SELECT 'CREATE DATABASE \"{$db}\" OWNER \"{$role}\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$db}')\\gexec",
            "ALTER DATABASE \"{$db}\" OWNER TO \"{$role}\";",
            "GRANT ALL PRIVILEGES ON DATABASE \"{$db}\" TO \"{$role}\";",
            // Hand the tenant its DB's public schema too — ALTER DATABASE OWNER
            // doesn't transfer it, and Postgres 15+ won't let a non-owner create
            // objects in `public`. Without this, migrations fail with
            // "permission denied for schema public".
            "\\connect \"{$db}\"",
            "ALTER SCHEMA public OWNER TO \"{$role}\";",
        ]);
    }

    /**
     * Inverse of buildPostgresTenantSql: drop a tenant's database and role from
     * the Commons. Terminates live connections first (Postgres refuses to drop a
     * database with open sessions). $db/$role come from plexTenantIdentifier()
     * (sanitised to [a-z0-9_]), so they are safe to interpolate — same trust
     * model as the create path.
     */
    public function buildDropTenantSql(string $db, string $role): string
    {
        return implode("\n", [
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$db}' AND pid <> pg_backend_pid();",
            "DROP DATABASE IF EXISTS \"{$db}\";",
            "DROP ROLE IF EXISTS \"{$role}\";",
        ]);
    }

    /**
     * Pure registry transforms. The plex-registry shape is
     * {"tenants": {"<id>": {"db": "<id>", "redis_index": <int|null>}}}.
     */
    public function registryAdd(array $registry, string $tenant, array $allocation): array
    {
        $registry['tenants'][$tenant] = $allocation;

        return $registry;
    }

    public function registryRemove(array $registry, string $tenant): array
    {
        unset($registry['tenants'][$tenant]);

        return $registry;
    }

    /**
     * @return array<int, int>
     */
    public function registryUsedRedisIndexes(array $registry): array
    {
        $indexes = [];
        foreach ($registry['tenants'] ?? [] as $alloc) {
            if (isset($alloc['redis_index']) && is_int($alloc['redis_index'])) {
                $indexes[] = $alloc['redis_index'];
            }
        }

        return $indexes;
    }

    /** A `kubectl` prefix scoped to the resolved plex context (current when null). */
    protected function plexKubectl(): string
    {
        return $this->plexContext !== null && $this->plexContext !== ''
            ? 'kubectl --context '.escapeshellarg($this->plexContext)
            : 'kubectl';
    }

    /** Whether the resolved plex context's API server is reachable. */
    protected function plexContextReachable(): bool
    {
        exec($this->plexKubectl().' get --raw=/readyz --request-timeout=5s 2>/dev/null', $out, $code);

        return $code === 0;
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
            $this->plexKubectl()." get configmap plex-commons -n {$ns} -o jsonpath='{.data.commons\\.json}' 2>/dev/null",
        ));

        if ($json === '') {
            return null;
        }

        $spec = json_decode($json, true);

        return is_array($spec) ? $spec : null;
    }

    /**
     * Read the live tenant registry from the cluster (empty shape if absent).
     */
    protected function getRegistry(): array
    {
        $ns = $this->plexNamespace();
        $json = trim((string) shell_exec(
            $this->plexKubectl()." get configmap plex-registry -n {$ns} -o jsonpath='{.data.registry\\.json}' 2>/dev/null",
        ));

        $registry = $json === '' ? [] : json_decode($json, true);

        return is_array($registry) ? $registry : [];
    }

    /**
     * Persist the tenant registry back to the cluster (idempotent apply of the
     * single registry.json key).
     */
    protected function saveRegistry(array $registry): void
    {
        $ns = $this->plexNamespace();
        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_registry');
        file_put_contents($tmp, (string) json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $kubectl = $this->plexKubectl();
        shell_exec(
            "{$kubectl} create configmap plex-registry -n {$ns} ".
            '--from-file=registry.json='.escapeshellarg($tmp).
            " --dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        @unlink($tmp);
    }
}
