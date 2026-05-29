<?php

namespace App\Data;

use App\Enums\DeploymentStrategy;
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
 * appliesToEnvironment(), and ConfigData::getFeatures($env) filters by it.
 * That keeps blueprints lean — most projects need neither addFeatures
 * nor excludeFeatures.
 */
class EnvironmentData extends Data
{
    public function __construct(
        public ?IngressController $ingress = null,
        /**
         * Deployment strategy for this env (single-node vs multi-node-HA).
         * Lets a budget-tiered setup run e.g. staging single-node and
         * production multi-node-HA. Falls back to the project-level
         * strategy when null.
         */
        public ?DeploymentStrategy $strategy = null,
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
         * SSH connection + teammate access for deploying this env to a
         * remote host. Null for envs not (yet) wired to a server — local
         * never has one. Spatie Data auto-casts this nested object from a
         * JSON array.
         */
        public ?CloudData $cloud = null,
        /**
         * Features to enable in this env that would otherwise be excluded
         * by their enum's appliesToEnvironment() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $addFeatures = [],
        /**
         * Features to disable in this env that would otherwise be enabled
         * by their enum's appliesToEnvironment() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $excludeFeatures = [],
        // --- ☁️ Managed-K8s overlay knobs (EKS/GKE/AKS) ---
        // All optional; each no-ops to today's Single-Node-Hero output when
        // unset, so existing blueprints and the snapshot suite are unchanged.
        /**
         * Override the derived `{name}-{env}` namespace, so the overlay can
         * land in an existing cluster namespace (e.g. `myapp` on EKS).
         */
        public ?string $namespace = null,
        /**
         * ServiceAccount name for the app pods. Null = today's behavior (no
         * SA on user pods). Set for IRSA/Workload-Identity setups.
         */
        public ?string $serviceAccount = null,
        /**
         * Annotations for the generated ServiceAccount — e.g.
         * `eks.amazonaws.com/role-arn` for IRSA. Only emitted when
         * $serviceAccount is set.
         *
         * @var array<string, string>
         */
        public array $serviceAccountAnnotations = [],
        /**
         * Image pull secret name. Defaults to `ghcr-login` (Single-Node-Hero)
         * when null and not omitted. Set to point at a different secret.
         */
        public ?string $imagePullSecret = null,
        /**
         * Drop the imagePullSecrets block entirely — for clusters that pull
         * via the node role/IRSA (e.g. ECR on EKS) and need no secret.
         */
        public bool $omitImagePullSecret = false,
        /**
         * Extra ingress annotations merged into the env's ingress-patch —
         * ACM cert ARN, security groups, ALB conditions/actions, etc. Raw
         * passthrough (dumb merge); the controller's defaults still apply.
         *
         * @var array<string, string>
         */
        public array $ingressAnnotations = [],
    ) {}
}
