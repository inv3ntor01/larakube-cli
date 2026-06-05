# Plan: Per-app per-environment scoped deploy credentials

## 🎯 Objective

Stop shipping the **k3s cluster-admin** cert to CI. Each `(app, environment)` pair
gets a namespaced ServiceAccount whose kubeconfig can touch **only** its own
`{app}-{env}` namespace. The admin cert stays on the developer's machine (it's the
bootstrapping authority and never leaves); the credential that *leaves* the
machine — the `{ENV}_KUBECONFIG` GitHub secret — is namespace-locked.

Per-app **per-environment** is the natural unit: it maps 1:1 to the existing
`{app}-{env}` namespace, so a plain namespaced `Role` (not a `ClusterRole`) is
enough — least privilege by construction.

## 🔐 Security model — two planes

| Plane | What | Governed by |
|-------|------|-------------|
| Control plane | `kubectl` create/patch/delete via the K8s API | RBAC → our scoped SA |
| Data plane | app pod → `svc.cluster.local` traffic + creds in `.env` | network + the credential |

- **Locally** you stay **admin** (`larakube-{ip}` context). Only admin can mint
  ServiceAccounts/Roles, and it's your own machine — no leak surface.
- **CI** only ever sees the **scoped** SA kubeconfig. A leaked `STAGING_KUBECONFIG`
  can touch staging and nothing else. Blast radius = one namespace.

## 🧩 Plex interaction — orthogonal, no special-casing

A Plex tenant **connects to** the shared Commons (`larakube-shared`) over the
**data plane**: Service DNS (`postgres.larakube-shared.svc:5432`) + the
host/db/user/password `plex:join` already baked into `.env.{env}`. Cross-namespace
Service DNS needs **no RBAC** on the Commons namespace.

- `plex:init` / `plex:join` are developer-run, **admin-context** commands — they
  provision Commons DB/user/bucket with full access. Not the scoped SA.
- The CI deployer only writes the app's own ConfigMap/Secret (carrying the Commons
  connection string) into `{app}-{env}` — already inside the scoped Role.
- env-sync already **skips** recomputing Plex components, so deploy won't clobber
  the Commons values.

Caveat to document: deny-all NetworkPolicies would need an explicit
`{app}-{env} → larakube-shared` allow. Default k3s/DOKS don't enforce it → out of
scope for v1. Optional future nicety: a read-only `get services` RoleBinding for
the tenant SA *in* the Commons namespace, purely for a pre-deploy health check —
not needed for connectivity.

## 🧱 The scoped Role (RED-LINE THIS)

Namespaced `Role` named `deployer` in `{app}-{env}`. Derived from the actual Kinds
emitted by a **cloud** overlay (verified: cloud overlays emit only namespaced
resources — PV/ClusterRole are gated to `local`/`isSystem()` and never appear).

```yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: deployer
  namespace: {app}-{env}
  labels:
    app.kubernetes.io/managed-by: larakube
    larakube.dev/app: {app}
    larakube.dev/env: {env}
rules:
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "replicasets"]
    verbs: ["get","list","watch","create","update","patch","delete"]
  - apiGroups: ["apps"]
    resources: ["deployments/status", "statefulsets/status"]
    verbs: ["get","list","watch"]
  - apiGroups: ["batch"]
    resources: ["cronjobs", "jobs"]
    verbs: ["get","list","watch","create","update","patch","delete"]
  - apiGroups: [""]
    resources: ["services","configmaps","secrets","persistentvolumeclaims","serviceaccounts"]
    verbs: ["get","list","watch","create","update","patch","delete"]
  - apiGroups: [""]
    resources: ["pods","pods/log"]
    verbs: ["get","list","watch"]
  - apiGroups: [""]
    resources: ["pods/exec"]
    verbs: ["create","get"]            # artisan / migrations via kubectl exec
  - apiGroups: [""]
    resources: ["events"]
    verbs: ["get","list","watch"]      # debugging
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get","list","watch","create","update","patch","delete"]
```

**Stays admin-only** (cluster-scoped, bootstrapped by the local admin, never the
scoped SA): `Namespace` (create), `PersistentVolume`, `ClusterRole/Binding`,
MetalLB `IPAddressPool`/`L2Advertisement`, Traefik install.

## 🔑 Token longevity

- **Local dogfood (Option A):** `kubectl create token deployer -n {app}-{env}` —
  short-lived, used for the apply immediately, then discarded. Admin stays the
  persistent local credential; the scoped one is ephemeral locally.
- **CI handoff (`gha:configure`):** a long-lived `kubernetes.io/service-account-token`
  **Secret** (standard non-expiring pattern). Build the kubeconfig from it, upload
  as `{ENV}_KUBECONFIG`. Re-mintable; `rbacGrantedAt` records the last mint.

## 🗂 Schema change — `CloudData`

No `credentialMode` (it's always scoped → redundant; presence of the marker *is*
the state). Add one field:

```php
// CloudData
public ?string $rbacGrantedAt = null,   // ISO-8601; last CI-token mint. null = not yet granted.
```

`cloud.user` is the **SSH** login — leave untouched. SA name + namespace are
deterministic (`deployer` in `{app}-{env}`), so they are derived, never stored.

