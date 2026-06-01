# Plex — Implementation Guide (`larakube plex:*`)

> **What this is:** a reviewable, code-grounded build guide for the `larakube plex`
> commands. It turns the locked design in [`tenement-multi-tenant-node.md`](./tenement-multi-tenant-node.md)
> into concrete commands, manifests, and a CI/CD story. Read that plan first for
> the *why* and the security model; this doc is the *how*.
>
> **Status:** design pass / spec. No code written yet. File paths below are where
> code *should* go, cross-referenced to the existing subsystems they build on.

---

## 0. The one decision that makes this clean

A Tenant **never** declares "I share Postgres with another app." It declares, per
environment, that a service is **`managed`** with a host — the exact seam that
already points production at RDS (`EnvironmentData::$managed` + `$hosts`,
`app/Data/EnvironmentData.php`). The Commons just becomes one more valid host.

So the critical insight for "works on CI/CD **and** `cloud:deploy`":

> **`plex:join` is a config-time operation, not a deploy-time one.** It writes the
> Commons wiring into the same `.larakube.json` (committed) and `.env.{env}`
> (gitignored, synced to GH secrets) that your *existing* deploy path already
> consumes. After a join, **the GHA workflow and `cloud:deploy` are unchanged** —
> they just stop deploying the app's own Postgres/Redis pods (because those
> services are now `managed`) and the app connects to the Commons FQDN from its
> injected env. No plex-specific CI steps required.

This is why we don't touch `resources/views/k8s/cloud-pilot-deploy.blade.php` for
the MVP.

---

## 1. Where each piece runs (the mental model)

| Piece | Scope | Run by | When | Idempotent? |
|---|---|---|---|---|
| **Commons** (Postgres+Redis in `larakube-shared`) | **Cluster** (once per box) | operator | once, after `cloud:provision` | yes (apply + PVC persists) |
| **`plex:join`** (DB/role/creds + config wiring) | **Tenant repo** | developer | once per app per env | yes (`--rotate` to reset creds) |
| **Deploy** (`cloud:deploy` / `git push` → GHA) | Tenant repo | CI or operator | every release | yes (already) |

The Commons is a **cluster concern**, like Traefik — provisioned once, not per
app. Tenants are wired at config time and then deploy normally. This mirrors how
`cloud:provision` preps the box once and many repos reuse it (plan §Tier 0 audit).

---

## 1a. Commons ownership & lifecycle — *no repo owns the Commons*

**Who manages the Commons if not an app repo?** Nothing does — and that's the
point. An "HOA app" that owns the Commons is fragile exactly as feared: pick an
arbitrary repo and the Commons is orphaned the day that repo is abandoned.
Instead:

> **The Commons is cluster-owned and self-describing.** It's installed on the
> cluster like Traefik or the `larakube-system` dashboard, and its "bylaws" live
> in the `larakube-shared` namespace as in-cluster state. The CLI is **stateless**
> about it — every `plex:*` command reads truth from the cluster, so it works from
> any machine with kubectl and depends on **no** repo.

The HOA metaphor still works, just relocated: **the cluster is the building, the
operator is the HOA, and the bylaws are posted in `larakube-shared`** (a ConfigMap
anyone with access can read). No single homeowner (app) runs it — so there is no
owner-app to go unmaintained.

Two pieces of in-cluster truth (both in `larakube-shared`, see §4):
- **`plex-commons` ConfigMap — the spec ("what the Commons IS").** Which services
  are enabled, image versions, resource/PVC sizes, larakube version, created-at.
  The source of truth `plex:init` writes and `plex:status` reads.
