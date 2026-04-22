<?php

namespace App\Traits;

use App\Enums\LaravelFeature;
use App\Enums\ScoutDriver;

use function Laravel\Prompts\confirm;

trait InteractsWithHosts
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * Check and optionally update the /etc/hosts file based on project context.
     */
    protected function ensureHostsAreSet(): void
    {
        $projectPath = getcwd();
        $appName = basename($projectPath);
        $baseHost = "{$appName}.dev.test";

        $requiredHosts = [$baseHost, "mailpit.{$baseHost}", "vite.{$baseHost}"];

        // Discovery Phase: Try to find enabled services even if config is missing
        $config = $this->getProjectConfig($projectPath);
        $features = $config['features'] ?? [];
        $k8sBasePath = $projectPath.'/.infrastructure/k8s/base';

        // 1. Storage Discovery
        if (($config['objectStorage'] ?? 'none') !== 'none' || is_dir($k8sBasePath.'/minio') || file_exists($k8sBasePath.'/minio-deployment.yaml')) {
            $requiredHosts[] = "s3.{$baseHost}";
            $requiredHosts[] = "s3-admin.{$baseHost}";
        }

        // 2. Search Discovery
        if (in_array(LaravelFeature::SCOUT->value, $features) || file_exists($k8sBasePath.'/meilisearch-deployment.yaml') || file_exists($k8sBasePath.'/typesense-deployment.yaml')) {
            $driver = $config['scoutDriver'] ?? 'MEILISEARCH';
            if ($driver === ScoutDriver::MEILISEARCH->value || $driver === 'MEILISEARCH' || file_exists($k8sBasePath.'/meilisearch-deployment.yaml')) {
                $requiredHosts[] = "meilisearch.{$baseHost}";
            }
            if ($driver === ScoutDriver::TYPESENSE->value || $driver === 'TYPESENSE' || file_exists($k8sBasePath.'/typesense-deployment.yaml')) {
                $requiredHosts[] = "typesense.{$baseHost}";
            }
        }

        // 3. Monitoring Discovery
        if (in_array(LaravelFeature::MONITORING->value, $features) || file_exists($k8sBasePath.'/monitoring-grafana.yaml')) {
            $requiredHosts[] = "grafana.{$baseHost}";
            $requiredHosts[] = "prometheus.{$baseHost}";
        }

        $requiredHosts = array_unique($requiredHosts);

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
        $newEntry = "{$externalIp} {$hostList}";
        $blockIdentifier = "# LaraKube: {$appName}";
        $fullBlock = "\n{$blockIdentifier}\n{$newEntry}\n";

        // 1. Check if update is actually needed
        if (! file_exists('/etc/hosts')) {
            return;
        }

        $currentHosts = file_get_contents('/etc/hosts');

        if (str_contains($currentHosts, $fullBlock)) {
            return; // Perfectly matched, nothing to do
        }

        $this->laraKubeInfo('Local domain mapping update required.');
        $this->line("  <fg=gray>Target IP:</> <fg=blue>{$externalIp}</>");
        foreach ($requiredHosts as $host) {
            $this->line("  <fg=gray>●</> <fg=blue>{$host}</>");
        }
        $this->line('');

        if (confirm('Would you like LaraKube to sync your /etc/hosts?', true)) {
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

                exec("sudo cp {$tmpPath} /etc/hosts");
                @unlink($tmpPath);

                return true;
            });

            $this->laraKubeInfo('Hosts synchronized successfully!');
        }
    }
}
