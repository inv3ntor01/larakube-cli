# Plan: RBAC-scoped teammate access (cluster-native)

## 🎯 Objective

Give each teammate their own **scoped** access to a LaraKube cluster — read-only,
single-namespace, or full — via the Kubernetes-native mechanism (a per-person
**kubeconfig** + **RBAC** `Role`/`ClusterRole` bound to them). This works
identically on single-node and multi-node clusters and on managed services
(EKS/GKE/AKS), unlike the current SSH/OS-user `teammates`.

## 🔍 Why the current `teammates` isn't enough

Today `cloud:configure users` SSHes into the single `cloud.ip` host and runs
`useradd` + `usermod -aG sudo` + writes `authorized_keys` + grants
`NOPASSWD:ALL`. That means:

- **One box only.** It provisions an OS user on the entry node. Other nodes in a
  multi-node cluster never get it, and managed clusters often can't be SSH'd
  into at all (managed control plane, disposable nodes).
- **All-or-nothing.** A sudo login on the box where the kubeconfig lives is
  effectively full cluster-admin. There's no "read-only" or "only the
  `acme-app-production` namespace."

It's the right tool for the **Single-Node Hero / VPS** tier ("give my friend a
login on my server") and nothing more. Cluster access is a different problem,
and the K8s answer is RBAC, not OS users.

## 🧱 Design

### Identity (how a teammate authenticates to the API)

Pick one for the MVP (decide during design):

- **ServiceAccount token** — simplest: create a SA, mint a (bounded) token,
  emit a kubeconfig. Easy to revoke (delete the SA / token). Token rotation is
  the main wrinkle.
- **Client certificate (CSR API)** — `CertificateSigningRequest` → approve →
  signed cert → kubeconfig. Feels like "real" user auth, but certs can't be
  revoked without rotating the CA; use short expiries.
- **OIDC** — the production-grade answer (no per-user secrets in-cluster), but
  needs an external IdP. **Out of scope for MVP**; note as the graduation path.

Lean toward **ServiceAccount tokens** for the MVP (revocable, no CA surgery),
with client-cert as a documented alternative.

### Permission presets (what they can do)

Ship a small set of `ClusterRole`s, applied via `RoleBinding` (namespace-scoped)
or `ClusterRoleBinding` (cluster-wide):

- **`admin`** — full access (parity with today's sudo teammate).
- **`read-only`** — `get`/`list`/`watch` across the usual resources.
- **`namespace`** — scoped to the project's `{name}-{env}` namespace(s) only
  (the common "let a contractor touch staging, not production" case).

### Issuance flow

A command (e.g. `larakube cluster:access add|list|revoke`, or an RBAC mode on
`cloud:configure users`) that:

1. Creates the identity (SA/token or CSR cert).
2. Binds the chosen preset (Role/ClusterRole + binding) in the target
   namespace(s).
3. Emits a ready-to-use kubeconfig for the teammate to drop in `~/.kube/`.
4. `revoke` tears down the binding + identity.

### Schema

Open question — where RBAC teammates are declared:

- extend each `environments[env].cloud.teammates` entry with an `access`/`role`
  field (`admin` | `read-only` | `namespace`), reusing the existing list; **or**
- a separate `access` block keyed by env, leaving `cloud.teammates` as the
  SSH-only concept.

Decide based on whether RBAC **supersedes** SSH teammates for clusters or
**complements** them (single-node could still want OS logins for `larakube`
proxy commands run on the box).

## 🔗 Relationship to SSH `teammates`

- **Single-node VPS:** SSH teammates stays valid (OS login to run `larakube`/
  `kubectl` on the box). RBAC access is an optional upgrade.
- **Multi-node / managed:** RBAC is the only sane path; SSH teammates is
  inapplicable. Docs should steer cluster users to RBAC.
- The generated **GHA deploy** is a separate machine identity (its own
  kubeconfig secret) — unaffected by this; don't conflate human access with CI.

## 🚦 Phases

1. **Presets** — the three `ClusterRole`s + binding generation (namespace vs
   cluster). No identity issuance yet; validate with an existing kubeconfig.
2. **Identity** — per-teammate kubeconfig via ServiceAccount token; `add`/`revoke`.
3. **Command UX + schema** — `cluster:access` (or RBAC mode on `cloud:configure
   users`), and the blueprint field for declaring RBAC teammates.
4. **Namespace presets + docs** — the scoped preset, the graduation note to
   OIDC, and updating Blueprint Anatomy to present RBAC as the multi-node
   access story (replacing the current single-node caveat).

## ✅ Verification

- Issue a `read-only` kubeconfig; confirm `kubectl get pods` works but
  `kubectl delete` is denied.
- Issue a `namespace`-scoped kubeconfig; confirm it can act in
  `{name}-staging` but is denied in `{name}-production`.
- `revoke` removes access immediately (binding gone → API calls 403).
- Works unchanged on a multi-node cluster (RBAC is cluster-level, node-agnostic).

## ⚠️ Risks / open questions

- **Token/cert lifecycle.** Expiry + rotation + revocation story must be
  explicit (SA tokens revocable; client certs are not without CA rotation).
- **Schema placement.** Reuse `cloud.teammates` vs a dedicated block (above).
- **Don't reinvent OIDC.** For orgs that need real SSO, point at OIDC rather
  than growing an in-cluster user system.
- **kubeconfig distribution.** Emitting a kubeconfig containing a token/cert is
  a secret hand-off — guide users to deliver it securely (not committed, not
  pasted in chat).
