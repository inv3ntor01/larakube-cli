<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\Tools\LaraKubeTool;
use App\Traits\InteractsWithEnvironments;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class ApplyHealingPatch extends LaraKubeTool
{
    use InteractsWithEnvironments;

    public function name(): string
    {
        return 'apply_healing_patch';
    }

    public function description(): string
    {
        return 'Surgically apply a Kubernetes manifest patch to fix a detected issue.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The raw YAML content of the Kubernetes manifest.')
                ->required(),
            'filename' => $schema->string()
                ->description('The name of the patch file (e.g., fix-permissions-patch.yaml).')
                ->required(),
            'environment' => $schema->string()
                ->description('The environment to target (local or production). Defaults to local.')
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
        $content = $arguments['content'];
        $filename = $arguments['filename'];
        $environment = $arguments['environment'] ?? 'local';
        $namespace = $this->getNamespace($environment);

        $tmpPath = sys_get_temp_dir().'/'.$filename;
        file_put_contents($tmpPath, $content);

        $output = shell_exec("kubectl apply -f {$tmpPath} -n {$namespace} 2>&1");
        @unlink($tmpPath);

        return "Patch Result: {$output}";
    }
}
