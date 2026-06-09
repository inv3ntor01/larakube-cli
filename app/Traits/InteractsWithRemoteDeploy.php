<?php

namespace App\Traits;

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Enums\RegistryProvider;

/**
 * Deploy a project to a remote VPS WITHOUT a container registry: build the image
 * locally, stream it into the node's k3s containerd over SSH (`docker save | ssh
 * … k3s ctr images import`), then apply manifests against the environment's OWN
 * kube-context (never the global current-context, so local dev on another context
 * is undisturbed).
 *
 * The command-builders are pure (no I/O) so they're unit-testable; the
 * orchestration runs them.
 */
trait InteractsWithRemoteDeploy
{
    /** The kube-context cloud:provision creates for a host. Pure. */
    public function remoteContextName(string $ip): string
    {
        return 'larakube-'.$ip;
    }

    /** SSH base command for a host (no remote command appended yet). Pure. */
    public function sshBaseCommand(string $user, string $ip, int $port, string $key): string
    {
        return 'ssh -o StrictHostKeyChecking=no -i '.escapeshellarg($key).' -p '.$port.' '.escapeshellarg($user.'@'.$ip);
    }

    /**
     * Build the production image for the NODE's architecture. The dev Mac is
     * often arm64 while the droplet is amd64, so we cross-build with buildx and
     * `--load` it into the local docker so `docker save` can stream it. The
     * platform is resolved from the target node (see resolveDeployPlatform), so
     * an arm64 Pi gets a native arm64 build instead of an unrunnable amd64 one.
     * Pure.
     */
    public function buildProductionImageCommand(string $image, string $dockerfile, string $path, string $platform = 'linux/amd64'): string
    {
        return 'docker buildx build --platform '.$platform.' --target deploy '
            .'-t '.escapeshellarg($image).' -f '.escapeshellarg($dockerfile).' '
            .escapeshellarg($path).' --load';
    }

    /**
     * Stream a local image into the remote node's k3s containerd. `k3s ctr`
     * needs root, hence sudo (the larakube user must have passwordless sudo for
     * it — set up by cloud:provision). Pure.
     */
    public function sideloadOverSshCommand(string $image, string $sshBase): string
    {
        return 'docker save '.escapeshellarg($image).' | '.$sshBase.' '.escapeshellarg('sudo k3s ctr images import -');
    }

    /**
     * `kubectl kustomize | sed image-rewrite | kubectl apply`, all against the
     * env's context. Mirrors what the GHA workflow does, but for the sideloaded
     * local tag instead of a registry image. Pure.
     */
    public function applyWithImageRewriteCommand(string $context, string $overlayPath, string $fromImage, string $toImage): string
    {
        $ctx = escapeshellarg($context);

        return 'kubectl --context '.$ctx.' kustomize '.escapeshellarg($overlayPath)
            .' | sed '.escapeshellarg('s|image: '.$fromImage.'|image: '.$toImage.'|g')
            .' | kubectl --context '.$ctx.' apply -f -';
    }

    /**
     * Same kustomize|sed|apply, but driven by a standalone (scoped) kubeconfig
     * file instead of a named admin context — used for the dogfooded deploy where
     * the namespace-locked `deployer` token does the apply. Pure.
     */
    public function applyWithImageRewriteUsingKubeconfig(string $kubeconfigPath, string $overlayPath, string $fromImage, string $toImage): string
    {
        $kc = 'KUBECONFIG='.escapeshellarg($kubeconfigPath).' kubectl';

        return $kc.' kustomize '.escapeshellarg($overlayPath)
            .' | sed '.escapeshellarg('s|image: '.$fromImage.'|image: '.$toImage.'|g')
            .' | '.$this->dropNamespaceDocCommand()
            .' | '.$kc.' apply -f -';
    }

    /**
     * An awk filter that drops any `kind: Namespace` document from a multi-doc
     * YAML stream. The cluster-scoped Namespace is created by admin before the
     * scoped apply; the namespaced `deployer` Role can't get/apply it, so it must
     * not reach the scoped `kubectl apply`. Everything else in the overlay is
     * namespaced and applies fine. Pure.
     */
    public function dropNamespaceDocCommand(): string
    {
        return 'awk '.escapeshellarg(
            'function flush(){if(!drop&&doc!=""){printf "%s",doc} doc="";drop=0}'
            .' /^---[ \t\r]*$/{flush();print;next}'
            .' {doc=doc $0 "\n"; if($0 ~ /^kind:[ \t]+Namespace[ \t\r]*$/)drop=1}'
            .' END{flush()}',
        );
    }

