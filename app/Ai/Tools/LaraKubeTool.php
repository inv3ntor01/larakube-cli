<?php

namespace App\Ai\Tools;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Ai\Contracts\Tool as AiToolContract;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\Server\Tool as McpTool;
use Stringable;

abstract class LaraKubeTool extends McpTool implements AiToolContract
{
    use InteractsWithLaraKubeCli;

    /**
     * The internal logic that returns a string result.
     */
    abstract protected function run(array $arguments): string;

    /**
     * Handle for Laravel AI SDK.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->run($request->toArray());
    }

    /**
     * Handle for MCP Server.
     *
     * Note: We use __invoke or a dedicated method for MCP to avoid
     * conflicting with the AI SDK's handle(Request $request) signature.
     */
    public function __invoke(array $arguments = []): McpResponse
    {
        return McpResponse::text($this->run($arguments));
    }

    /**
     * Helper for MCP Server to avoid type-hinting issues with 'handle'.
     */
    public function executeMcp(array $arguments = []): McpResponse
    {
        return $this->runMcp($arguments);
    }

    public function runMcp(array $arguments = []): McpResponse
    {
        return McpResponse::text($this->run($arguments));
    }

    /**
     * Reconciliation Engine (Kubernetes Controller Pattern)
     *
     * Ensures the cluster state matches the project blueprint.
     */
    protected function reconcile(string $projectPath, string $environment = 'local'): array
    {
        $this->laraKubeInfo("Reconciling architectural state for '{$environment}'...");
        $namespace = $this->getNamespace($environment);
        $issues = [];

        // 1. Ensure Namespace
        $nsCheck = shell_exec("kubectl get namespace {$namespace} --no-headers 2>&1");
        if (str_contains($nsCheck, 'NotFound')) {
            $issues[] = "Namespace '{$namespace}' missing. Creating...";
            shell_exec("kubectl create namespace {$namespace}");
        }

        // 2. Sync Blueprint Secret (The source of truth)
        $blueprintPath = $projectPath.'/.larakube.json';
        if (file_exists($blueprintPath)) {
            shell_exec("kubectl create secret generic larakube-blueprint -n {$namespace} --from-file=.larakube.json={$blueprintPath} --dry-run=client -o yaml | kubectl apply -f -");
        }

        // 3. Check for PVC Health
        $pvcs = shell_exec("kubectl get pvc -n {$namespace} -o json");
        if ($pvcs) {
            $data = json_decode($pvcs, true);
            foreach ($data['items'] ?? [] as $pvc) {
                if ($pvc['status']['phase'] !== 'Bound') {
                    $issues[] = "PVC '{$pvc['metadata']['name']}' is in state: {$pvc['status']['phase']}";
                }
            }
        }

        return [
            'success' => empty($issues),
            'logs' => $issues,
            'namespace' => $namespace,
        ];
    }
}
