# speckit.plan: Environment-First Configuration Schema

## 🎯 Objective
Refactor `.larakube.json` so per-environment overrides (ingress, managed services, hosts) live inside each env's bucket — and so feature enablement is **scoped by the enum's natural environment fit** (BOOST/AI/MCP → local-only, SSR → production-only, etc.) rather than being dumped into a single global list. The blueprint should be **leaner**, not more verbose.

## 🚦 Mode: clean break, no backward compatibility

Confirmed during the partial Phase 1 attempt: only 1-2 projects use LaraKube today (pilot-app + maybe one other). Maintaining a two-shape compat layer is more cost than the cutover. **No `migrateLegacySchema()`, no dual reads, no deprecation period.** Existing blueprints must be edited to the new shape.

## 🧩 Design Principles

After dogfooding the naïve "stick everything per-env" design, two refinements landed:

1. **Features have natural environment scope** — `BOOST/AI/MCP/MAILPIT` only make sense locally; `SSR` only makes sense in production; `HORIZON/QUEUES/REVERB/SCHEDULER` apply everywhere. Hard-coding this in `LaravelFeature` removes repetition from the blueprint and prevents the "boost accidentally in prod" footgun.

2. **`ingress` has a sensible default everywhere** — 95% of projects use the same controller in every env. Top-level default + per-env override is the right ergonomics.

## 🧱 Target Schema

```json
{
  "name": "pilot-app",
  "serverVariation": "frankenphp",
  "frontend": "react",
  "database": "postgres",
  "packageManager": "npm",

  "ingress": "traefik",
  "features": ["horizon", "queues", "reverb", "scheduler", "boost", "ssr", "mcp"],

  "environments": {
    "local": {
      "managed": [],
      "hosts": {}
    },
    "production": {
      "managed": ["postgres", "redis", "meilisearch"],
      "hosts": {
        "web": "example.com",
        "reverb": "ws.example.com"
      }
    }
  }
}
```

**Notice what's missing from `environments[*]`**: no `features` (auto-filtered by enum), no `ingress` (inherits top-level). Only the fields that genuinely vary per env (`managed`, `hosts`) live in each bucket. pilot-app's blueprint actually *shrinks* under this design.

### Feature resolution (the hybrid)

For env `local`:
- Start with top-level `features`
- Filter by enum: include feature `F` only if `local` is in `F::defaultEnvironments()`
- Apply per-env `addFeatures` (rare opt-in for non-default cases)
- Apply per-env `excludeFeatures` (rare opt-out)

For env `production`:
- Same logic with `production` as the env context.

For pilot-app: top-level lists `[horizon, queues, reverb, scheduler, boost, ssr, mcp]`. After enum-driven filtering:
- **local**: `[horizon, queues, reverb, scheduler, boost, mcp]` (ssr filtered out — production-only)
- **production**: `[horizon, queues, reverb, scheduler, ssr]` (boost, mcp filtered out — local-only)

### Ingress resolution

```
$config->getEnvironment($env)?->ingress    // per-env override, if present
    ?? $config->ingress                     // top-level default
    ?? IngressController::TRAEFIK           // hardcoded fallback
```

## 🧱 EnvironmentData class (new)

`app/Data/EnvironmentData.php`:

```php
class EnvironmentData extends Data
{
    public function __construct(
        public ?IngressController $ingress = null,     // optional override
        /** @var array<int, string> */
        public array $managed = [],
        /** @var array<string, string> */
        public array $hosts = [],
        /** @var array<int, LaravelFeature> */
        public array $addFeatures = [],                // rare: opt feature into this env
        /** @var array<int, LaravelFeature> */
        public array $excludeFeatures = [],            // rare: opt feature out of this env
    ) {}
}
```

**Spatie Data gotcha (learned during the abandoned attempt)**: Spatie v4 cannot auto-cast `array<string, EnvironmentData>` from a JSON map. Fix: in `ConfigData::__construct`, iterate `$environments` and promote any associative arrays to `EnvironmentData` instances. Tested working.

## 🔧 ConfigData changes

### Remove
- `public ?string $productionHost` — moves to `environments.production.hosts.web`
- `public array $managedServices` — moves to `EnvironmentData.managed`

### Keep top-level (with new semantics)
- `public array $features` — still the project's feature list, but each feature's env applicability is now derived from the enum
- `public ?IngressController $ingress` — top-level default (renamed from `$ingressController`)