- **`plex-registry` ConfigMap — the tenants ("who USES it").** Each tenant's db
  name + Redis index. (Passwords live only in each tenant's own Secret, never here.)

**So `plex:init` needs no repo** — it's an operator action against a cluster, same
category as `cloud:provision`. Run it once after provisioning the droplet.

**Disaster recovery / GitOps (optional):** the in-cluster ConfigMap is the runtime
truth, but it dies with the cluster. `plex:export` dumps the spec to YAML/JSON you
can commit *anywhere* (or keep under `~/.larakube/`), and `plex:init --from <file>`
rebuilds an identical Commons — version-control & reproducibility without making
any app the owner. (Backing up the actual DATA is separate — see §11.)

## 1b. Which services can be a Commons service?

Exactly the services LaraKube can already mark **`managed`** — i.e. whose driver
implements `RemovableWhenManaged` (`app/Contracts/RemovableWhenManaged.php`):

| Category | Driver enum (`app/Enums/`) | Commons-eligible |
|---|---|---|
| Database | `DatabaseDriver` | PostgreSQL, MySQL, MariaDB |
| Cache | `CacheDriver` | Redis, Memcached |
| Search | `ScoutDriver` | Meilisearch *(RAM-heavy → opt-in)* |
| Storage | `StorageDriver` | object storage is already external; not a Commons pod |

**Never eligible:** app compute — `web`, `queues`, `horizon`, `reverb`,
`scheduler` (per-tenant by definition). The rule is clean: **a service can be a
Commons service iff it can be `managed`.** MVP ships **Postgres + Redis** (the
$12/2GB sweet spot); Meili is opt-in via `--with-meili`.

## 1c. When is the Commons "decided"?

- **Composition** — decided by the **operator at `plex:init`** (`--with-meili`,
  sizes, db engine). Because it's cluster state it is **mutable later** (re-run
  `plex:init` to reconcile / add a service), not frozen at first run.
- **Per-tenant usage** — decided by the **developer at `plex:join`**, which reads
  the app's declared drivers (db / cache / scout) and **ensures the Commons
  provides them**, expanding the Commons spec on demand if a service is missing
  (the first app that uses Scout adds Meili, etc.). The Commons is therefore the
  **union of what its tenants need** — demand-driven, not an operator guess.

---

## 1d. Entry points & lifecycle — how Plex enters the picture

Plex is **never** an app-authoring decision and **always** an additive,
deploy-time one. The rules that keep this from sprawling:

1. **Apps are authored Plex-agnostic.** `larakube new` / `init` ask which services
   an app uses (db/cache/scout) — and that is *all* Plex ever needs. They do **not**
   ask "will you join a Plex?" A blueprint is identical whether a service runs as
   its own pod, on a Commons, or on a managed DB — only a host + the `managed`
   flag differ, and those are set at join time when a real server exists. Adding a
   Plex prompt to `new`/`init` would be premature (no cluster yet) and would couple
   the blueprint to topology — the exact thing the `managed`-host seam avoids.
   **Recommendation: leave `new`/`init` untouched.**
2. **Any LaraKube server is Plex-eligible, at any time.** `cloud:provision` preps
   the box project-agnostically; the Commons is just a new `larakube-shared`
   namespace. So a box where you already ran `cloud:provision` + `cloud:deploy`
   (or GHA) for app #1 can become a Plex host later — **adding a Commons never
   touches app #1.**
