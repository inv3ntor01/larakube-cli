# speckit.plan: SSR Feature v2 — Project-side Scaffolding

## 🎯 Objective
Auto-scaffold the project-side files needed for Inertia SSR when a user enables the `ssr` feature, so they don't have to hand-roll the wiring per project. Currently (v1), `larakube add ssr` provisions the K8s topology (deployment, service, configmap env vars) but the user still has to manually:

- Create `resources/js/ssr.jsx` (SSR entry)
- Add `ssr` input to `vite.config.js` laravel-vite-plugin call
- Add `ssr: { noExternal: true }` block to `vite.config.js`
- Add `build:ssr` script to `package.json`
- Publish `config/inertia.php` with SSR section
- Add Ziggy share to `HandleInertiaRequests::share()` (if Ziggy is in use)
- Add CSS entry to `@vite()` in `resources/views/app.blade.php`

v2 makes all of these automatic on `larakube add ssr` (or `larakube new --ssr`).

## 🧩 Architectural Implementation

### 1. New trait: `ScaffoldsSsrFeature` (or extend existing `GeneratesProjectInfrastructure`)
A new trait that owns the project-side file mutations. Methods:
- `scaffoldSsrEntry(ConfigData $config)` — writes `resources/js/ssr.{jsx,tsx,vue,svelte}` per the project's frontend stack
- `patchViteConfig(ConfigData $config)` — adds `ssr` input + `ssr.noExternal` block
- `patchPackageJson(ConfigData $config)` — adds `"build:ssr"` script
- `publishInertiaConfig(ConfigData $config)` — copies a stub into `config/inertia.php`
- `patchInertiaMiddleware(ConfigData $config)` — adds Ziggy share to `HandleInertiaRequests::share()` (if Ziggy is in composer.json)
- `patchAppBlade(ConfigData $config)` — ensures the CSS entry is in `@vite()`

Called from `LaravelFeature::SSR->onPostInstall($projectPath, $config)`.

