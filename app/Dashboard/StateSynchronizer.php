<?php

namespace App\Dashboard;

use App\Dashboard\Data\ProjectSummaryData;
use App\Dashboard\Requests\SyncClusterStateRequest;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTraefik;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;

class StateSynchronizer
{
    use InteractsWithProjectConfig, InteractsWithTraefik;

    public function __construct(
        protected DashboardConnector $connector
    ) {}

    /**
     * Synchronize the current CLI state with the Web UI.
     */
    public function sync(): bool
    {
        $projects = $this->discoverProjects();
        $traefik = $this->getTraefikStatus();

        $request = new SyncClusterStateRequest(
            projects: $projects->toArray(),
            traefik: $traefik,
            system: [
                'cli_version' => config('app.version'),
                'os' => PHP_OS_FAMILY,
                'k8s_connected' => true,
            ]
        );

        return $this->connector->send($request);
    }

    /**
     * Discover all LaraKube projects in the workspace.
     */
    protected function discoverProjects(): Collection
    {
        $workspace = dirname(getcwd()); // Assuming CLI is run from a subfolder or root
        
        if (!is_dir($workspace)) {
            return collect();
        }

        $finder = new Finder();
        $finder->files()->name('.larakube.json')->in($workspace)->depth('< 3');

        return collect($finder)->map(function ($file) {
            try {
                $config = $this->loadConfig($file->getPath());
                
                // Get real-time status via kubectl (simplified for prototype)
                $status = $this->getNamespaceStatus("larakube-{$config->id}");

                return new ProjectSummaryData(
                    id: $config->id,
                    name: $config->name ?? $config->id,
                    status: $status,
                    namespace: "larakube-{$config->id}",
                    url: "https://{$config->id}.dev.test",
                    features: $config->features
                );
            } catch (\Throwable) {
                return null;
            }
        })->filter();
    }

    protected function getNamespaceStatus(string $namespace): string
    {
        $output = shell_exec("kubectl get ns {$namespace} -o jsonpath='{.status.phase}' 2>/dev/null");
        
        if (!$output) return 'DOWN';
        
        return $output === 'Active' ? 'UP' : 'SYNCING';
    }

    protected function getTraefikStatus(): array
    {
        $active = shell_exec("kubectl get pods -n larakube-system -l app.kubernetes.io/name=traefik -o jsonpath='{.items[0].status.phase}' 2>/dev/null");

        return [
            'status' => ($active === 'Running') ? 'ACTIVE' : 'INACTIVE',
            'version' => 'v3.0',
            'entrypoints' => ['web', 'websecure'],
        ];
    }
}
