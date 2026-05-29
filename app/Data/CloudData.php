<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * SSH connection config for deploying an environment to a remote host, plus
 * the teammates granted access to that host. Lives on EnvironmentData::$cloud
 * so cloud config stays attached to the environment it describes.
 */
class CloudData extends Data
{
    public function __construct(
        public ?string $ip = null,
        public ?string $user = 'larakube',
        public ?int $port = 22,
        public ?string $key = null,
        /**
         * Teammate SSH-key descriptors granted access to this env's host.
         * Synced by `cloud:configure users`. Per-env, so different servers
         * can grant different people access.
         *
         * @var array<int, array>
         */
        public array $teammates = [],
    ) {
        $this->key = $key ?? ($_SERVER['HOME'] ?? '').'/.ssh/id_rsa';
    }
}
