<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Traits\InteractsWithProjectConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetProjectConfig extends Tool
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
        return [];
    }

    public function handle(): Response
    {
        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (empty($config)) {
            return Response::error('No LaraKube configuration found (.larakube.json) in '.$projectPath);
        }

        return Response::json($config);
    }
}