### 2. Frontend stack detection drives the SSR entry template
The `FrontendStack` enum already has cases. Each variant needs a different ssr entry:
- **React (Inertia v2)** — `createServer` + `@inertiajs/react/server` + `ReactDOMServer.renderToString` (the pilot-app shape)
- **React (Inertia v3)** — same as above but with the new `@inertiajs/vite` plugin assumptions (auto-on SSR; we'd need to be careful here)
- **Vue** — `createSSRApp` + `renderToString` from `@vue/server-renderer`
- **Svelte** — Svelte's built-in SSR with `@inertiajs/svelte`
- **Inertia version detection** — read `composer.json` for `inertiajs/inertia-laravel` version constraint to pick v2 vs v3 entry

Implementation: add a `getSsrEntryStub(): string` method to FrontendStack, returning the path to a stub file in `stubs/ssr/{frontend}-{version}.stub.jsx` (or similar).

### 3. Idempotent file patching
For files we're modifying (not creating fresh), each patcher must be **idempotent** — re-running `larakube add ssr` after the first run should not duplicate entries. Strategies:
- **`vite.config.js`**: check if `ssr:` key already exists before inserting. AST parsing is ideal; regex is acceptable with care.
- **`package.json`**: JSON parse → check key → add if missing → write back.
- **`HandleInertiaRequests`**: scan for `'ziggy' =>` substring before adding the share block.
- **`app.blade.php`**: scan for the CSS path string in the `@vite([...])` call before inserting.

### 4. Conflict handling
If a user has already hand-rolled some SSR wiring (like pilot-app did before v1):
- **Detect existing `ssr.jsx`**: skip the create step; warn the user.
- **Detect existing `ssr:` in vite.config.js**: skip the patch; warn.
- **Detect existing Ziggy share**: skip.

Provide a `--force` flag to overwrite, with strong confirmation prompt.

## ✅ Action Plan

1. **Stub files**: create `stubs/ssr/react-v2.stub.jsx`, `stubs/ssr/react-v3.stub.jsx`, `stubs/ssr/vue-v2.stub.js`, `stubs/ssr/vue-v3.stub.js`, `stubs/ssr/svelte.stub.js` and an `stubs/ssr/inertia-config.stub.php`.
2. **FrontendStack helpers**: add `getSsrEntryStub(int $inertiaMajorVersion): string` that returns the right stub path.
3. **Patcher helpers** (in a new trait or service):
   - `ViteConfigPatcher` — handles the ssr input + noExternal block
   - `PackageJsonPatcher` — handles the build:ssr script
   - `InertiaMiddlewarePatcher` — handles the Ziggy share
   - `AppBladePatcher` — handles the CSS entry
4. **Wire it up**: `LaravelFeature::SSR->onPostInstall()` calls all the above in order.
5. **Tests**:
   - Stub-by-stub snapshot tests (render each stub, compare)
   - Patcher tests using temp directories with synthetic before/after files
   - Idempotency tests: run the scaffold twice, second run should be a no-op
6. **Doctor check**: extend `larakube doctor` to detect "SSR feature enabled but project-side files missing" and offer to scaffold.

## 🧪 Validation Pattern

For each patcher, test:
- **Empty file case**: file exists but minimal/default → patches cleanly
- **Already-patched case**: file already has the addition → idempotent no-op
- **Conflicting case**: file has a similar but wrong version → detect + warn + don't break
- **Hand-rolled case** (pilot-app-style): user did it themselves → detect + skip

## 🚦 Phases

- **Phase 2.1** — React/Inertia v2 only (covers pilot-app-shaped projects, the proven case)
- **Phase 2.2** — Vue + Svelte stub variants
- **Phase 2.3** — Inertia v3 (will need to rethink ssr.jsx pattern given v3's plugin changes)
- **Phase 2.4** — Doctor integration

## 🔥 Strategic finding from pilot-app dogfooding (must fix in v2 OR v1.5)

**The current v1 deploys the SSR pod in `base/`, which means BOTH local and production overlays get it. This is wrong.**

After pilot-app ran with always-on local SSR for a session, the verdict is:
- **Local SSR adds a 50-200ms round-trip per page load**. Dev iteration becomes noticeably sluggish (browser → web → SSR pod → render → web → browser). Compounding with Vite recompiles, this is annoying enough to make devs disable SSR locally.
- **Local SSR gives no value**: no SEO scrapers, no cold visitors, no real users. The only reason to have it on locally is parity testing of the SSR-rendered output, which is occasional, not constant.
- **Inertia v3 makes local SSR pod *actively redundant*** — `@inertiajs/vite` plugin v3+ uses `vite-node` to do SSR rendering inside Vite's dev server. Same HMR loop, no separate process. **For v3 projects, deploying a separate `node-ssr` pod for local dev does nothing.**

### Right shape per Inertia version

| | Local SSR pod | Local SSR active | Prod SSR pod |
|---|---|---|---|
| **Inertia v2** | Optional (off by default) | Opt-in via env var | Required |
| **Inertia v3** | **Never deployed** | Vite handles it natively in dev | Required |

### Implementation changes needed

1. **Move SSR manifest out of `base/`** → `overlays/production/` (or similar prod-only path). Local opt-in adds it to `overlays/local/` on demand.
2. **Detect Inertia version** at `larakube add ssr` time by reading `composer.json`. Branch the install:
   - **v3**: scaffold project-side wiring for Vite-native SSR (`@inertiajs/vite` ssr option, no separate node-ssr deployment), production-only SSR pod
   - **v2**: scaffold project-side `ssr.jsx`, default to production-only pod, document the local opt-in flag
3. **Configmap env var injection**: only set `INERTIA_SSR_ENABLED=true` in the production overlay's configmap. Local stays `false` (or absent) by default.
4. **Local opt-in flag** (`--with-local-ssr` or `"localSsr": true` in `.larakube.json`): if set, deploy SSR pod to local AND set `INERTIA_SSR_ENABLED=true` in local configmap.

### pilot-app's migration path

Once v2 (or v1.5) ships:
1. `larakube heal` regenerates manifests with the new prod-only default
2. SSR pod disappears from local cluster (no resource waste)
3. `INERTIA_SSR_ENABLED` becomes prod-only via overlay-specific configmap entries
4. Local dev iteration returns to full speed without manual configmap patches

### Until v1.5/v2 lands, the manual disable steps are:
```
kubectl patch configmap laravel-config -n <project>-local --patch '{"data":{"INERTIA_SSR_ENABLED":"false"}}'
kubectl scale deploy/node-ssr -n <project>-local --replicas=0
kubectl rollout restart deploy/web -n <project>-local
```

## 📚 Reference

- pilot-app's hand-rolled v2 implementation (in `/Users/jsluchavez/Codes/Acme/codes/pilot-app`) is the canonical proven shape — use it as the source-of-truth for the React-Inertia-v2 stub.
- Inertia v2 SSR docs: https://inertiajs.com/docs/v2/advanced/server-side-rendering
- FrankenPHP SSR docs (alternative we evaluated and rejected): https://frankenphp.dev/docs/hot-reload/

## 🚧 Known Gotchas From v1 / pilot-app Experience

- **`ssr.noExternal: true` is the practical default** — most React apps pull in CJS/directory-import packages that crash Node ESM otherwise. The build cost is acceptable.
- **`window`/`document` access in component bodies is the #1 manual fix needed** — patchers can't auto-fix this; needs to be a doctor check + clear error message pointing user to the file.
- **`AOS.init()` and similar library setup in render body** — same class of issue. Suggest pattern: `useEffect(() => { lib.init() }, [])`.
- **Ziggy share is non-trivial** — needs to be inside `share()` method as a closure: `'ziggy' => fn () => [...(new Ziggy)->toArray(), 'location' => $request->url()]`.
- **CSS not in `@vite()` causes FOUC** — easy patcher fix: detect any input listed in `vite.config.js` ending in `.css` and ensure it's in the Blade `@vite()` call.

## 🔗 Relationship to environments-config-schema refactor

The schema refactor (`plans/active/environments-config-schema.md`) will eventually let users scope `ssr` to specific environments (e.g., `production.features: [ssr]`). v2 of SSR should land first or in parallel; it doesn't conflict.