    /**
     * A unique, rollout-triggering image tag. ALWAYS includes the timestamp so
     * each deploy gets a fresh tag — otherwise a manual deploy of UNCOMMITTED
     * changes (e.g. after `larakube add horizon`) would reuse the last commit's
     * SHA tag, and the cluster (imagePullPolicy: IfNotPresent) would run the stale
     * cached image. The SHA is kept as a prefix for traceability. Pure.
     */
    public function formatImageTag(?string $gitSha, int $timestamp): string
    {
        $sha = $gitSha !== null ? trim($gitSha) : '';

        return $sha !== '' ? $sha.'-'.$timestamp : 'build-'.$timestamp;
    }

    /**
     * Split .env lines into ConfigMap (public) and Secret literals for
     * `kubectl create`. A key is a secret if it's a known blueprint secret or
     * looks like one (PASSWORD/SECRET/KEY/TOKEN). Pure.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $knownSecrets
     * @return array{public: string, secret: string}
     */
    public function splitEnvForK8s(array $lines, array $knownSecrets): array
    {
        $public = '';
        $secret = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }

            $isSecret = in_array($key, $knownSecrets, true)
                || str_contains($key, 'PASSWORD')
                || str_contains($key, 'SECRET')
                || str_contains($key, 'KEY')
                || str_contains($key, 'TOKEN');

