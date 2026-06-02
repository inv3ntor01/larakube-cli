<?php

namespace App\Traits;

use App\Data\ConfigData;

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
     * `--load` it into the local docker so `docker save` can stream it. Pure.
     */
    public function buildProductionImageCommand(string $image, string $dockerfile, string $path): string
    {
        return 'docker buildx build --platform linux/amd64 --target deploy '
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
     * A unique, rollout-triggering image tag — the git short SHA, else a
     * timestamped fallback. (A fixed ':latest' wouldn't change the Deployment
     * spec, so k8s wouldn't roll out the new image.) Pure.
     */
    public function formatImageTag(?string $gitSha, int $timestamp): string
    {
        $sha = $gitSha !== null ? trim($gitSha) : '';

        return $sha !== '' ? $sha : 'build-'.$timestamp;
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

        $this->laraKubeLine("  <fg=gray>Target:</> <fg=cyan>{$context}</>  <fg=gray>namespace:</> <fg=cyan>{$namespace}</>");

        if (! $this->remoteContextReachable($context)) {
            $this->laraKubeError("Context '{$context}' is missing or unreachable. Re-run `larakube cloud:provision`.");

            return 1;
        }

        $tag = $this->resolveImageTag($path);
        $image = "{$name}:{$tag}";
        $dockerfile = "{$path}/Dockerfile.php";

        // 1. Build the production image for the node's architecture (amd64).
        $this->laraKubeInfo("Building production image '{$image}' (linux/amd64)...");
        passthru($this->buildProductionImageCommand($image, $dockerfile, $path), $code);
        if ($code !== 0) {
            $this->laraKubeError('Image build failed.');

            return 1;
        }

        // 2. SSH-sideload it into the remote node's k3s containerd (no registry).
        $this->laraKubeInfo('Sideloading the image into the remote cluster...');
        $ssh = $this->sshBaseCommand($cloud->user, $cloud->ip, $cloud->port, $cloud->key);
        passthru($this->sideloadOverSshCommand($image, $ssh), $code);
        if ($code !== 0) {
            $this->laraKubeError('Image sideload failed — check SSH access and passwordless sudo for `k3s` on the host.');

            return 1;
        }

        // 3. Namespace + env (ConfigMap/Secret) on the env's context.
        $ctx = escapeshellarg($context);
        $ns = escapeshellarg($namespace);
        shell_exec("kubectl --context {$ctx} create namespace {$ns} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");
        $this->syncRemoteEnv($config, $environment, $context, $namespace);

        // 4. Apply manifests, rewriting the local :latest ref to the sideloaded tag.
        $overlay = $config->getK8sPath().'/overlays/'.$environment;
        $this->laraKubeInfo('Applying Kubernetes manifests...');
        passthru($this->applyWithImageRewriteCommand($context, $overlay, "{$name}:latest", $image), $code);
        if ($code !== 0) {
            $this->laraKubeError('kubectl apply failed.');

            return 1;
        }

        // 5. Wait for the web rollout.
        passthru("kubectl --context {$ctx} rollout status deploy/web -n {$ns} --timeout=180s");

        $this->laraKubeInfo("✅ Deployed '{$name}' to '{$environment}' ({$context}).");

        return 0;
    }

    /**
     * Create/refresh the laravel-config ConfigMap + laravel-secrets Secret from
     * .env.{env}, on the env's context.
     */
    protected function syncRemoteEnv(ConfigData $config, string $environment, string $context, string $namespace): void
    {
        $envPath = $config->getPath().'/.env.'.$environment;

        if (! file_exists($envPath)) {
            $this->laraKubeWarn("No .env.{$environment} found — skipping config injection.");

            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($environment));
        ['public' => $public, 'secret' => $secret] = $this->splitEnvForK8s($lines, $knownSecrets);

        $ctx = escapeshellarg($context);
        $ns = escapeshellarg($namespace);

        if ($public !== '') {
            shell_exec("kubectl --context {$ctx} create configmap laravel-config -n {$ns} {$public} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");
        }
        if ($secret !== '') {
            shell_exec("kubectl --context {$ctx} create secret generic laravel-secrets -n {$ns} {$secret} --dry-run=client -o yaml | kubectl --context {$ctx} apply -f -");
        }
    }
}
