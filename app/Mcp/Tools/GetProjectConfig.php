<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\Tools\LaraKubeTool;
use App\Traits\InteractsWithProjectConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class GetProjectConfig extends LaraKubeTool
{
    use InteractsWithProjectConfig;

    public function name(): string
    {
        return 'get_project_config';
    }

    public function description(): string
    {
        return 'Retrieve the LaraKube architectural configuration (.larakube.json) for the current project.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The directory path to check for configuration.')
                ->default('.')
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
        $config = $this->getProjectConfig();

        if ($this->isLaraKubeProject(false)) {
            return 'No .larakube.json found in the current directory.';
        }

        return $config?->toString() ?? '';
    }
}
