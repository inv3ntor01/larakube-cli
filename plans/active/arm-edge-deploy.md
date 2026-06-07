# Plan: ARM / edge (single-board) deploy tier

## 🎯 Vision

Single-board computers (Raspberry Pi 4/5 8GB, Orange Pi, etc.) are now powerful
enough to run a real Laravel app. That makes them a great **cheapest tier** —
school projects, demos, homelab — and LaraKube's edge is the **portability
promise**: the *same blueprint* deploys to a Pi, a $6 VPS, or managed Kubernetes
(DOKS/EKS/GKE), and moving *up* is a one-line target change, not a rewrite.
"Build it on the Pi on your desk, then `cloud:deploy` it to DOKS for the demo."

## 🧱 Near-term: make the deploy arch-aware (prerequisite)

> **Status (2026-06-07): Phase 1 BUILT (unbuilt binary — user rebuilds).** Both
> deploy paths now resolve the platform instead of hardcoding amd64:
> `resolveDeployPlatform()` in `InteractsWithRemoteDeploy` picks
> `cloud.arch` override → SSH `uname -m` (sideload path) → kubectl node-arch
> (registry/managed path, e.g. DOKS) → `linux/amd64` fallback. New nullable
> `CloudData::$arch`. Command-builders take a `$platform` param (default amd64,
> so snapshots/old tests are unchanged). Mixed-arch managed pools resolve to
> null → safe amd64 default. Unit tests added in `RemoteDeployTest`. Testable on
> the existing **VPS staging** (SSH path) now; DOKS exercises the kubectl path
> (resolves amd64). Remaining: Phase 2 (Pi docs) + Phase 3 (tunnel).

The single blocker today is architecture. `cloud:deploy` hardcodes
`docker buildx build --platform linux/amd64` (assumes a DO droplet). A Pi is
**arm64** → the amd64 image gives `exec format error`, pod never starts.

Fix (small, and future-proofs ARM VPSes too — Graviton/Ampere):

- **Resolve the node arch** instead of hardcoding amd64. Options:
  - detect over SSH at deploy time (`uname -m` → `aarch64` → `linux/arm64`), or
  - a per-env knob `cloud.arch` (`amd64` | `arm64`), defaulting to amd64.
  - Probably: detect for VPS/sideload (we already SSH in), knob as override.
- Feed the resolved platform into `buildProductionImageCommand` /
  `buildAndPushImageCommand` (currently `linux/amd64` literal).
- Native build bonus: Apple-Silicon Mac → arm64 Pi is a *native* build (faster,
  no emulation), vs today's forced amd64 cross-build.
- Manifests: `imagePullPolicy: IfNotPresent` already set for sideload; arch
  doesn't change manifests, only the image build.

This benefits **any ARM target**, not just Pi — so it's worth doing regardless.

## 🌐 Reachability (mostly the user's network, document it)

A home Pi has **no public IP** — and often **CGNAT** (no inbound at all) or
ISP-blocked 80/443. So "reachable on the internet" is a network problem, not a
LaraKube feature:

- **LAN-only:** trivial — the Pi's local IP, no extra setup. (Great for demos.)
- **Internet, no CGNAT:** port-forward 80/443 (+22) on the router + **dynamic DNS**.
- **Internet, CGNAT / blocked ports:** a **tunnel** — Cloudflare Tunnel or
  Tailscale Funnel — which also sidesteps the public-IP requirement entirely.
- **TLS:** Let's Encrypt HTTP-01 needs port 80 reachable → broken under CGNAT.
  Use **DNS-01**, or a tunnel that terminates TLS (Cloudflare).

Possible future feature: a Cloudflare-Tunnel integration (vs. only docs) so a
home/edge node can be exposed without router surgery. (Relates to the existing
local-dev tunneling story — different use case.)

## 🐧 k3s-on-Pi nuances (for the docs)

- 64-bit OS required; enable cgroups: add `cgroup_memory=1 cgroup_enable=memory`
  to `/boot/cmdline.txt` (k3s needs it for memory limits).
- Single-node → k3s `local-path` storage is fine (no block-storage class needed).
- `cloud:provision` already installs k3s + hardens — should mostly "just work"
  on a Pi once arch is handled and SSH is reachable.

## 🚦 Phases

1. **Arch-aware deploy** — node-arch detection + `cloud.arch` override; thread the
   platform through the buildx commands. (The real unlock.)
2. **Pi quickstart docs** — `deployment/raspberry-pi.md`: provision over LAN SSH,
   cgroups note, LAN-vs-internet, Cloudflare Tunnel for CGNAT, TLS implications,
   and the "move it to DOKS" upgrade path (the portability promise in action).
3. **(Later) Tunnel integration** — optional Cloudflare-Tunnel wiring so edge
   nodes are internet-reachable without port-forwarding.

## ✅ Verification

- Deploy the same blueprint to a Pi (arm64) — image builds `linux/arm64`,
  side-loads, pod runs (no `exec format error`).
- Re-target the env at a DOKS context + registry and `cloud:deploy` — the *same*
  project lands on managed Kubernetes with no blueprint changes beyond the target.