3. **Plex enters at join time, via `plex:join` — the primary entry point.** Run it
   in the app you want to share a box. If the cluster isn't a Plex yet, join offers
   to **bootstrap the Commons on the spot** (seeded from that app's services). That
   makes `plex:init` *optional* — an operator pre-provision for when you want the
   Commons up (or sized) before any tenant.
4. **The Commons composition is demand-driven** (see §1c): the first joiner seeds
   it from its services; later joiners expand it. You never guess up front.
5. **Apps may mix.** App #1 can keep its own self-contained Postgres while app #2
   uses the Commons. Plex is per-app opt-in, not all-or-nothing.

**The two scenarios:**

- **A — planned co-host (apps not all built yet).** `cloud:provision` the box →
  `plex:join` app #1 (bootstraps the Commons) → `plex:join` app #2 (reuses it).
  Both deploy normally via `cloud:deploy` / GHA thereafter.
- **B — retrofit (app #1 already deployed solo).** The box is already Plex-eligible.
  For a **new** app #2: just `plex:join` app #2 — done, no migration, app #1
  untouched. Moving **app #1's existing data** onto the Commons is the one
  non-trivial path: `pg_dump` → create the Commons DB → restore → flip app #1 to
  `managed` + host → redeploy. **New apps join free; only *moving live data* costs
  a migration** (documented runbook, later phase). Often the right call is simply
  **mixed mode** (rule 5) — leave app #1 as-is.

---

## 2. The commands

All live in a new `app/Commands/Plex/` directory, extend
`LaravelZero\Framework\Commands\Command`, and use the standard trait stack
(`LaraKubeOutput`, `InteractsWithProjectConfig`, `InteractsWithEnvironments`,
`InteractsWithClusterContext`). A new `app/Traits/InteractsWithPlex.php` holds the
shared Commons/psql/registry helpers.

### `plex:init {environment?}` — stand up the Commons *(optional — see §1d)*

Provisions (or idempotently re-applies) the shared services on the **current
kube-context's cluster**. **Optional:** the first `plex:join` auto-bootstraps the
Commons if you skip it. Reach for `plex:init` when you want to pre-provision (or
pin sizes/services) before any tenant joins.

```
1. Confirm/select kube-context (InteractsWithClusterContext) — guard against
   pointing at the wrong cluster (reuse validateContextForEnvironment).
2. Render the Commons manifests (see §4) for namespace `larakube-shared`:
   - Postgres Deployment + ClusterIP Service + PVC + admin Secret
   - Redis    Deployment + ClusterIP Service
   - the `plex-registry` ConfigMap (tenant allocations) + admin Secret
   - resource requests/limits on everything (plan §3 "fairness")
3. kubectl apply -f (idempotent). Wait for rollout (kubectl rollout status).
4. Print the Commons FQDNs tenants will use:
     postgres.larakube-shared.svc.cluster.local:5432
     redis.larakube-shared.svc.cluster.local:6379
```

- **CI/CD-expressible:** it's just `kubectl apply` of static manifests + a
  readiness wait — runnable from a workflow if you ever want a GitOps "commons"
  repo. MVP: run it once locally against the droplet.
- **`--with-meili` flag:** off by default (RAM hog — plan §Capacity).
- **Re-run safety:** apply is declarative; the PVC keeps data. Never `delete`.

### `plex:join {environment?}` — register THIS project as a Tenant

Run from inside a tenant repo (needs kubectl access to the Commons cluster).

```
1. Load .larakube.json (InteractsWithProjectConfig); resolve tenant name + env.
2. If the cluster has no Commons yet, offer to bootstrap it (plex:init) seeded
   from this app's declared services — so `plex:join` is a valid standalone entry
   point (§1d).
3. Ensure the Commons provides this app's services: for each declared driver
   (db/cache/scout) missing from `plex-commons`, expand the spec + re-apply (the
   first app to use Scout adds Meili). Composition is demand-driven (§1c/§1d).
4. Allocate in the Commons (idempotent, via kubectl exec into the Postgres pod —
   peer auth, no password needed inside the pod; see §5):
     - database  <tenant>           (CREATE DATABASE IF NOT EXISTS-style guard)
     - role       <tenant> LOGIN PASSWORD '<generated>'  (ALTER on --rotate)
     - GRANT ALL ON DATABASE <tenant> TO <tenant>  (scoped; no cross-tenant read)
     - Redis logical DB index: next free 0–15 (record in plex-registry)
5. Record the allocation in the `plex-registry` ConfigMap (db name + redis index;
   NOT the password).
6. Write tenant config:
     a. .env.{env}:  DB_HOST=postgres.larakube-shared.svc.cluster.local
                     DB_DATABASE=<tenant> DB_USERNAME=<tenant> DB_PASSWORD=<gen>
                     REDIS_HOST=redis.larakube-shared.svc.cluster.local
                     REDIS_DB=<index>
        (via GeneratesProjectInfrastructure::syncEnvFile — lock-aware)
     b. .larakube.json: environments[env].managed += [postgres, redis]
        so the app's overlay STOPS deploying those pods (RemovableWhenManaged
        delete-patches — see §3 of the explore map / GeneratesProjectInfrastructure).
7. Tell the user to commit .larakube.json and re-run `gha:configure {env}` so the
   updated .env.{env} is re-uploaded as the {ENV}_ENV_FILE_BASE64 secret.
```

- **Why one-time + manual (MVP):** credential creation + secret upload is a
  deliberate, auditable step. A fully CI-driven `plex:ensure` (idempotent join in
  the workflow) is a Phase 3+ option, not MVP.
- **`--rotate`:** `ALTER ROLE ... PASSWORD` + rewrite `.env` (then re-`gha:configure`).

### `plex:export` / `plex:init --from <file>` — DR & GitOps (optional)

`plex:export` dumps the live `plex-commons` spec (§1a) to YAML/JSON you can commit
anywhere or keep under `~/.larakube/`. `plex:init --from <file>` rebuilds an
identical Commons on a fresh cluster. This is the reproducibility story **without**
making any app repo the owner. (Data backup/restore is separate — §11.)

### `plex:status` — what's on the box

```
- Read plex-registry ConfigMap → list tenants (db, redis index).
- kubectl top pods -n larakube-shared (+ per-tenant namespaces if metrics-server)
  → RAM headroom; warn when near the $12/2GB budget (plan §Capacity).
- Show Commons health (rollout/ready, PVC usage).
```

### `plex:leave {environment?}` — deprovision a Tenant (strong confirm)

```
- Require typing the tenant name to confirm (mirror `uninstall`'s guard).
- DROP DATABASE <tenant>; DROP ROLE <tenant>; free the Redis index.
- Remove from plex-registry.
- Revert .larakube.json (drop postgres/redis from managed) + note .env edits.
- Does NOT touch the Commons itself or other tenants.
```

---

## 3. Cross-namespace host resolution

A managed service's host comes from `EnvironmentData::$hosts[$service]` /
the injected `DB_HOST` env, so the **MVP needs no code change**: `plex:join`
writes the literal `postgres.larakube-shared.svc.cluster.local` into `.env.{env}`.
Kubernetes DNS resolves ClusterIP services across namespaces by FQDN, so a pod in
`app-one-production` reaches the Commons in `larakube-shared` fine.

> **Optional sugar (Phase 2+):** `ConfigData::getInternalFqdn()`
> (`app/Data/ConfigData.php`) hardcodes the project's own namespace. Add a
> per-env `sharedServices: true` (or a `managedHosts` map) so a managed in-cluster
> service auto-resolves to `{service}.larakube-shared.svc.cluster.local` without
> the user/`join` writing the FQDN by hand. Nice-to-have, not required.

---

## 4. The Commons manifests (new: `resources/views/k8s/plex/`)

Mirror the existing single-project data services and the system-namespace
precedent:

- **Postgres** — model on `resources/views/k8s/postgres/deployment.blade.php`
  (+ `volumes.blade.php`), but: namespace `larakube-shared`, a fixed admin Secret,
  resource requests/limits, and a PVC sized for shared use (start 5–10Gi vs the
  per-project 1Gi).
- **Redis** — model on `resources/views/k8s/redis/deployment.blade.php`
  (stateless; add limits).
- **Namespace + labels** — model on `resources/views/k8s/system-dashboard.blade.php`
  (namespace `larakube-shared`, label `larakube.io/managed-by: larakube`).
- **`plex-commons` ConfigMap** — the spec / "bylaws": enabled services, image
  versions, resource + PVC sizes, larakube version, created-at (§1a).
- **`plex-registry` ConfigMap** — `{tenant: {db, redis_index}}` allocation table.
- **admin Secret** — the Commons Postgres superuser password (used by init; tenant
  roles get their own scoped creds).

Resource limits are the **only** isolation mechanism in the MVP (plan §3: soft,
trusted, single-owner). No NetworkPolicy/ResourceQuota/RBAC walls yet.

---

## 5. Credential creation mechanism

`plex:join` talks to the Commons Postgres via `kubectl exec` into its pod — no
network exposure, and inside the pod `psql -U postgres` uses local peer/trust
auth (no password needed):

```bash
kubectl exec -n larakube-shared deploy/postgres -- \
  psql -U postgres -v ON_ERROR_STOP=1 -c "
    SELECT 'CREATE DATABASE \"app_one\"'
      WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname='app_one')\gexec
    DO \$\$ BEGIN
      IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='app_one') THEN
        CREATE ROLE \"app_one\" LOGIN PASSWORD '••••••';
      END IF;
    END \$\$;
    GRANT ALL PRIVILEGES ON DATABASE \"app_one\" TO \"app_one\";
  "
```

The generated password is written **only** to the tenant's `.env.{env}` (→ its
`laravel-secrets` Secret on deploy via the existing
`kubectl create secret generic laravel-secrets` step in `UpCommand`). The Commons
never stores tenant passwords; `plex-registry` only tracks db name + redis index
for `status`/capacity.

**Redis:** assign the next free logical DB index (0–15) and set `REDIS_DB`.
Logical indexes have no auth boundary (documented weakness, plan §Security);
Redis 6 ACL users are the hardened upgrade for later.

---

## 6. CI/CD integration (GitHub Actions)

Nothing new in the workflow. The existing `cloud-pilot-deploy.blade.php` already:
checks out, builds + pushes the image to GHCR, sets the kube-context from the
`{ENV}_KUBECONFIG` secret, creates ConfigMap/Secret from the `.env`, and runs
`kubectl apply -k .infrastructure/k8s/overlays/{env}`.

After a `plex:join`:
- The overlay no longer contains the app's Postgres/Redis (they're `managed` →
  `RemovableWhenManaged` delete-patches), so apply just brings up `web`/workers.
