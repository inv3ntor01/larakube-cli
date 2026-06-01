<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

trait InteractsWithHosts
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Check and optionally update the /etc/hosts file based on project context.
     *
     * @param  array  $customHosts  Optional array of specific hosts to map. If empty, uses the current project's hosts.
     * @param  string|null  $customAppName  Optional app name to group the block. Defaults to the current directory name.
     */
    protected function ensureHostsAreSet(array $customHosts = [], ?string $customAppName = null): void
    {
        $projectPath = getcwd();
        $appName = $customAppName ?? basename($projectPath);

        if (empty($customHosts)) {
            $config = $this->getProjectConfig($projectPath);

            // No readable config → nothing to map. Host syncing is a convenience
            // pre-step; skip it rather than crash (getProjectConfig already
            // surfaced the reason if the file exists but is invalid).
            if (! $config) {
                return;
            }

            $requiredHosts = array_keys($config->getAllHosts('local'));
        } else {
            $requiredHosts = $customHosts;
        }

        if (empty($requiredHosts)) {
            return;
        }

        // 🛡 SMART IP DETECTION
        // On Mac and Windows, Docker Desktop/OrbStack maps published ports to 127.0.0.1.
        // On Linux, we use the actual LoadBalancer IP because it's natively routable.
        $isLinux = PHP_OS_FAMILY === 'Linux';
        $externalIp = '127.0.0.1';

        if ($isLinux) {
            $detectedIp = shell_exec("kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null");
            if (! empty($detectedIp)) {
                $externalIp = $detectedIp;
            }
        }
        $hostList = implode(' ', $requiredHosts);
        $newEntry = "$externalIp $hostList";
        $blockIdentifier = "# LaraKube: $appName";
        $fullBlock = "\n{$blockIdentifier}\n{$newEntry}\n";

        // 1. Check if update is actually needed
        if (! file_exists('/etc/hosts')) {
            return;
        }

        // If running inside a container, we usually can't update the host's /etc/hosts
        // without mapping it, which is risky. We'll skip it and warn the user.
        if (getenv('LARAKUBE_HOST_PROJECT_PATH') && ! is_writable('/etc/hosts')) {
            $this->warning('Running inside LaraKube daemon: skipping /etc/hosts sync.');
            $this->line('  👉 Please ensure your host machine has these mappings:');
            $this->line("     $newEntry");
            $this->line('');

            return;
        }

        $currentHosts = file_get_contents('/etc/hosts');

        if (str_contains($currentHosts, $fullBlock)) {
            return;
        }

        $this->laraKubeInfo('Local domain mapping update required.');
        $this->line("  <fg=gray>Target IP:</> <fg=blue>$externalIp</>");
        foreach ($requiredHosts as $host) {
            $this->line("  <fg=gray>●</> <fg=blue>{$host}</>");
        }
        $this->line('');

        if (confirm('Would you like LaraKube to sync your /etc/hosts?')) {
            $this->line('  <fg=gray>LaraKube requires sudo privileges to update /etc/hosts</>');
            passthru('sudo -v');

            $this->withSpin('Syncing /etc/hosts...', function () use ($currentHosts, $blockIdentifier, $fullBlock) {
                $newHosts = $currentHosts;

                // 2. If an old block exists, remove it first
                if (str_contains($currentHosts, $blockIdentifier)) {
                    // Pattern to match the block and the following line
                    $pattern = "/\n?".preg_quote($blockIdentifier, '/')."\n.*?\n/s";
                    $newHosts = preg_replace($pattern, '', $currentHosts);
                }

                // 3. Append the clean new block
                $newHosts = rtrim($newHosts)."\n".$fullBlock;

                $tmpPath = sys_get_temp_dir().'/larakube_hosts';
                file_put_contents($tmpPath, $newHosts);

                exec("sudo cp $tmpPath /etc/hosts");
                @unlink($tmpPath);

                return true;
            });

            $this->laraKubeInfo('Hosts synchronized successfully!');
        }
    }
}
