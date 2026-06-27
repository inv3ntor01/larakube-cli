# End-to-End Test Pass — Full Flow Verification

A full manual pass across every major deploy path and scaffolding command to confirm the BuildKit secret approach, Plex integration, and hostname pre-configuration all behave correctly end-to-end.

---

## 1. `larakube new` and `larakube init`

- [ ] `larakube new` — full wizard: app name, services, environment, scaffold output
- [ ] `larakube init` on an existing Laravel project — Dockerfile.php, manifests, `.larakube.json` written correctly
- [ ] Confirm `assets` stage present in generated `Dockerfile.php` (FROM base + Node install)
- [ ] Confirm no `--build-arg VITE_*` references in generated Dockerfiles

---

## 2. VPS Deploy (single-node, no Plex)

### Manual (`cloud:deploy`)
- [ ] `larakube env production` — hostname prompts, config written
- [ ] `cloud:deploy` — SSH side-load path: BuildKit secret mounted, image built, deployed
- [ ] Verify VITE_* vars baked correctly (open app in browser, check asset URLs)
- [ ] Verify no secrets in image layers (`docker history`)

### GitHub Actions
- [ ] GHA workflow generated correctly (`cloud:deploy` or `cloud-pilot-deploy.blade.php`)
- [ ] `secret-files: dotenv=.env` present in build-push-action step
- [ ] Deploy succeeds end-to-end from a push to main

---

## 3. VPS Deploy (single-node, with Plex)

### Manual (`cloud:deploy`)
- [ ] `plex:join` adds project to existing Commons cluster
- [ ] `cloud:deploy` deploys into Plex namespace correctly
- [ ] Shared services (DB, Redis, MinIO) resolve from Commons
- [ ] VITE_* vars still baked correctly via BuildKit secret

### GitHub Actions
- [ ] GHA workflow accounts for Plex namespace
- [ ] Deploy succeeds into the Plex cluster

---

## 4. DOKS Deploy (multi-node, no Plex)

### Manual (`cloud:deploy` → registry push)
- [ ] `cloud:configure registry` — registry credentials saved
- [ ] `cloud:deploy` — registry push path: `--push`, BuildKit secret mounted
- [ ] Image pushed to GHCR / Docker Hub, pulled by DOKS nodes
- [ ] Verify VITE_* vars baked correctly

### GitHub Actions
- [ ] GHA workflow uses `docker/build-push-action` with `secret-files:`
- [ ] Push to registry, apply manifests to DOKS cluster
- [ ] Rolling deploy, zero-downtime verified

---

## 5. DOKS Deploy (multi-node, with Plex)

### Manual
- [ ] Plex Commons running on DOKS
- [ ] `plex:join` for project
- [ ] `cloud:deploy` → registry push into Plex namespace
- [ ] Shared services resolve correctly across nodes

### GitHub Actions
- [ ] GHA workflow with Plex namespace targeting
- [ ] Deploy succeeds

---

## 6. Air-Gapped Bundle Deploy on VPS

- [ ] `larakube env airgap --offline` — hostnames stored in `.larakube.json`, no re-prompt expected
- [ ] `bundle:build airgap --arch=amd64 --tar` — assets stage builds correctly, VITE_* vars from secret
- [ ] Bundle extracted on target VPS
- [ ] `bundle:install` — hostnames NOT re-prompted (reads from `.larakube.json`)
- [ ] TLS generated, app live over HTTPS
- [ ] `bundle:install --skip-images` — fast re-run, no hostname re-prompt, certs regenerated
- [ ] `bundle:update` — lightweight app-only update applied cleanly

---

## Notes

- Test app: see `project_firearmland_test_app.md` — do NOT reference by name in any committed output
- Rebuild CLI before each test pass: `./php vendor/bin/pint && ./build`
- Check `docker history <image>` to confirm no secrets in layers after every build
