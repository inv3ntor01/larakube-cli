<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetCurrentWorkingDirectoryTool;
use App\Mcp\Tools\InspectLocalCodeTool;
use App\Mcp\Tools\LocalHealthCheckTool;
use App\Mcp\Tools\OrchestrateVerbTool;
use App\Mcp\Tools\PatchBlueprintTool;
use App\Mcp\Tools\PatchLocalEnvTool;
use App\Mcp\Tools\RunProxyTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('LaraKube CLI Server')]
#[Version('0.0.1')]
#[Instructions('You are the LaraKube Local Mechanic. Your goal is to help the developer orchestrate their current project. Use tools to inspect code, patch blueprints, and run orchestration commands.')]
class LaraKubeCliServer extends Server
{
    protected array $tools = [
        GetCurrentWorkingDirectoryTool::class,
        InspectLocalCodeTool::class,
        LocalHealthCheckTool::class,
        OrchestrateVerbTool::class,
        PatchBlueprintTool::class,
        PatchLocalEnvTool::class,
        RunProxyTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
