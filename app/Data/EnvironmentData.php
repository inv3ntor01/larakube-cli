<?php

namespace App\Data;

use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use Spatie\LaravelData\Data;

/**
 * Per-environment configuration overrides. Lives inside
 * ConfigData::$environments as a map keyed by env name (local, staging,
 * production).
 *
 * Carries the fields that genuinely vary per environment:
 *   - ingress: optional override of the project-level default
 *   - managed: services LaraKube should NOT deploy in this env (because an
 *     external provider handles them — e.g. RDS Postgres in production)
 *   - hosts: service → external hostname map
 *   - addFeatures: explicit opt-in for a feature whose enum default would
 *     otherwise exclude it from this env (rare)
 *   - excludeFeatures: explicit opt-out for a feature whose enum default
 *     would otherwise include it in this env (rare)
 *
 * Common-case features (horizon, queues, reverb, scheduler, ssr, boost,
 * mailpit, etc.) live in ConfigData::$features at the project level. Each
 * LaravelFeature enum case declares its natural environment scope via
 * defaultEnvironments(), and ConfigData::getFeatures($env) filters by it.
 * That keeps blueprints lean — most projects need neither addFeatures
 * nor excludeFeatures.
 */
class EnvironmentData extends Data
{
    public function __construct(
        public ?IngressController $ingress = null,
        /**
         * Services external to the cluster in this environment (e.g.
         * managed Postgres on RDS in production). LaraKube skips
         * deployment for these.
         *
         * @var array<int, string>
         */
        public array $managed = [],
        /**
         * Service → external hostname map. Example for production:
         *   ['web' => 'app.example.com', 'reverb' => 'ws.example.com']
         *
         * @var array<string, string>
         */
        public array $hosts = [],
        /**
         * Features to enable in this env that would otherwise be excluded
         * by their enum's defaultEnvironments() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $addFeatures = [],
        /**
         * Features to disable in this env that would otherwise be enabled
         * by their enum's defaultEnvironments() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $excludeFeatures = [],
    ) {}
}
