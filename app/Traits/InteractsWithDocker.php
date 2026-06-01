<?php

namespace App\Traits;

use App\Data\ConfigData;

trait InteractsWithDocker
{
    use InteractsWithProjectConfig;

    /**
     * Decide where a freshly built image must be sideloaded, from the active
     * kube-context. Pure (no I/O) so the routing is unit-testable.
     *
     * Returns ['engine' => 'k3d', 'cluster' => '<name>'] for a `k3d-<name>`
     * context, ['engine' => 'k3s'] for the LOCAL native k3s context that
     * cluster:setup creates, or null for remote/registry-backed clusters
     * (including remote k3s, which is named "larakube-<ip>").
     */
    public function resolveSideloadTarget(string $context): ?array
    {
        $cluster = $this->resolveK3dClusterName($context);

        if ($cluster !== null) {
            return ['engine' => 'k3d', 'cluster' => $cluster];
        }

        if (trim($context) === 'k3s-larakube') {
            return ['engine' => 'k3s'];
        }

        return null;
    }

    /**
     * Get the base Docker run command for a specific type (php or node).
     */
    protected function getDockerCommand(string $path, string $type = 'php', string $envs = ''): string
    {
        if ($type === 'node') {
            return "docker run --rm --init -v $path:/usr/src/app -w /usr/src/app --user root $envs -e npm_config_cache=/tmp/.npm node:22-alpine ";
        }

        $appName = basename($path);
        $localImage = "$appName:latest";

        // Check if we have a local image, otherwise fallback to base
        $imageExists = shell_exec("docker images -q {$localImage} 2>/dev/null");
        $image = $imageExists ? $localImage : $this->getProjectConfig($path)->getPhpImage(true);

        $baseEnvs = '-e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_IGNORE_PLATFORM_REQS=1';

        return "docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $baseEnvs $envs {$image} ";
    }

    protected function imageExists(string $image): bool
    {
        $id = shell_exec('docker images -q '.escapeshellarg($image).' 2>/dev/null');

        return ! empty(trim($id ?? ''));
    }

    /**
     * Build the local project image.
     */
    protected function buildImage(ConfigData $config): void
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;
        $appName = $config->getName();
        $path = $config->getPath();

