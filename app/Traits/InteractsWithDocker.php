<?php

namespace App\Traits;

use App\Data\ConfigData;

trait InteractsWithDocker
{
    use InteractsWithProjectConfig;

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

        // --- 🛡 K3D IMAGE BRIDGE ---
        // Images built on the host Docker engine are invisible to k3d nodes
        // until imported. Detect the ACTIVE k3d cluster from the current
        // kube-context (k3d-<name>) — not a hardcoded name — and sideload into
        // it, so `larakube up` "just works" on any k3d cluster without a local
        // registry. (Previously hardcoded to a cluster named "larakube" and
        // gated on a "running" string k3d doesn't actually print.)
        $context = trim((string) shell_exec('kubectl config current-context 2>/dev/null'));

        if (! str_starts_with($context, 'k3d-')) {
            return; // Not a k3d cluster — the image is reached via a registry.
        }

        $cluster = substr($context, strlen('k3d-'));

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
