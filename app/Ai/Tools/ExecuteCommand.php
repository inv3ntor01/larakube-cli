<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class ExecuteCommand extends LaraKubeTool
{
    public function name(): string
    {
        return 'execute_command';
    }

    public function description(): string
    {
        return 'Execute a specific LaraKube CLI command (e.g., "new my-app --fast").';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The full command to run (e.g. "new chat-app --fast").')
                ->required(),
        ];
    }

    protected function run(array $arguments): string
    {
        $result = $this->executeCliCommand($arguments['command']);

        return $result['success']
            ? "SUCCESS: Executed {$result['command']}"
            : "FAILURE: {$result['output']}";
    }
}
