<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ExecuteCommand extends Tool
{
    use InteractsWithLaraKubeCli;

    public function name(): string
    {
        return 'execute_command';
    }

    public function description(): string
    {
        return 'Execute a specific LaraKube command on the cluster. Use this after discovering flags via get_command_help.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The full LaraKube command string to execute (e.g., "new my-app --fast").')
                ->required(),
        ];
    }

    public function handle(string $command): Response
    {
        $result = $this->executeCliCommand($command);

        if (! $result['success']) {
            return Response::text("Error executing command (Exit Code: {$result['exit_code']}):\n" . $result['output']);
        }

        return Response::text("Successfully executed: {$result['command']}\n" . $result['output']);
    }
}
