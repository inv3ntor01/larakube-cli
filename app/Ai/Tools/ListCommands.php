<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

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
        return [];
    }

    protected function run(array $arguments): string
    {
        return $this->listCliCommands();
    }
}
