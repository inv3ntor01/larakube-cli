<?php

namespace App\Traits;

use App\Data\ConfigData;

trait DeploysMonitoringExporters
{
    protected function isMonitoringActive(string $kubectl = 'kubectl'): bool
    {
        return trim((string) shell_exec(
            "{$kubectl} get deployment prometheus -n larakube-shared --no-headers 2>/dev/null",
        )) !== '';
    }

    protected function ensureMonitoringExporters(
        ConfigData $config,
        string $namespace,
        string $kubectl = 'kubectl',
    ): void {
        if (! $this->isMonitoringActive($kubectl)) {
            return;
        }

        // DB exporter (MySQL, MariaDB, PostgreSQL)
        $db = $config->getDatabase();
        if ($db && $db->exporterImage()) {
            $this->applyExporterManifest(
                view('k8s.monitoring.exporters.database', [
                    'config' => $config,
                    'driver' => $db,
                    'namespace' => $namespace,
                ])->render(),
                $kubectl,
            );
        }

        // Cache exporters (Redis; Memcached has no maintained exporter)
        foreach ($config->getCacheDrivers() as $cache) {
            if ($cache->exporterImage()) {
                $this->applyExporterManifest(
                    view('k8s.monitoring.exporters.cache', [
                        'config' => $config,
                        'driver' => $cache,
                        'namespace' => $namespace,
                    ])->render(),
                    $kubectl,
                );
            }
        }
    }

    private function applyExporterManifest(string $yaml, string $kubectl): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'larakube-exporter-');
        file_put_contents($tmp, $yaml);
        shell_exec("{$kubectl} apply -f {$tmp} 2>/dev/null");
        @unlink($tmp);
    }
}
