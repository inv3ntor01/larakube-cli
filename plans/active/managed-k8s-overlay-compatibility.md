# speckit.plan: Managed-K8s Overlay Compatibility (EKS / GKE / etc.)

> **Status (2026-05-30):** Phases 1–4 shipped (commits 7665e0a, 5cf45a0, 4aea79b)
> — all snapshot-stable, default to current output. Remaining: Phase 5 (env
> config/secret names, low-priority) and Phase 6 (configurable registry + GHA
> workflow generation). Note corrected below: the `larakube-dashboard` SA is
> `isSystem()`-gated, so it never leaked onto user app pods — Phase 3 was purely
> additive (opt-in SA + IRSA), not a leak fix.

## 🎯 Objective

Make LaraKube's generated manifests drop into a managed Kubernetes cluster
(EKS today, GKE/AKS later) without hand-editing, by exposing the
cloud-specific knobs per environment. The same overlay set should serve
both a Single-Node-Hero (GHCR + k3s) and a managed cluster (ECR + EKS +
IRSA + ALB) — selected by `.larakube.json`, not by forking the templates.

Driven by a real case: a pilot project's staging runs on EKS. Its hand-written
`.k8s/staging.yaml` already matches LaraKube's **workload** shape
(web/horizon/reverb deployments, scheduler CronJob, in-cluster
meilisearch), so the only thing blocking adoption of the generated
overlays is the **cloud-native wrapper**.

## 🔍 The divergences (managed-K8s app on EKS vs LaraKube overlay)

| Concern | LaraKube overlay emits | EKS staging needs | Configurable? |
|---|---|---|---|
| Namespace | `{name}-{env}` (`myapp-staging`) | existing `myapp` | **add override** |
| Image pull secret | `ghcr-login` (prod deployment-patch, hardcoded) | none (ECR via node role/IRSA) | **make nullable** |
| ServiceAccount | `larakube-dashboard` (hardcoded in base deployment) | `myapp-sa` w/ IRSA annotation | **add override + annotations** |
| Ingress annotations | scheme, target-type, listen-ports, ssl-redirect, healthcheck, CloudFront `conditions.php` | + ACM `certificate-arn`, `security-groups`, `conditions.web`/`conditions.reverb`, `deny-access` action | **add per-env passthrough** |
| Env wiring | `configMapRef: laravel-config` + `secretRef: laravel-secrets` | single `secretRef: myapp-env` | optional override (or adapt CI) |
| Image ref | `{name}:latest` placeholder (sed-substituted) | ECR sha | already registry-agnostic — no change |

Workload names (web/php/horizon/reverb/scheduler/meilisearch) already
match, so no remapping needed there.

## 🧱 Design — per-environment deploy knobs on EnvironmentData

All optional; all default to **today's behavior** so existing projects and
the snapshot suite are unchanged.

```php
class EnvironmentData {
    // … existing: ingress, managed, hosts, addFeatures, excludeFeatures …

    public ?string $namespace = null;            // override {name}-{env}
    public ?string $serviceAccount = null;       // override the pod SA name
    /** @var array<string,string> */
    public array $serviceAccountAnnotations = []; // e.g. eks.amazonaws.com/role-arn (IRSA)
    public ?string $imagePullSecret = null;       // null + "unset" sentinel handling
    public bool $omitImagePullSecret = false;     // EKS: drop ghcr-login entirely
    /** @var array<string,string> */
    public array $ingressAnnotations = [];        // merged into the ingress-patch
}
```