- The Secret created from the synced `.env.{env}` carries `DB_HOST` (Commons FQDN)
  + `DB_PASSWORD`, so the app connects to the Commons.

**Operator action after join:** re-run `larakube gha:configure {env}` so the
updated `.env.{env}` is re-uploaded as `{ENV}_ENV_FILE_BASE64`. (That's the
existing `GhaConfigureCommand` flow — `app/Commands/Github/GhaConfigureCommand.php`.)

> The Commons itself (`plex:init`) is provisioned once out-of-band. If you want it
> GitOps too, give it a tiny dedicated repo/workflow that runs `plex:init` (pure
> `kubectl apply`) against the cluster — but that's optional.

---

## 7. `cloud:deploy` integration

`CloudDeployCommand` → prompts domain → checks context → optionally build/push →
`$this->call('up', [...])`. **No change needed** for tenants: a joined app's `up`
applies the overlay (sans its own DB pods) and injects the Commons-pointing env.

Add (Phase 3) a **capacity pre-flight** here: before deploying a tenant, read
`plex-registry` + `kubectl top` and warn if the box is near the 2GB budget
(plan §Capacity).

---

## 8. Worked example — `app-one` + `app-two` on one DO droplet

Assumes a fresh single-node DO droplet (the $12/2GB tier).

