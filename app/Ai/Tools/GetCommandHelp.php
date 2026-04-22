<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;

class GetCommandHelp extends LaraKubeTool
{
    public function name(): string
    {
        return 'get_command_help';
    }

    public function description(): string
    {
        return 'Get detailed help and options for a specific LaraKube command.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The command name to get help for (e.g. "new").')
                ->required(),
        ];
    }

    public function callTool(array $arguments = []): Response
    {
        return $this->runMcp($arguments);
    }

    protected function run(array $arguments): string
    {
        return $this->getCliCommandHelp($arguments['command']);
    }
}