        // Build Primary Project Image (Includes PHP, Node, and correct permissions)
        $this->buildTargetedImage($appName, "$path/Dockerfile.php", $path, $uid, $gid);
    }

    /**
     * Resolve the k3d cluster name from a kube-context, or null if the context
     * isn't a k3d cluster. k3d contexts are named `k3d-<cluster>`. Kept pure (no
     * shell) so the detection that decides which cluster to sideload the image
     * into is unit-testable — this is the logic that was previously hardcoded to
     * "larakube" and silently skipped other clusters.
     */
    protected function resolveK3dClusterName(string $context): ?string
    {
        $context = trim($context);

        if (! str_starts_with($context, 'k3d-')) {
            return null;
        }

        $cluster = substr($context, strlen('k3d-'));

        return $cluster !== '' ? $cluster : null;
    }

    protected function buildTargetedImage(string $tag, string $dockerfile, string $path, int $uid, int $gid): void
    {
        if (! file_exists($dockerfile)) {
            return;
        }

        $imageTag = "$tag:latest";
        $this->laraKubeInfo("Building local image '$imageTag'...");

        $target = '';
        $buildArgs = '';
        $content = file_get_contents($dockerfile);

        if (str_contains($content, 'AS development')) {
            $target = '--target development';
            $buildArgs = "--build-arg USER_ID=$uid --build-arg GROUP_ID=$gid";
        }

        passthru("docker build $target $buildArgs -t $imageTag -f $dockerfile $path");

        // --- 🛡 LOCAL IMAGE BRIDGE ---
        // Images built on the host Docker engine are invisible to a local
        // cluster's container runtime until imported. Route the freshly built
        // image to whichever local engine is active so `larakube up` "just
        // works" without a registry. Remote/registry-backed clusters need
        // nothing here.
        $context = trim((string) shell_exec('kubectl config current-context 2>/dev/null'));
        $sideload = $this->resolveSideloadTarget($context);

        if ($sideload === null) {
            return; // Remote/registry-backed cluster — the image is pulled, not sideloaded.
        }

        if ($sideload['engine'] === 'k3d') {
            $this->sideloadIntoK3d($imageTag, $sideload['cluster']);
        } else { // k3s
            $this->sideloadIntoK3s($imageTag);
        }
    }

    /**
     * Sideload a host-built image into a k3d cluster's nodes.
     */
    protected function sideloadIntoK3d(string $imageTag, string $cluster): void
    {
        // Confirm the cluster exists in k3d (also skips cleanly if k3d isn't installed).
        if (trim((string) shell_exec('k3d cluster list '.escapeshellarg($cluster).' --no-headers 2>/dev/null')) === '') {
            return;
        }

        $this->laraKubeInfo("Importing '$imageTag' into k3d cluster '$cluster'...");

        $output = [];
        $code = 0;
        exec('k3d image import '.escapeshellarg($imageTag).' -c '.escapeshellarg($cluster).' 2>&1', $output, $code);

        if ($code !== 0) {
            $this->laraKubeError("Could not sideload '$imageTag' into k3d cluster '$cluster'.");
            $this->line('  The local image is not visible to the cluster nodes, so pods will');
            $this->line('  likely fail with ImagePullBackOff. Last output from k3d:');
            foreach (array_slice($output, -4) as $line) {
                $this->line('    '.$line);
            }

            return;
        }

        // Verify availability on the cluster's server node.
        $this->withSpin('Verifying cluster image availability...', function () use ($imageTag, $cluster) {
            $images = shell_exec('docker exec k3d-'.$cluster.'-server-0 crictl images 2>/dev/null');
            [$name, $tag] = array_pad(explode(':', $imageTag), 2, 'latest');

            return str_contains($images ?? '', $imageTag) ||
                   str_contains($images ?? '', "docker.io/library/$imageTag") ||
                   (str_contains($images ?? '', $name) && str_contains($images ?? '', $tag));
        });
    }

    /**
     * Sideload a host-built image into native k3s. k3s uses containerd (not
     * Docker), so stream the image straight into its store with `k3s ctr images
     * import`. Requires sudo because the k3s containerd socket is root-owned.
     */
    protected function sideloadIntoK3s(string $imageTag): void
    {
        $this->laraKubeInfo("Importing '$imageTag' into k3s (containerd)...");
        $this->line('  <fg=gray>k3s uses containerd; importing requires sudo.</>');

        // Pre-warm sudo so the credential prompt is interactive (the import runs
        // through a pipe where a prompt would otherwise be swallowed).
        passthru('sudo -v');

        $code = 0;
        passthru('docker save '.escapeshellarg($imageTag).' | sudo k3s ctr images import -', $code);

        if ($code !== 0) {
            $this->laraKubeError("Could not sideload '$imageTag' into k3s.");
            $this->line('  The local image is not visible to k3s, so pods will likely fail');
            $this->line('  with ImagePullBackOff.');
        }
    }

    /**
     * Get the PHP image string based on project config.
     */
    protected function getProjectPhpImage(string $path): string
    {
        $configPath = $path.'/.larakube.json';
        $phpVersion = '8.5';
        $osSuffix = '-alpine';

        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $phpVersion = $config['phpVersion'] ?? $phpVersion;
            $os = $config['os'] ?? 'alpine';
            $osSuffix = $os === 'alpine' ? '-alpine' : '';
        }

        return "serversideup/php:{$phpVersion}-cli{$osSuffix}";
    }

    /**
     * Get the command to install Node.js and NPM based on the image OS.
     */
    protected function getNodeInstallationCommand(string $image): string
    {
        return str_contains($image, 'alpine')
            ? 'apk add --no-cache nodejs npm'
            : 'apt-get update && apt-get install -y nodejs npm';
    }

    /**
     * Run a command inside a Docker container.
     */
    protected function runInContainer(string $command, string $path, string $type = 'php', string $envs = ''): void
    {
        $base = $this->getDockerCommand($path, $type, $envs);
        passthru($base."sh -c '$command'");
    }

    /**
     * Fix file ownership in the project directory back to the host user.
     */
    protected function chownToHostUser(string $path): void
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;

        $appName = basename($path);
        $image = "$appName:latest";

        // Fallback if image doesn't exist
        $imageExists = shell_exec("docker images -q {$image} 2>/dev/null");
        if (! $imageExists) {
            $image = $this->getProjectConfig($path)->getPhpImage(true);
        }

        passthru("docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $image chown -R $uid:$gid .");
    }
}
