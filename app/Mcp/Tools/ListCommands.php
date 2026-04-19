<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ListCommands extends Tool
{
    use InteractsWithLaraKubeCli;

    public function name(): string
    {
        return 'list_commands';
    }

    public function description(): string
    {
        return 'List all available LaraKube commands and their descriptions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(): Response
    {
        return Response::text($this->listCliCommands());
    }
}
