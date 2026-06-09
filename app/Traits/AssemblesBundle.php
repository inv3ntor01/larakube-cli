<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Build-side logic for the air-gapped on-prem bundle (`bundle:build`). Kept in a
 * trait so the pure parts — image derivation, the bundle manifest — are unit
 * testable without a cluster or Docker.
 */
trait AssemblesBundle
{
    /** System images every bundle carries regardless of the blueprint (ingress, …). */
    private const BUNDLE_SYSTEM_IMAGES = ['traefik:v3.1'];

    /**
     * Every container image an air-gapped bundle must carry: the app image (built
     * for the target arch) plus each dependency the blueprint declares. Dependencies
     * come straight from the driver enums (database / cache / scout / object storage)
     * via getDockerImage(), so the list is derived from `.larakube.json` and can
     * never drift — no hand-maintained "zip mysql, redis, …" list. Empty images
     * (SQLite, database-cache: no service) are skipped.
     *
     * @return array{app: string, dependencies: array<int, string>}
     */
    public function bundleImages(ConfigData $config): array
    {
        $drivers = array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getObjectStorage(),
            $config->getScoutDriver(),
            ...$config->getDatabases(),
            ...$config->getCacheDrivers(),
            ...$config->getObjectStorages(),
            ...$config->getScoutDrivers(),
        ]);

        $dependencies = self::BUNDLE_SYSTEM_IMAGES;
        foreach ($drivers as $driver) {
            $image = $driver->getDockerImage($config);
            if ($image !== '') {
                $dependencies[] = $image;
            }
        }

        return [
            'app' => $config->getName().':latest',
            'dependencies' => array_values(array_unique($dependencies)),
        ];
    }
}