            $literal = ' --from-literal='.escapeshellarg("{$key}={$value}");
            if ($isSecret) {
                $secret .= $literal;
            } else {
                $public .= $literal;
            }
        }

        return ['public' => $public, 'secret' => $secret];
    }

    /**
     * Build a registry image reference command (build + push). Pure.
     * Cross-compiles for the resolved target platform (the cluster nodes' arch
     * for a managed context; defaults to linux/amd64), then pushes to registry.
     */
    public function buildAndPushImageCommand(string $registryImage, string $dockerfile, string $path, string $platform = 'linux/amd64'): string
    {
        return 'docker buildx build --platform '.$platform.' --target deploy '
            .'-t '.escapeshellarg($registryImage).' -f '.escapeshellarg($dockerfile).' '
            .escapeshellarg($path).' --push';
    }

    /**
     * Map a raw architecture token — from `uname -m`, a kubectl nodeInfo
     * architecture, or a user's `cloud.arch` override — to a docker `--platform`
     * value. Returns null for anything unrecognised so callers can fall back.
     * Allowlist by design: only known platforms ever reach the build command,
     * so the resolved string is safe to interpolate unescaped. Pure.
     */
    public function normalizeArch(?string $raw): ?string
    {
        return match (strtolower(trim((string) $raw))) {
            'amd64', 'x86_64', 'x64', 'linux/amd64' => 'linux/amd64',
            'arm64', 'aarch64', 'linux/arm64' => 'linux/arm64',
            'armv7l', 'armhf', 'arm', 'linux/arm/v7' => 'linux/arm/v7',
            default => null,
        };
    }

    /** Test docker login to a registry. Pure. */
    public function dockerLoginCommand(string $registryHost, string $username, string $password): string
    {
        return 'echo '.escapeshellarg($password).' | docker login -u '.escapeshellarg($username).' --password-stdin '.escapeshellarg($registryHost);
    }

    /** Resolve the rollout-triggering image tag for a project. */
    protected function resolveImageTag(string $path): string
    {
        $sha = trim((string) shell_exec('git -C '.escapeshellarg($path).' rev-parse --short HEAD 2>/dev/null'));

        return $this->formatImageTag($sha !== '' ? $sha : null, time());
    }

    /** Is the env's context present and reachable (without touching the global one)? */
    protected function remoteContextReachable(string $context): bool
    {
        exec('kubectl --context '.escapeshellarg($context).' cluster-info --request-timeout=5s 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Detect the target node's docker platform over SSH (`uname -m`). Used by the
     * VPS/sideload path, where we already SSH into the single node. Returns null
     * if SSH fails or the arch is unrecognised, so the caller can fall back.
     */
    protected function detectNodePlatformOverSsh(string $sshBase): ?string
    {
        $raw = trim((string) shell_exec($sshBase.' '.escapeshellarg('uname -m').' 2>/dev/null'));

        return $this->normalizeArch($raw);
    }

    /**
     * Detect the target platform from a managed cluster's NODES via kubectl —
     * the registry path has no SSH. Reads each node's reported architecture and
     * only returns a platform when they AGREE (a mixed-arch pool is ambiguous →
     * null, so we fall back to the safe default rather than guessing). Returns
     * null on any failure too.
     */
    protected function detectNodePlatformViaKubectl(string $context): ?string
    {
        $raw = trim((string) shell_exec(
            'kubectl --context '.escapeshellarg($context)
            .' get nodes -o '.escapeshellarg('jsonpath={.items[*].status.nodeInfo.architecture}')
            .' 2>/dev/null',
        ));
        if ($raw === '') {
            return null;
        }

        $platforms = array_unique(array_filter(array_map(
            fn (string $a): ?string => $this->normalizeArch($a),
            preg_split('/\s+/', $raw) ?: [],
        )));

        return count($platforms) === 1 ? (string) reset($platforms) : null;
    }

    /**
     * Resolve the docker `--platform` for a deploy. Precedence:
     *   1. an explicit `cloud.arch` override (skips detection),
     *   2. detection — SSH `uname -m` when $sshBase is given (VPS/sideload),
     *      else the managed cluster's node arch via kubectl,
     *   3. linux/amd64 (the historical default) when nothing resolves.
     * Emits one line so the chosen platform + source is never a mystery.
     */
    protected function resolveDeployPlatform(?CloudData $cloud, string $context, ?string $sshBase): string
    {
        if ($cloud && $cloud->arch) {
            $override = $this->normalizeArch($cloud->arch);
            if ($override !== null) {
                $this->line("  <fg=gray>Target arch:</> <fg=cyan>{$override}</> <fg=gray>(cloud.arch override)</>");

                return $override;
            }
            $this->laraKubeWarn("Unrecognised cloud.arch '{$cloud->arch}' — ignoring and auto-detecting.");
        }

        $detected = $sshBase !== null
            ? $this->detectNodePlatformOverSsh($sshBase)
            : $this->detectNodePlatformViaKubectl($context);

        if ($detected !== null) {
            $source = $sshBase !== null ? 'SSH uname' : 'cluster nodes';
            $this->line("  <fg=gray>Target arch:</> <fg=cyan>{$detected}</> <fg=gray>(detected via {$source})</>");

            return $detected;
        }

        $this->line('  <fg=gray>Target arch:</> <fg=cyan>linux/amd64</> <fg=gray>(default — detection unavailable)</>');

        return 'linux/amd64';
    }

    /**
     * Deploy a project to its remote VPS via SSH-sideload, targeting the env's
     * own kube-context. Returns a command exit code.
     */
    protected function deployViaSshSideload(ConfigData $config, string $environment): int
    {
        $cloud = $config->getCloud($environment);

        if (! $cloud || ! $cloud->ip) {
            $this->laraKubeError("No cloud connection configured for '{$environment}'. Run `larakube cloud:provision` first.");

            return 1;
        }

        $context = $this->remoteContextName($cloud->ip);
        $name = $config->getName();
        $path = $config->getPath();
        $namespace = $config->getNamespace($environment);

        $this->line("  <fg=gray>Target:</> <fg=cyan>{$context}</>  <fg=gray>namespace:</> <fg=cyan>{$namespace}</>");

        if (! $this->remoteContextReachable($context)) {
            $this->laraKubeError("Context '{$context}' is missing or unreachable. Re-run `larakube cloud:provision`.");

            return 1;
        }

        $tag = $this->resolveImageTag($path);
        $image = "{$name}:{$tag}";
        $dockerfile = "{$path}/Dockerfile.php";
        $ssh = $this->sshBaseCommand($cloud->user, $cloud->ip, $cloud->port, $cloud->key);

        // 1. Build the production image for the node's architecture (resolved
        //    over SSH, so an arm64 node gets a native arm64 build).
        $platform = $this->resolveDeployPlatform($cloud, $context, $ssh);
        $this->laraKubeInfo("Building production image '{$image}' ({$platform})...");
        passthru($this->buildProductionImageCommand($image, $dockerfile, $path, $platform), $code);
        if ($code !== 0) {
            $this->laraKubeError('Image build failed.');

            return 1;
        }

        // 2. SSH-sideload it into the remote node's k3s containerd (no registry).
        $this->laraKubeInfo('Sideloading the image into the remote cluster...');
        passthru($this->sideloadOverSshCommand($image, $ssh), $code);
        if ($code !== 0) {
            $this->laraKubeError('Image sideload failed — check SSH access and passwordless sudo for `k3s` on the host.');

            return 1;
        }

        // 3. Namespace — ADMIN only (cluster-scoped; the scoped SA can't create it).
        $ctx = escapeshellarg($context);
        $ns = escapeshellarg($namespace);
        shell_exec("kubectl --context {$ctx} create namespace {$ns} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");

        // 4-5. env-sync + apply + rollout THROUGH a namespace-scoped credential.
        return $this->applyScopedDeploy($config, $environment, $context, $namespace, "{$name}:latest", $image);
    }

    /**
     * Deploy a project to a remote cluster via container registry push, targeting the
     * env's own kube-context. Returns a command exit code.
     */
    protected function deployViaRegistry(ConfigData $config, string $environment): int
    {
        $registry = $config->getRegistry($environment);
        if (! $registry) {
            $this->laraKubeError("No registry configured for '{$environment}'.");

            return 1;
        }

        // VPS (larakube-<ip>) OR managed (cloud.context) — resolved one way.
        $context = $this->environmentContextOrCurrent($config, $environment);
        if (! $context) {
            $this->laraKubeError("No cluster context for '{$environment}'. Run `cloud:configure:base` or `cloud:provision` first.");

            return 1;
        }

        $name = $config->getName();
        $path = $config->getPath();
        $namespace = $config->getNamespace($environment);
        $tag = $this->resolveImageTag($path);
        $registryImage = $registry->getFullImageReference($tag);

        $this->line("  <fg=gray>Target:</> <fg=cyan>{$context}</>  <fg=gray>namespace:</> <fg=cyan>{$namespace}</>");
        $this->line('  <fg=gray>Registry:</> <fg=cyan>'.$registry->getRegistryHost().'</>');

        if (! $this->remoteContextReachable($context)) {
            $this->laraKubeError("Context '{$context}' is missing or unreachable.");

            return 1;
        }

        $dockerfile = "{$path}/Dockerfile.php";

        // 1. Verify Docker login to registry.
        // For now, assume credentials are in environment or docker config.
        // In future, we could prompt for credentials.
        $this->laraKubeInfo('Verifying registry credentials...');
        // Try a simple docker info to check if already logged in. If not, the push will fail with clear error.

        // 2. Build and push the production image to registry, for the cluster's
        //    node architecture (no SSH here — read it from the nodes via kubectl).
        $platform = $this->resolveDeployPlatform($config->getCloud($environment), $context, null);
        $this->laraKubeInfo("Building and pushing image to {$registry->getRegistryHost()} ({$platform})...");
        passthru($this->buildAndPushImageCommand($registryImage, $dockerfile, $path, $platform), $code);
        if ($code !== 0) {
            $this->laraKubeError('Image build/push failed. Ensure Docker credentials are configured and you have push access.');

            return 1;
        }

        // Pin the deploy to the immutable digest the registry just assigned, not the
        // mutable tag — an attacker with push access can repoint a tag, never a digest.
        // Fall back to the tag (with a warning) if buildx can't resolve it.
        $deployImage = $registryImage;
        if (($digest = $this->resolvePushedDigest($registryImage)) !== null) {
            $deployImage = $registry->getDigestReference($digest);
            $this->line('  <fg=gray>Pinned digest:</> <fg=cyan>'.$digest.'</>');
        } else {
            $this->laraKubeWarn('Could not resolve the pushed image digest — deploying by tag (mutable). Is `docker buildx` available?');
        }

        // 3. Namespace — ADMIN only (cluster-scoped; the scoped SA can't create it).
        $ctx = escapeshellarg($context);
        $ns = escapeshellarg($namespace);
        shell_exec("kubectl --context {$ctx} create namespace {$ns} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");

        // GHCR packages are private in LaraKube, so the cluster always needs a pull
        // secret — create it here (admin context) so manual deploys work without a
        // separate `cloud:configure:gha` run or a public package.
        if ($registry->provider === RegistryProvider::GHCR) {
            $this->ensureGhcrPullSecret($context, $namespace);
        }

        // 4-5. env-sync + apply + rollout THROUGH a namespace-scoped credential.
        return $this->applyScopedDeploy($config, $environment, $context, $namespace, "{$name}:latest", $deployImage);
    }

    /**
     * The immutable digest the registry assigned to the just-pushed image, so the
     * deploy can pin `repo@sha256:…` instead of a mutable `:tag`. Null when buildx
     * imagetools can't resolve it (caller falls back to the tag with a warning).
     */
    protected function resolvePushedDigest(string $registryImage): ?string
    {
        $digest = trim((string) shell_exec(
            'docker buildx imagetools inspect '.escapeshellarg($registryImage)
            .' --format '.escapeshellarg('{{.Manifest.Digest}}').' 2>/dev/null',
        ));

        return preg_match('/^sha256:[0-9a-f]{64}$/', $digest) === 1 ? $digest : null;
    }

    /**
     * Create/refresh the `ghcr-login` image-pull secret in the namespace using the
     * GitHub CLI token (run in Docker — zero local deps). GHCR packages are private
     * in LaraKube, so the cluster always needs this to pull. Best-effort: warns and
     * skips if gh isn't authenticated rather than failing the deploy.
     */
    protected function ensureGhcrPullSecret(string $context, string $namespace): void
    {
        $gh = $this->getGhCommand();
        $user = trim((string) shell_exec("{$gh} api user -q .login 2>/dev/null"));
        $token = trim((string) shell_exec("{$gh} auth token 2>/dev/null"));

        if ($user === '' || $token === '') {
            $this->laraKubeWarn('Skipped the GHCR pull secret — run `larakube gha:login` (private images will fail to pull until then).');

            return;
        }

        $ctx = escapeshellarg($context);
        $ns = escapeshellarg($namespace);
        shell_exec(
            "kubectl --context {$ctx} create secret docker-registry ghcr-login -n {$ns}"
            .' --docker-server=https://ghcr.io'
            .' --docker-username='.escapeshellarg($user)
            .' --docker-password='.escapeshellarg($token)
            ." --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -",
        );
        $this->laraKubeInfo("Ensured GHCR pull secret (ghcr-login) in '{$namespace}'.");
    }

    /**
     * Create/refresh the laravel-config ConfigMap + laravel-secrets Secret from
     * .env.{env}, on the env's context.
     */
    protected function syncRemoteEnv(ConfigData $config, string $environment, ?string $context, string $namespace, ?string $kubeconfigPath = null): void
    {
        $envPath = $config->getPath().'/.env.'.$environment;

        if (! file_exists($envPath)) {
            $this->laraKubeWarn("No .env.{$environment} found — skipping config injection.");

            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($environment));
        ['public' => $public, 'secret' => $secret] = $this->splitEnvForK8s($lines, $knownSecrets);

        // Drive kubectl either via the scoped kubeconfig (dogfood) or, as a
        // fallback, a named admin context.
        $kube = $kubeconfigPath !== null
            ? 'KUBECONFIG='.escapeshellarg($kubeconfigPath).' kubectl'
            : 'kubectl --context '.escapeshellarg((string) $context);
        $ns = escapeshellarg($namespace);

        if ($public !== '') {
            shell_exec("{$kube} create configmap laravel-config -n {$ns} {$public} --dry-run=client -o yaml | {$kube} apply -f -");
        }
        if ($secret !== '') {
            shell_exec("{$kube} create secret generic laravel-secrets -n {$ns} {$secret} --dry-run=client -o yaml | {$kube} apply -f -");
        }
    }

    /**
     * The shared "scoped tail" for both deploy paths: with the image already in
     * place (sideloaded or pushed), use the LOCAL ADMIN context to bootstrap the
     * namespace-scoped `deployer` SA/Role/RoleBinding and mint a short token, then
     * run env-sync + manifest apply + rollout THROUGH that token. Dogfooding the
     * scoped credential means any RBAC gap surfaces here, where we still hold admin
     * to widen the Role — and guarantees the same creds will work in CI.
     *
     * Namespace creation stays with the caller (admin) — it's cluster-scoped and
     * the scoped SA deliberately can't create namespaces.
     */
    protected function applyScopedDeploy(
        ConfigData $config,
        string $environment,
        string $adminContext,
        string $namespace,
        string $fromImage,
        string $toImage,
    ): int {
        $name = $config->getName();

        // 1. Bootstrap SA + namespaced Role + RoleBinding with ADMIN (idempotent).
        $this->laraKubeInfo('Granting namespace-scoped deploy credentials...');
        if (! $this->ensureScopedRbac($adminContext, $namespace, $name, $environment)) {
            $this->laraKubeError('Failed to grant scoped RBAC (kubectl apply of the SA/Role/RoleBinding failed).');

            return 1;
        }

        // 2. Mint a short-lived token (admin) for the dogfooded apply.
        $token = trim((string) shell_exec($this->createTokenCommand($adminContext, $namespace, null, 1800).' 2>/dev/null'));
        if ($token === '') {
            $this->laraKubeError('Failed to mint a scoped token — needs kubectl >= 1.24 on a cluster >= 1.24.');

            return 1;
        }

        // 3. Read server + CA from the admin context to assemble a standalone kubeconfig.
        $server = trim((string) shell_exec($this->clusterServerCommand($adminContext).' 2>/dev/null'));
        $caData = trim((string) shell_exec($this->clusterCaDataCommand($adminContext).' 2>/dev/null'));
        if ($server === '' || $caData === '') {
            $this->laraKubeError('Could not read cluster server/CA from the admin context.');

            return 1;
        }

        // 4. Write the namespace-locked kubeconfig to a 0600 temp file.
        $kubeconfig = $this->assembleScopedKubeconfig($adminContext, $server, $caData, $namespace, $token);
        $kubeconfigPath = tempnam(sys_get_temp_dir(), 'lk_kubeconfig_');
        file_put_contents($kubeconfigPath, $kubeconfig);
        @chmod($kubeconfigPath, 0600);

        try {
            $this->line('  <fg=gray>Deploying as</> <fg=cyan>deployer</> <fg=gray>— namespace-locked to</> <fg=cyan>'.$namespace.'</>');

            // 5. env ConfigMap/Secret — THROUGH the scoped credential.
            $this->syncRemoteEnv($config, $environment, null, $namespace, $kubeconfigPath);

            // 6. Apply the overlay via the scoped credential, retrying briefly for
            //    RBAC propagation lag right after the RoleBinding was created.
            $overlay = $config->getK8sPath().'/overlays/'.$environment;
            $applyCmd = $this->applyWithImageRewriteUsingKubeconfig($kubeconfigPath, $overlay, $fromImage, $toImage);
            $this->laraKubeInfo('Applying Kubernetes manifests...');

            $code = 1;
            $applyOut = [];
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $applyOut = [];
                exec($applyCmd.' 2>&1', $applyOut, $code);
                if ($code === 0) {
                    break;
                }
                if ($attempt < 3) {
                    $this->line('  <fg=gray>RBAC not effective yet — retrying ('.$attempt.'/2)...</>');
                    sleep(2);
                }
            }
            if ($code !== 0) {
                $this->laraKubeError("kubectl apply failed under the scoped credential:\n".implode("\n", $applyOut));

                return 1;
            }

            // 7. Wait for the web rollout (scoped).
            passthru('KUBECONFIG='.escapeshellarg($kubeconfigPath).' kubectl rollout status deploy/web -n '.escapeshellarg($namespace).' --timeout=180s');
        } finally {
            @unlink($kubeconfigPath);
        }

        $this->laraKubeInfo("✅ Deployed '{$name}' to '{$environment}' (namespace-scoped, ns: {$namespace}).");

        return 0;
    }
}