### Add
- `public array $environments = []` typed as `array<string, EnvironmentData>` (Spatie auto-cast workaround in constructor)
- Constructor backfills `local` + `production` EnvironmentData if empty
- Constructor promotes array entries to `EnvironmentData`

### Rewrite methods

| Method | New signature | Behavior |
|---|---|---|
| `getEnvironments()` | `: array` | Returns `array_keys($this->environments)` — flat list of env names (preserves call-site contract) |
| `getEnvironment($name)` | `: ?EnvironmentData` | NEW |
| `hasEnvironment($name)` | `: bool` | NEW |
| `getFeatures(?string $env = null)` | `: array` | If `$env` given, returns features RESOLVED for that env (enum filter + addFeatures + excludeFeatures). If null, returns the raw top-level list (rarely what callers want — should be replaced in sweep). |
| `hasFeature($feature, ?string $env = null)` | `: bool` | Same env-resolution. If null, "is this feature anywhere in any env." |
| `addFeature($feature, ?string $env = null)` | `: self` | If `$env` given, adds to that env's `addFeatures`. If null, adds to top-level (the normal case). |
| `removeFeature($feature, ?string $env = null)` | `: self` | If env given, adds to that env's `excludeFeatures` (doesn't touch top-level). If null, removes from top-level. |
| `getIngress($env)` | `: IngressController` | per-env override → top-level default → TRAEFIK |
| `getManaged($env)` | `: array` | `environments.$env.managed` |
| `getProductionHost()` | `: string` | `environments.production.hosts['web'] ?? "{name}.dev.test"` |
| `getAppUrl($env)` | `: string` | Reads from `environments.$env.hosts['web']` |
| `getServiceHost($service, $env)` | `: string` | Reads from `environments.$env.hosts[$service]` |
| `getAllHosts($env)` | `: array` | Aggregates env's `hosts` map + computed defaults from `getComponents()` |
| `setProductionHost($host)` | `: self` | Writes into `environments.production.hosts['web']` |

## 🧱 LaravelFeature enum addition

```php
/**
 * Environments where this feature naturally applies. Used by
 * ConfigData::getFeatures($env) to filter the top-level features list
 * per environment without forcing the user to repeat themselves.
 *
 * @return array<int, string>
 */
public function defaultEnvironments(): array
{
    return match ($this) {
        self::BOOST, self::AI, self::MCP, self::MAILPIT => ['local'],
        self::SSR => ['production'],
        // HORIZON, QUEUES, REVERB, SCHEDULER, MONITORING, OCTANE, METALLB, SCOUT, TASK_SCHEDULING:
        default => ['local', 'production', 'staging'],
    };
}
```

Per-env `addFeatures` / `excludeFeatures` only matter when a user wants to override these defaults (e.g., "actually I do want boost in staging for some reason"). The 99% case is the user lists features at top level and the system Does The Right Thing.

## 📂 Files to update (20)

**Core**
- `app/Data/ConfigData.php` — restructure
- `app/Data/EnvironmentData.php` — new
- `app/Enums/LaravelFeature.php` — add `defaultEnvironments()` method

**Enums** (read `->managedServices`)
- `app/Enums/DatabaseDriver.php` — `in_array($this->value, $config?->managedServices ?? [])` → `$config->getManaged($env)`
- `app/Enums/ScoutDriver.php` — same pattern
- `app/Enums/CacheDriver.php` — same pattern
- `app/Enums/StorageDriver.php` — same pattern

**Traits**
- `app/Traits/GathersInfrastructureConfig.php` — init wizard: prompt for top-level ingress, per-env managed/hosts; features go top-level (enum filters per env)
- `app/Traits/GeneratesProjectInfrastructure.php` — scaffolding needs env context threaded through where it reads features
- `app/Traits/InteractsWithDynamicOptions.php`
- `app/Traits/EnsuresHostDependencies.php` — reads `hasFeature(SSR)` → must specify env

**Commands**
- `app/Commands/UpCommand.php`
- `app/Commands/InitCommand.php`
- `app/Commands/AddCommand.php` — writes ingress + managed (now per-env for managed)
- `app/Commands/RemoveCommand.php`
- `app/Commands/ReloadCommand.php`
- `app/Commands/AboutCommand.php`
- `app/Commands/Cloud/CloudConfigureCommand.php`
- `app/Commands/Cloud/CloudDeployCommand.php`

