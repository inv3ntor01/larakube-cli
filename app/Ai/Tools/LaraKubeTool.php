<?php

namespace App\Ai\Tools;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Ai\Contracts\Tool as AiToolContract;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\Server\Tool as McpTool;
use Stringable;

abstract class LaraKubeTool extends McpTool implements AiToolContract
{
    use InteractsWithLaraKubeCli;

    /**
     * The internal logic that returns a string result.
     */
    abstract protected function run(array $arguments): string;

    /**
     * Handle for Laravel AI SDK.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->run($request->toArray());
    }

    /**
     * Handle for MCP Server.
     */
    public function __invoke(array $arguments = []): McpResponse
    {
        return McpResponse::text($this->run($arguments));
    }
}
