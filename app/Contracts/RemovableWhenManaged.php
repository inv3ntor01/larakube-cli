<?php

namespace App\Contracts;

use App\Data\ConfigData;

/**
 * A backing service whose in-cluster workload should be fully removed from an
 * environment where it is externally managed (e.g. Postgres on RDS, Redis on
 * ElastiCache). The base layer always ships the Deployment/Service so local
 * keeps it; for a managed cloud env the generator emits a kustomize
 * `$patch: delete` for each resource listed here, so the pod never runs there.
 */
interface RemovableWhenManaged
{
    /**
     * Cluster resources to delete in environments where this service is
     * managed. Empty when there's nothing to remove (e.g. SQLite, the
     * database-backed cache/scout drivers).
     *
     * @return array<int, array{kind: string, name: string}>
     */
    public function getManagedResources(ConfigData $config): array;
}