**MCP**
- `app/Mcp/Tools/PatchBlueprintTool.php`

**Templates** (each needs `$env` blade variable threaded in from the rendering loop)
- `resources/views/k8s/base/ingress.blade.php`
- `resources/views/k8s/overlays/production/ingress-patch.blade.php`
- `resources/views/k8s/overlays/local/deployment-patch.blade.php`
- `resources/views/k8s/overlays/production/deployment-patch.blade.php`
- `resources/views/k8s/cloud-pilot-deploy.blade.php`

**Tests**
- `tests/Unit/ConfigDataTest.php`
- `tests/Feature/KustomizeIndentationTest.php`
- `tests/Feature/DependencyResolutionTest.php`
- `tests/Feature/FeatureManifestTest.php`
- `tests/Feature/ManifestVerificationTest.php`

## 🎯 Sweep order

1. **EnvironmentData class + ConfigData shape change + LaravelFeature::defaultEnvironments()** (no method rewrites yet). Tests fail loudly but the model is in place.
2. **ConfigData method rewrites** — make unit tests green by reworking all getter/setter bodies.
3. **Enum updates** (4 enums, mechanical `managedServices` → `getManaged($env)` swap with env arg threaded through).
4. **Template updates** (5 blade files). Each needs `$env` passed in by the rendering loop. Snapshot regen + visual diff of 1-2 snaps.
5. **Command + trait updates** (~12 files). Wizards are the fiddly ones.
6. **MCP tool + tests** — last because they tend to surface integration issues.

## 🧪 Verification gates

- After step 2: `pest tests/Unit/ConfigDataTest.php` green.
- After step 3: `pest tests/Unit/Enums` green.
- After step 4: spot-check 1-2 snapshot diffs visually before bulk `--update-snapshots`. Confirm structural-only changes (no surprise reordering, no lost volume mounts).
- After step 5: full `pest` green.

## 🏗 pilot-app blueprint migration

Manual edit, current shape (top-level fields):

```json
{
  "name": "pilot-app",
  "managedServices": ["postgres", "redis", "meilisearch"],
  "ingressController": "traefik",
  "features": ["horizon", "queues", "reverb", "scheduler", "boost"],
  "environments": ["local", "production"]
}
```

Target shape (note: gains `ssr` and `mcp` because they were always implicit; no `boost` repetition because the enum scopes it to local):

```json
{
  "name": "pilot-app",
  "ingress": "traefik",
  "features": ["horizon", "queues", "reverb", "scheduler", "boost", "mcp", "ssr"],
  "environments": {
    "local": {
      "managed": [],
      "hosts": {}
    },
    "production": {
      "managed": ["postgres", "redis", "meilisearch"],
      "hosts": {}
    }
  }
}
```

Headline win: production gets `ssr` automatically, local gets `boost` + `mcp` automatically, nothing repeated.

## ⚡ Estimated effort

4-6 focused hours in a fresh session. Surface is well-scoped (20 files), unknowns are eliminated.

## 🧹 Lessons from the abandoned partial attempt (don't repeat)

1. **Spatie `from()` override isn't enough on its own** — also needs the constructor `is_array` promotion loop because `from()` runs before construction completes.
2. **`getEnvironments(): array` callers expect a flat string array.** Returning `array_keys($this->environments)` preserves their contract (verified: only 3 callers — InteractsWithEnvironments, CacheDriver, DatabaseDriver — all iterate names).
3. **`hasFeature($feature)` with no env should mean "any env has it"** for unmodified callers to keep working during the sweep.
4. **The 2 ingress templates** need active env context passed in. Add `$env` blade variable upstream in the rendering loop.
5. **Per-env `features` repetition is a real ergonomic concern.** The hybrid (enum-driven defaults + per-env addFeatures/excludeFeatures for rare overrides) keeps the blueprint lean.
6. **`ingress` has a sensible top-level default** in 95% of projects. Don't force every env bucket to repeat it.

## 🔗 SSR v2 link

This refactor unblocks `plans/active/ssr-feature-v2-scaffolding.md`. After the cutover, SSR's "prod-only" identity is captured by `LaravelFeature::SSR::defaultEnvironments() === ['production']`. No special-case `localSsr` boolean needed; no per-env feature list bloat.
