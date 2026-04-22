<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\Tools\LaraKubeTool;
use App\Traits\InteractsWithEnvironments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class DiagnosePod extends LaraKubeTool
{
    use InteractsWithEnvironments;

    public function name(): string
    {
        return 'diagnose_pod';
    }

    public function description(): string
    {
        return 'Retrieve logs and events for a specific pod to identify issues.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pod_name' => $schema->string()
                ->description('The name of the pod to diagnose.')
                ->required(),
            'environment' => $schema->string()
                ->description('The environment to target. Defaults to local.')
                ->default('local')
                ->required(),
        ];
    }

    /**
     * MCP Server entry point.
     */
    public function callTool(array $arguments = []): Response
    {
        return $this->runMcp($arguments);
    }

    protected function run(array $arguments): string
    {
        $pod_name = $arguments['pod_name'];
        $environment = $arguments['environment'] ?? 'local';
        $namespace = $this->getNamespace($environment);

        $logs = shell_exec("kubectl logs -n {$namespace} {$pod_name} --tail=100 2>&1");
        $events = shell_exec("kubectl get events -n {$namespace} --field-selector involvedObject.name={$pod_name} --sort-by='.lastTimestamp' 2>&1");

        return "--- LOGS (Last 100 lines) ---\n{$logs}\n\n--- RECENT EVENTS ---\n{$events}";
    }
}
