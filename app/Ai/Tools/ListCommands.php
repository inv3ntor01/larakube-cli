<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class ListCommands extends LaraKubeTool
{
    public function name(): string
    {
        return 'list_commands';
    }

    public function description(): string
    {
        return 'List all available LaraKube commands.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('An optional filter for the command list.')
                ->default('')
                ->required(),
        ];
    }

    public function callTool(array $arguments = []): Response
    {
        return $this->runMcp($arguments);
    }

    protected function run(array $arguments): string
    {
        return $this->listCliCommands();
    }
}
