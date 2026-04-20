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

        $requiredHosts = array_unique($requiredHosts);
        $missingHosts = [];

        foreach ($requiredHosts as $host) {
            $ip = gethostbyname($host);
            if ($ip === $host || ($ip !== '127.0.0.1' && $ip !== '::1')) {
                $missingHosts[] = $host;
            }
        }

        if (empty($missingHosts)) {
            return;
        }

        $this->laraKubeInfo('Missing local domain mappings detected:');
        $this->line('  <fg=gray>127.0.0.1</> '.implode(', ', array_map(fn ($h) => "<fg=blue>{$h}</>", $missingHosts)));
        $this->line('');

        if (confirm('Would you like LaraKube to add these mappings to your /etc/hosts?', true)) {
            $hostList = implode(' ', $missingHosts);
            $entry = "127.0.0.1 {$hostList}";

            $this->line('  <fg=gray>LaraKube requires sudo privileges to update /etc/hosts</>');
            passthru('sudo -v');

            $this->withSpin('Updating /etc/hosts...', function () use ($entry, $appName) {
                exec("echo '\n# LaraKube: {$appName}\n{$entry}' | sudo -n tee -a /etc/hosts > /dev/null");
            });

            $this->laraKubeInfo('Hosts updated successfully!');
        }
    }
}
