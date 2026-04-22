<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\Tools\LaraKubeTool;
use App\Traits\InteractsWithEnvironments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class ListPods extends LaraKubeTool
{
    use InteractsWithEnvironments;

    public function name(): string
    {
        return 'list_pods';
    }

    public function description(): string
    {
        return 'List all pods and their health status in a LaraKube environment.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'environment' => $schema->string()
                ->description('The environment to target (e.g., local, production). Defaults to local.')
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
        $environment = $arguments['environment'] ?? 'local';
        $namespace = $this->getNamespace($environment);
        $output = shell_exec("kubectl get pods -n {$namespace} 2>&1");

        if (str_contains($output, 'No resources found')) {
            return "No pods found in namespace '{$namespace}'.";
        }

        return $output;
    }
}
