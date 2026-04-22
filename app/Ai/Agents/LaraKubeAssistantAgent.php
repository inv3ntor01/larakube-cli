<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ExecuteCommand;
use App\Ai\Tools\GetCommandHelp;
use App\Ai\Tools\ListCommands;
use App\Ai\Tools\SearchDocumentation;
use App\Mcp\Tools\ApplyHealingPatch;
use App\Mcp\Tools\DiagnosePod;
use App\Mcp\Tools\GetProjectConfig;
use App\Mcp\Tools\ListPods;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class LaraKubeAssistantAgent implements Agent, Conversational, HasTools
{
    use InteractsWithGlobalConfig, InteractsWithLaraKubeCli, Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return $this->getAiInstructions();
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new ListCommands,
            new GetCommandHelp,
            new ExecuteCommand,
            new SearchDocumentation,
            new ListPods,
            new DiagnosePod,
            new GetProjectConfig,
            new ApplyHealingPatch,
        ];
    }
}
