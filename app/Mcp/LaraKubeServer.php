<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Ai\Tools\ExecuteCommand;
use App\Ai\Tools\GetCommandHelp;
use App\Ai\Tools\ListCommands;
use App\Mcp\Tools\ApplyHealingPatch;
use App\Mcp\Tools\DiagnosePod;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\ListPods;
use Laravel\Mcp\Server;

class LaraKubeServer extends Server
{
    protected string $name = 'LaraKube MCP Server';

    protected string $version = '0.0.1';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server allows AI agents to orchestrate, diagnose, and heal Kubernetes clusters managed by LaraKube.

        It provides dynamic discovery tools (`list_commands`, `get_command_help`) so that you can always use the latest CLI features.
    MARKDOWN;

    protected array $tools = [
        ListPods::class,
        DiagnosePod::class,
        GetProjectConfig::class,
        ApplyHealingPatch::class,
        ListCommands::class,
        GetCommandHelp::class,
        ExecuteCommand::class,
    ];
}
