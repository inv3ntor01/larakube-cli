<?php

namespace App\Data;

use App\Enums\TunnelProvider;
use Spatie\LaravelData\Data;

/**
 * Persistent tunnel configuration for a cloud environment.
 * Stored in EnvironmentData::$tunnel (nullable).
 * Example: {"provider": "cloudflare"}
 *
 * The secret token is never stored here — it lives in the K8s Secret
 * `larakube-tunnel-secret` in the app namespace.
 */
class TunnelData extends Data
{
    public function __construct(
        public TunnelProvider $provider,
    ) {}
}
