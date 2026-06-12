# Global Companions — Shared Cluster-Level Companion Apps

## Goal

Move companion apps (phpMyAdmin, RedisInsight, etc.) out of per-project namespaces and into `larakube-system` as shared cluster-level services — the same pattern as Traefik. One instance serves all local apps simultaneously.

## Dependency

**Implement after `vanity-local-domains.md` (Phase 2) ships.** The companion hostnames will use the `.kube` TLD, not `.dev.test`. Do not hard-code `.dev.test` anywhere in this feature.

Planned companion URLs:
```
phpmyadmin.kube        → phpMyAdmin (arbitrary server mode)
redisinsight.kube      → RedisInsight
mongo-express.kube     → Mongo Express
typesense-dashboard.kube → Typesense Dashboard (if applicable)
```

---

## Why This Works

### Cross-namespace DNS

From `larakube-system`, Kubernetes DNS resolves every project's services as:
```
mysql.{appname}.svc.cluster.local
redis.{appname}.svc.cluster.local
```

A single phpMyAdmin instance can reach any project's database without needing to be in the same namespace.

### Arbitrary Server Mode

phpMyAdmin supports `$cfg['AllowArbitraryServer'] = true` — users type the MySQL host at the login screen. The host to enter is `mysql.{appname}.svc.cluster.local`. No per-project phpMyAdmin configuration needed.

RedisInsight natively manages multiple named connections — users add a new connection once per project using the same cross-namespace hostname.

---

## Current Behaviour (to be replaced)

Each project that has MySQL/MariaDB/PostgreSQL with `withCompanions = true` generates:
- `overlays/local/{driver}-companion.yaml` — Deployment in the project namespace
- `overlays/local/{driver}-companion-ingress.yaml` — Ingress at `{driver}-{appname}.dev.test`

This means:
- N MySQL projects = N phpMyAdmin pods running simultaneously
- Companions only exist when a project has been scaffolded with `withCompanions`
- Hostname differs per project (`mysql-hospital.dev.test`, `mysql-store.dev.test`)

---

## Proposed Behaviour

### Cluster setup (`cluster:setup` / `setupTraefik`)

After Traefik is installed, deploy global companions into `larakube-system`:

```
phpmyadmin.kube    →  phpMyAdmin (arbitrary server, connects to any MySQL/MariaDB/PostgreSQL)
redisinsight.kube  →  RedisInsight (user adds connections per project)
```

Companions are deployed once and stay running for the lifetime of the cluster — just like Traefik.

### Per-project scaffolding

- Remove per-project `{driver}-companion.yaml` and `{driver}-companion-ingress.yaml` generation
- Remove `withCompanions` toggle from `UpCommand` (`--companions` / `--no-companions` flags become irrelevant)
- Remove `withCompanions` from `ConfigData` and `GathersInfrastructureConfig`
- The `hasCompanion()` checks in DatabaseDriver / CacheDriver / ScoutDriver enums are removed

### What the user sees

```
larakube cluster:setup   →  Traefik + phpMyAdmin + RedisInsight installed once
larakube up              →  app starts; companions already there
# Visit phpmyadmin.kube → log in with host: mysql.hospital.svc.cluster.local
```

---

## Companion Manifest Design

Companions live in `larakube-system` (already exists — hosts console + Traefik dashboard).

New static manifests (rendered once during `cluster:setup`, not templated per-project):

### phpMyAdmin

- Image: `phpmyadmin:latest`
- Env: `PMA_ARBITRARY=1` (enables arbitrary server mode)
- Ingress host: `phpmyadmin.kube`
- No per-project configuration

### RedisInsight

- Image: `redis/redisinsight:latest`
- Ingress host: `redisinsight.kube`
- User adds connections manually (hostname: `redis.{appname}.svc.cluster.local`)

---

## Migration for Existing Projects

`larakube heal` should:
1. Remove `{driver}-companion.yaml` and `{driver}-companion-ingress.yaml` from `overlays/local/`
2. Remove companion entries from `kustomization.yaml` resources list

---

## What Changes

| Component | Change |
|---|---|
| `cluster:setup` / `setupTraefik` | Deploy global companions after Traefik |
| `DatabaseDriver::writeKubernetesFiles()` | Remove companion manifest generation |
| `CacheDriver::writeKubernetesFiles()` | Remove companion manifest generation |
| `ScoutDriver::writeKubernetesFiles()` | Remove companion manifest generation |
| `DatabaseDriver/CacheDriver/ScoutDriver::getKubernetesFiles()` | Remove companion file entries |
| `ConfigData::$withCompanions` | Remove field |
| `GathersInfrastructureConfig` | Remove companion prompt |
| `UpCommand` | Remove `--companions` / `--no-companions` flags |
| `LaravelFeature` (Mailpit, Monitoring) | Unaffected — these stay per-project |
| `larakube heal` | Remove stale companion manifests |
| New: `resources/views/k8s/system/companions.blade.php` | Static companions manifest for `larakube-system` |

---

## Open Questions

- **Mailpit**: currently gated by `withCompanions`. Should it also go global? It's more of a dev tool (catches all outbound mail). Could make sense as `mailpit.kube` — one inbox, all projects' mail. Decide before implementing.
- **Mongo Express**: same pattern as phpMyAdmin (`ME_CONFIG_MONGODB_SERVER` can be set at runtime). Include?
- **Typesense Dashboard**: currently in its own ingress template. Fold into global companions?
- **`--companions` flag on `UpCommand`**: remove entirely or repurpose as "deploy/skip global companion setup"?