Resolution helpers on ConfigData mirror `getIngress($env)` /
`getWebHost($env)`:
- `getNamespace($env)` → `environments[$env]->namespace ?? "{name}-{env}"`
- `getServiceAccount($env)` / `getServiceAccountAnnotations($env)`
- `getImagePullSecret($env)` (returns null when omitted)
- `getIngressAnnotations($env)` (merged over the controller's defaults)

The engine already threads `$environment` into every overlay template, so
the templates just read these instead of hardcoded values.

### Container registry & image push

Today the generated CI/CD workflow (`cloud-pilot-deploy`) hardcodes **GHCR**:
`registry: ghcr.io`, a `docker/login-action` using the GitHub actor +
`GITHUB_TOKEN`, and an image ref of `ghcr.io/<repo>`. Teams on AWS ECR,
Docker Hub, GCP Artifact Registry, or a private registry can't use it as-is.

Make the push target a first-class choice — a `registry` block in
`.larakube.json` (project-level default, optional per-env override):

```json
"registry": {
  "provider": "ghcr",            // ghcr | ecr | dockerhub | gar | custom
  "image": "<namespace>/<name>", // repo/path within the registry
  "host": null                   // ECR account host / custom registry host
}
```

This drives three things:

1. **Image reference** everywhere — `{host}/{image}:{tag}` replaces the
   `{name}:latest` placeholder through the existing kustomize `sed` step.
2. **Workflow auth/push**, branched per provider by `cloud:configure gha`:
   - `ghcr` → `docker/login-action` (actor + `GITHUB_TOKEN`)
   - `ecr` → `aws-actions/configure-aws-credentials` (OIDC role) + `amazon-ecr-login`
   - `dockerhub` → `docker/login-action` (`DOCKERHUB_USERNAME` + token)
   - `gar` / `custom` → `docker/login-action` (host + creds)
3. **Image pull secret** on the manifests — ties into the `imagePullSecret`
   knob above (GHCR → `ghcr-login`; ECR → none/IRSA; Docker Hub → a
   `dockerhub` pull secret). One source of truth: the registry choice.

`larakube cloud:configure gha` gains a registry prompt and emits the correct
login + build-push steps; default stays GHCR so existing projects are
unchanged.

## 🚦 Phases (each independently shippable; each defaults to current output)

1. **Image pull secret** — the sharpest edge. `deployment-patch` reads
   `getImagePullSecret($env)`; omit the `imagePullSecrets` block when null.
   Default `ghcr-login` for Single-Node-Hero. Unblocks ECR immediately.
2. **Namespace override** — `getNamespace($env)` everywhere a namespace is
   derived (engine renderStub, kustomization `namespace:`, delete-patch,
   rollout targets). Lets the overlay land in an existing namespace.
3. **ServiceAccount + IRSA** — `serviceAccount` name + annotations into
   base serviceaccount + deployment `serviceAccountName`. (Also fixes the
   odd `larakube-dashboard` default leaking onto app pods.)
4. **Ingress annotation passthrough** — merge `ingressAnnotations` into the
   ingress-patch so ACM cert ARN, security groups, and custom ALB
   conditions/actions are expressible per env. Controller defaults stay;
   per-env entries extend/override.
5. **(Optional) env config/secret names** — `envConfigMapName` /
   `envSecretName` so the deployment's `envFrom` can point at an existing
   `myapp-env`. Lower priority — projects can instead create
   `laravel-config`/`laravel-secrets` in CI.
6. **Configurable container registry** — the `registry` block, provider-aware
   login/build-push in the generated workflow, and registry-aware image refs.
   Default GHCR (unchanged). Pairs with the imagePullSecret knob (Phase 1):
   the registry choice picks the pull-secret strategy. This is what lets a
   project push to ECR/Docker Hub/GAR instead of only GHCR.

## 🧪 Verification

- Snapshot suite unchanged after each phase (all knobs default to current).
- A blueprint with an EKS-style staging env (`namespace: myapp`,
  `omitImagePullSecret: true`, `serviceAccount: myapp-sa` + role-arn,
  `ingressAnnotations: {certificate-arn, security-groups, …}`) generates an
  overlay that `kubectl apply -k` accepts on EKS with no hand-editing.
- `larakube kustomize staging` for that blueprint diffs cleanly against the
  hand-written `.k8s/staging.yaml` (modulo intentional improvements).

## ⚠️ Risks / notes

- **Snapshot stability is the contract.** Every new field must no-op to
  current output when unset; treat any snapshot diff as a regression.
- **The `larakube-dashboard` SA default** on app pods looks like a Console
  artifact leaking into user workloads — Phase 3 should let apps use a
  normal/own SA and stop binding the dashboard role by default. Confirm
  nothing relies on app pods having that SA before changing the default.
- **Ingress is the messiest** — ALB conditions/actions are free-form JSON
  strings; passthrough (raw annotation map) is safer than trying to model
  them. Keep it a dumb merge.
- **Not the generated workflow.** This plan makes the *manifests* portable;
  projects on managed clusters keep their own CI (ECR/IRSA/Secrets Manager)
  and just `kubectl apply -k overlays/<env>`. The `cloud-pilot-deploy`
  workflow generator stays GHCR/Single-Node-Hero-specific.
