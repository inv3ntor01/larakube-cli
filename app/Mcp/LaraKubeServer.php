<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\ApplyHealingPatch;
use App\Mcp\Tools\DiagnosePod;
use App\Mcp\Tools\ExecuteCommand;
use App\Mcp\Tools\GetCommandHelp;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\ListCommands;
use App\Mcp\Tools\ListPods;
use Laravel\Mcp\Server;

class LaraKubeServer extends Server
{
    protected string $name = 'LaraKube MCP Server';

    protected string $version = '0.0.1';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server allows AI agents to orchestrate, diagnose, and heal Kubernetes clusters managed by LaraKube.
        
        It provides dynamic discovery tools (`list_commands`, `get_command_help`) so that you can always use the latest CLI features without hardcoded knowledge.
    MARKDOWN;

    protected function boot(): void
    {
        $this->tools = [
            app(ListPods::class),
            app(DiagnosePod::class),
            app(GetProjectConfig::class),
            app(ApplyHealingPatch::class),
            app(ListCommands::class),
            app(GetCommandHelp::class),
            app(ExecuteCommand::class),
        ];
    }
}