```bash
# --- once, operator, against the droplet's kube-context ---
larakube cloud:provision            # k3s + Traefik + swap (existing)
larakube plex:init production       # Commons: Postgres + Redis in larakube-shared

# --- in the app-one repo ---
larakube plex:join production       # db app_one + role + creds; managed=[pg,redis]
git add .larakube.json && git commit -m "join plex"
larakube gha:configure production   # re-upload .env.production secret
git push                            # GHA deploys app-one against the Commons

# --- in the app-two repo (identical) ---
larakube plex:join production
git add .larakube.json && git commit -m "join plex" && git push
larakube gha:configure production

# --- verify ---
larakube plex:status                # 2 tenants, RAM headroom
# cross-tenant read MUST fail (app_two role cannot read app_one db)
```

Both apps share one Postgres + one Redis; each has its own database + login;
neither can read the other's data — "exactly like two apps sharing one RDS"
(plan §Security headline). Graduating later = change `DB_HOST` to a DO Managed DB
endpoint and redeploy; no manifest surgery (plan §Evolution).

---

## 9. Files to create / touch

| Path | What |
|---|---|
| `app/Commands/Plex/PlexInitCommand.php` | `plex:init` |
| `app/Commands/Plex/PlexJoinCommand.php` | `plex:join` |
| `app/Commands/Plex/PlexStatusCommand.php` | `plex:status` |
| `app/Commands/Plex/PlexLeaveCommand.php` | `plex:leave` |
| `app/Commands/Plex/PlexExportCommand.php` | `plex:export` (DR/GitOps spec dump) |
| `app/Traits/InteractsWithPlex.php` | Commons apply, psql-exec helpers, registry read/write, Redis index alloc — keep the parseable bits **pure** for unit tests (cf. `resolveK3dClusterName`) |
| `resources/views/k8s/plex/*.blade.php` | Commons namespace, Postgres (+PVC+secret), Redis, registry ConfigMap |
| `app/Data/EnvironmentData.php` | *(optional)* `sharedServices` / `managedHosts` for auto-FQDN |
| `app/Data/ConfigData.php` | *(optional)* `getInternalFqdn()` shared-namespace awareness |
| `tests/Unit/PlexAllocationTest.php` | pure tests: redis-index allocation, tenant-name → db/role mapping, registry merge |
| `docs/docs/architecture/the-plex.md` | user docs (plan §Relationship) |

