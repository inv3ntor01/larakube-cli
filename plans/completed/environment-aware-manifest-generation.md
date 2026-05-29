# speckit.plan: Environment-Aware Manifest Generation

## 🎯 Objective

Make manifest generation honor **every** environment in `.larakube.json`, not just the hardcoded `local` + `production`. After this, `larakube env staging` and `larakube heal` produce a correct, complete `overlays/staging/` that reflects staging's own ingress, hosts, managed services, and per-env feature set — and a `managed` service is genuinely removed from the envs that manage it.

## 🩺 The three symptoms (one root cause)

The architectural engine assumes exactly two cloud-agnostic envs:

1. **`larakube env <name>` copies the production overlay** (string-replacing the namespace) instead of generating from the new env's config. The new env's ingress/hosts/managed never reach its manifests.
2. **`heal` regenerates only `overlays/local` + `overlays/production`** — `GeneratesProjectInfrastructure` hardcodes those paths and never iterates `getEnvironments()`. Custom envs drift forever.
3. **`managed` removes only the per-env *volume* manifest, not the base Deployment** — the `unset($manifests[$env])` loops in the driver enums target manifest-category keys (`base`/`local`/`production`), so a managed Postgres still deploys a pod in cloud.

Root cause: generation is written around the literal pair `local`/`production`. Fix the engine to be env-driven and all three resolve.

## 🧱 What already works (leverage, don't rebuild)

- Blade overlay templates are **already env-parameterized**: `getIngress($env)`, `getServiceHost`, `getAppUrl`, and the ingress-patch all take `$environment`. A cloud overlay is just the production template set rendered with `namespace={app}-{env}` + `environment={env}`.
- `getComponents($env)` already filters features per env (SSR→prod, boost→local, addFeatures/excludeFeatures). Per-env manifest collection falls out of calling it with the env.
- Snapshot tests (`ManifestVerificationTest`, `FeatureManifestTest`, `KustomizeIndentationTest`, `ServicesManifestTest`) assert exact output and are the **regression guardrail**: production/local output must stay byte-identical through Phase 1-2.

## 🏗 Design

### Cloud env = the production template set, per env

`production` stops being special. It becomes "the first cloud env." Every non-local env gets the same overlay stub set:
`kustomization.yaml`, `namespace.yaml`, `config-patch.yaml`, `deployment-patch.yaml`, `ingress-patch.yaml` — rendered with that env's namespace + environment.

`local` keeps its distinct stub set (infrastructure.yaml, patches.yaml, pvc-patch.yaml, node-deployment.yaml, hostPath mounts).

### `'production'` manifest key → `'cloud'`

In every `getManifestFiles()` (`DatabaseDriver`, `ScoutDriver`, `StorageDriver`, `LaravelFeature`), rename the `'production'` category to `'cloud'` — it always meant "cloud-env volumes/workloads," not "the production env specifically." The engine applies `cloud` manifests to each non-local env, collected via `getComponents($env)` so per-env feature filtering applies (e.g. SSR only where active).

### Per-env collection + registration

```
base    ← getComponents()          base manifests   (env-agnostic, shared)
local   ← getComponents('local')   local manifests + patches
<env>   ← getComponents($env)       cloud manifests  (for each cloud env)
```

`updateK8s` volume loops in Scout/Storage that iterate `['local','production']` become `array_merge(['local'], $config->getCloudEnvironments())` so each cloud env's volumes file is written.

### Managed deployment-skip (symptom 3)

Remove the no-op `unset($manifests[$env])` loops from the driver enums. The engine handles it per env:

- **Skip the cloud volume manifest** for a managed service in that env (don't register it).
- **Emit a kustomize delete-patch** in the env's overlay removing the service's base Deployment (and Service) — `{$patch: delete}` keyed by kind+name — so the managed service is fully gone from that env while local keeps it.

### New helper

`ConfigData::getCloudEnvironments(): array` → `getEnvironments()` minus `local`. (Mirrors the command-side helper added earlier, but on the data object the engine already holds.)

### EnvCommand stops copying

`larakube env <name>` updates the blueprint DNA (it already gathers ingress/managed/hosts), then calls the architectural engine to regenerate. No more `file_get_contents(production/*) + str_replace`. The overlay is generated from the env's own config like every other env.

## 📋 Files

- `app/Data/ConfigData.php` — add `getCloudEnvironments()`.
- `app/Traits/GeneratesProjectInfrastructure.php` — env-driven mkdir, stub rendering loop, per-env collection/registration, delete-patch emission.
- `app/Commands/EnvCommand.php` — delegate to engine instead of copying.
- `app/Enums/{DatabaseDriver,ScoutDriver,StorageDriver,LaravelFeature}.php` — `'production'`→`'cloud'`; remove managed-skip loops; volume `updateK8s` loops over cloud envs.
- `app/Enums/CacheDriver.php` — remove managed-skip loop (no `cloud` key today).
- Templates — render per cloud env (already parameterized); thread `$environment` into `deployment-patch` so `hasFeature($f,$env)` is respected.
- Tests — staging generation, managed delete-patch; verify local/production snapshots unchanged.

## 🚦 Phases

1. **Env-aware generation.** Engine loop + `'cloud'` rename + per-env collection + Scout/Storage volume loops. Production/local output byte-identical. Staging overlay generates correctly. (Symptoms 1-via-heal & 2.)
2. **EnvCommand delegation.** `env` regenerates via engine. (Symptom 1 fully.)
3. **Managed delete-patch.** Remove enum skip-loops; engine emits delete-patches + skips managed volumes. (Symptom 3.)
4. **Tests + snapshot review + commit.**

## 🧪 Verification

- `larakube env staging` (aws-alb, web host, managed postgres+redis) → `overlays/staging/` has staging namespace, ALB ingress annotations, staging web host, NO postgres/redis volume manifests, AND delete-patches removing the postgres/redis Deployments.
- `larakube kustomize staging` renders valid YAML; the managed services are absent; the app Deployment + ingress are present with staging values.
- `kustomize local` and `kustomize production` outputs unchanged vs current snapshots.
- Renaming production→"main" then `heal` regenerates `overlays/main/` correctly (env name is fully soft).

## ⚠️ Risks

- **Snapshot drift.** Any incidental change to production/local output fails the snapshot tests — treat any diff as a bug to investigate, not a snapshot to bless, unless it's a deliberate, reviewed improvement.
- **Delete-patch correctness.** Must target the right kind+name for each driver's base resources (Deployment + Service). Verify per driver.
- **`appendToKustomization` idempotency.** Re-running heal must not duplicate resource/patch entries across the now-N overlays (the existing `str_contains` guard should hold, but test multi-env re-runs).
- **Stale overlays.** If a user removes an env from the blueprint, its overlay dir lingers. Out of scope here; note for a future `env --remove`.
