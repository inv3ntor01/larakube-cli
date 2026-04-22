<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Ai\Tools\ExecuteCommand;
use App\Ai\Tools\GetCommandHelp;
use App\Ai\Tools\ListCommands;
use App\Ai\Tools\SearchDocumentation;
use App\Mcp\Methods\LaraKubeCallTool;
use App\Mcp\Tools\ApplyHealingPatch;
use App\Mcp\Tools\DiagnosePod;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\ListPods;
use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Mcp\Server;

class LaraKubeServer extends Server
{
    use InteractsWithLaraKubeCli;

    protected string $name = 'LaraKube MCP Server';

    protected string $version = '0.0.1';

    protected string $instructions = '';

    protected array $tools = [
        ListPods::class,
        DiagnosePod::class,
        GetProjectConfig::class,
        ApplyHealingPatch::class,
        ListCommands::class,
        GetCommandHelp::class,
        ExecuteCommand::class,
        SearchDocumentation::class,
    ];

    /**
     * Override the boot method to use a custom tool caller.
     */
    protected function boot(): void
    {
        $this->instructions = $this->getAiInstructions();
        $this->addMethod('tools/call', LaraKubeCallTool::class);
    }
}