---

## 10. Phasing (maps to the plan's §Phased delivery)

- **Phase 1 — Commons release.** `plex:init` + manifests; services stand up; no
  tenant wiring. Verify on the droplet.
- **Phase 2 — Tenant join.** `plex:join` (DB/role/creds, `.env` writeback, managed
  wiring), one app live against the Commons **via CI/CD**, not just local.
- **Phase 3 — Second tenant + fairness.** Limits everywhere, `plex:status`,
  registry, capacity warning in `cloud:deploy`. `app-one`+`app-two` coexist.
- **Phase 4 — Graduation + multi-node + Meili + hardening.** Host-swap to DO
  Managed DB; same blueprint on multi-node; optional shared Meili; Redis ACL
  users / NetworkPolicies docs.

---

## 11. Open decisions to resolve before/while building

1. **Backups.** One Postgres, many tenant DBs → per-database `pg_dump` (a
   `plex:backup` later?) vs whole-PVC snapshot. (plan §Risks)
2. **Idempotent CREATE DATABASE.** Postgres has no `IF NOT EXISTS` for DB; use the
   `SELECT … \gexec` guard shown in §5, or catch the duplicate error.
3. **Where the Commons manifests live for GitOps.** Out-of-band `plex:init` (MVP)
   vs a dedicated commons workflow/repo. Pick when Phase 1 lands.
4. **Auto-FQDN sugar vs literal host in `.env`.** Ship MVP with literal host;
   decide if `sharedServices: true` is worth the schema addition.
5. **Multi-node Commons.** Single in-cluster instance is shared-fate (fine for
   hobbyist). Multi-node HA = graduate the host to a managed DB — same `managed`
   mechanism, different host only. No new code, just docs + a verification.

---

## 12. Verification checklist (from the plan §Verification)

- [ ] Two demo apps on a real $12 droplet, own domains, sharing one PG + Redis.
- [ ] Cross-tenant query attempt **fails** (role isolation proven).
- [ ] OOM test: hammer tenant A; limits keep tenant B serving.
- [ ] Graduation: flip `DB_HOST` to a DO Managed DB; only `.env` + redeploy.
- [ ] Rollout test: deploy a new image to A; B stays up (no shared-fate on deploy).
- [ ] CI/CD path proven (not just local `cloud:deploy`).