## 🛠 Commands

**Who mints the SA:** local **admin-context** commands only — `cloud:deploy`
(manual path) and `gha:configure` (CI path). The GHA **runner never mints** it: by
design it holds only the scoped creds (no admin), and a namespace-scoped token
can't create RBAC anyway. The runner is a pure **consumer** of `{ENV}_KUBECONFIG`.
Both minters share one idempotent `ensureScopedRbac()` (namespace + SA + Role +
RoleBinding via admin); they differ only in the token.

- **`cloud:deploy` (Option A):** `ensureScopedRbac()` → mint **short** token →
  **apply the overlay with the scoped token** (dogfoods the Role; missing perms
  surface locally where admin can fix them). Re-applies every deploy → self-heals
  a widened Role after a CLI upgrade.
- **`gha:configure`:** `ensureScopedRbac()` → mint **Secret-bound** (long-lived)
  token → assemble scoped kubeconfig → upload `{ENV}_KUBECONFIG`. Replaces the
  admin-cert upload. Stamp `rbacGrantedAt`.

**Role-refresh caveat:** the scoped SA cannot modify its own `Role`, so a CLI
upgrade that *widens* the Role only takes effect when a **local admin** re-applies
it. `cloud:deploy` does this automatically each run; a **pure-GHA** user must
re-run `gha:configure` (or a future `cluster:grant` refresh — task #7 family).
- **`cluster:users`** (new, under the `cluster:*` family; user called it
  `context:users`): list everything labeled `app.kubernetes.io/managed-by=larakube`
  across namespaces, rendered with **Laravel Prompts `table()`**:

  ```
  NAMESPACE             SERVICEACCOUNT   APP        ENV          TOKEN
  myapp-production      deployer         myapp      production   ✅ active
  myapp-staging         deployer         myapp      staging      ✅ active
  ```

  **Scope detail.** With a namespace argument (or by selecting a row), expand to
  show the credential's *actual* permissions, read from the **live `Role`**
  (`kubectl get role deployer -n {ns} -o json`, not our template — so drift is
  visible), rendered as a second `table()`:

  ```
  larakube cluster:users myapp-production

  RBAC scope — deployer @ myapp-production
  API GROUP          RESOURCES                                    VERBS
  (core)             services, configmaps, secrets, pvc, sa       get,list,watch,create,update,patch,delete
  (core)             pods, pods/log                               get,list,watch
  (core)             pods/exec                                    create,get
  apps               deployments, statefulsets, replicasets       get,list,watch,create,update,patch,delete
  batch              cronjobs, jobs                               get,list,watch,create,update,patch,delete
  networking.k8s.io  ingresses                                    get,list,watch,create,update,patch,delete

  Binding: ✅ RoleBinding "deployer" → ServiceAccount "deployer"
  Token:   ✅ active   Granted: 2026-06-05T12:00:00Z
  ```

  One row per `Role` rule (apiGroups × resources × verbs). Also surfaces whether
  the `RoleBinding` actually binds the SA (an SA with no binding = no scope) and
  the token state — so the detail view doubles as a "is this credential wired up
  and what can it touch" audit.

## 🧪 Build order

1. `InteractsWithScopedRbac` trait — **pure** builders (SA/Role/RoleBinding YAML,
   token mint cmd, kubeconfig assembly from server URL + CA + token + namespace).
   Unit-testable, no I/O.
2. Wire into `cloud:deploy` (Option A) — bootstrap, then scoped apply.
3. **Test on the new Droplet** — close the VPS gap.
4. `gha:configure` — Secret-bound token + scoped kubeconfig upload; stamp marker.
5. `cluster:users` — list (Prompt Table) + scope detail (live-Role Prompt Table).
6. `rbacGrantedAt` on `CloudData`.
7. Docs page.

## 🔎 Gaps found on review (record now, mostly follow-ups)

1. **DOKS context resolution** — `cloud:deploy` is VPS-centric (`cloud.ip` →
   `larakube-{ip}`). DOKS contexts are doctl-named with no `cloud.ip`. The scoped
   flow pulls **server URL + CA** from the admin context, so add a `--context`
   override + env→context resolution. VPS works now; DOKS is a follow-up.
2. **Token Secret is async** — after creating the SA-token `Secret` (CI), poll
   until `.data.token` is populated before reading it.
3. **RBAC propagation lag** — Option A applies with the fresh token immediately;
   the `RoleBinding` may take a sub-second to take effect → small retry/backoff on
   the first scoped call.
4. **kubectl ≥ 1.24 preflight** — `create token` requires it; fail clearly if older.
5. **Offboarding/rotation** — `cluster:revoke {app} {env}` (delete SA/Role/Binding)
   + `--rotate` (replace the token Secret) to kill/rotate a leaked CI cred.
   Security-critical follow-up.
6. **Naming overlap** — `cloud:configure users` (SSH teammates) vs `cluster:users`
   (K8s SA). Different namespaces; document the distinction.

## ❓ Open for red-line

- The Role rule set above — anything too broad (`secrets` CRUD?) or missing
  (`horizontalpodautoscalers` if HPA lands later)?
- `cluster:users` name vs alias `context:users`.
- Marker field name `rbacGrantedAt`.
