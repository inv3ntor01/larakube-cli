<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetCommandHelp extends Tool
{
    use InteractsWithLaraKubeCli;

    public function name(): string
    {
        return 'get_command_help';
    }

    public function description(): string
    {
        return 'Get detailed help, arguments, and options for a specific LaraKube command.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The name of the command to get help for (e.g., new, up, down).')
                ->required(),
        ];
    }

    public function handle(string $command): Response
    {
        return Response::text($this->getCliCommandHelp($command));
    }
}
