# LaraKube CLI — Batch Testing Checklist

Single source of truth for validating the current large staged batch (~137 files)
before tagging/committing. Detailed step-by-step lives in `manual-test-guide.md`;
this file is the tickable tracker. Test by **blast radius** — shared infrastructure
first, then per-feature.

> Build step before testing: `./php vendor/bin/pint && ./build`

---

## Phase 0 — Smoke (do first; if 0.2 fails, stop)

- [ ] `larakube about` boots, no stack trace
- [ ] `larakube up` in a test project → pods Running, summary URLs render
- [ ] `./php vendor/bin/pest` → all green, no output leaks

---

## Phase 1 — config:tld TLD propagation regression (HIGHEST RISK)

Tests this batch's `View::share` removal + fresh-TLD `deployCompanion` fix. The
original bug: Mailpit migrated to the new TLD but phpMyAdmin/RedisInsight did not.

```bash
larakube config:tld --set kube
larakube companion:add          # phpMyAdmin (needs MySQL/MariaDB) + RedisInsight (needs Redis)
larakube up                      # note hosts on .kube
larakube config:tld --set test   # chains `up` internally
kubectl get ingress -A | grep -E 'phpmyadmin|redisinsight|mailpit|grafana|console|traefik'
```

Pass — ALL on `.test`, ZERO stranded on `.kube`:
- [ ] Companions: `phpmyadmin.test`, `redisinsight.test`
- [ ] Shared services: `mailpit.test`, `grafana.test` (if monitoring active), Console, Traefik dashboard
- [ ] Project web/vite hosts: `*.<app>.test`
- [ ] Browse `https://phpmyadmin.test` AND `https://mailpit.test` → both load
- [ ] Restore: `larakube config:tld --set kube`

---

## Phase 2a — ext:add (not in manual-test-guide)

- [ ] `larakube ext:add imagick` → no crash (addAdditionalExtension fatal gone)
- [ ] `imagick` in `.larakube.json` → `additionalExtensions`
- [ ] `Dockerfile.php` `install-php-extensions` line gains `imagick`, prior exts preserved
- [ ] Re-run `ext:add imagick` → idempotent (no dup in config or Dockerfile)
- [ ] Outside a project → clean "Not a LaraKube project", exit 1, no trace

## Phase 2b — ext:remove (not in manual-test-guide)

- [ ] `larakube ext:remove imagick` → no crash (removeAdditionalExtension fatal gone)
- [ ] `imagick` removed from `.larakube.json` and stripped from the Dockerfile line, other exts intact
- [ ] `ext:remove notinstalled` → clean "not in your configuration, skipping", exit 0
- [ ] After add+remove+`larakube up`, confirm via `larakube shell` → `php -m`

---

## Phase 2c — companion:add project-aware recommendation (NEW this batch)

- [ ] In a **Postgres** project: `larakube companion:add` → pgAdmin at top, pre-selected, tagged `(recommended)`; Adminer next
- [ ] In a **MySQL/MariaDB** project: phpMyAdmin tops, tagged `(recommended)`
- [ ] Project with **Redis** cache: RedisInsight appears in the recommended set
- [ ] Outside a project (or SQLite-only) → falls back to the flat list, no errors
- [ ] Explicit `larakube companion:add pgadmin` → unchanged (no reordering)

---

## Phase 3 — Walk manual-test-guide.md §1–21 (ordered by blast radius)

- [ ] §7 Mailpit + §19–20 Monitoring (shared-cluster infra)
- [ ] §2–3 Companions / phpMyAdmin auto-config
- [ ] §15–17 Plex init/export/join + §10 plex:migrate
- [ ] §9 clone, §11 bundle CA, §21 bundle tunnel
- [ ] §4–5 tunnels/share, §6 cloud:init context
- [ ] §12 DOKS, §13 Homebrew, §14 GitLab, §18 registry

---

## Pre-commit gate

- [ ] Naming hard-rule scan: grep the staged diff for the forbidden test-app codename (see private naming memory) and for bare "LaraKube" used as a product name. Must find: zero codename hits; no bare "LaraKube" product-name (exceptions: `larakube` commands, "LaraKube CLI", "LaraKube Console", "LaraKube Local CA").

---

## Reverb realtime — NOW FIXED (test it; was previously excluded)

The two local-scaffolding gaps are fixed in this batch (`LaravelFeature` + new
`resources/views/k8s/reverb/ingress.blade.php`). Confirmed working on
`kube-examples/todo` this session.

- [x] Reverb Ingress generated — `getNetworkViewName()`/`getNetworkYamlDestination()`
      now emit `overlays/local/reverb-ingress.yaml` (`reverb.{name}.{localTld}` →
      `reverb:8080`, websecure+TLS), registered in the local overlay kustomization.
- [x] Local `VITE_REVERB_*` scaffolded — `getPublicEnvironmentVariables()` emits
      `VITE_REVERB_APP_KEY`/`HOST` (via `getServiceHost`)/`PORT=443`/`SCHEME=https`
      for the local env into the project `.env`.
- [ ] Re-verify on a second project (e.g. `kube-examples/qr-link-tracker`):
      enable reverb → `larakube up` → browser Echo connects over WSS:443, realtime
      updates without refresh.
