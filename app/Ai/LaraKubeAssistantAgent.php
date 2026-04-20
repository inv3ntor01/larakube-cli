<?php

namespace App\Ai;

use App\Ai\Tools\ExecuteCommand;
use App\Ai\Tools\GetCommandHelp;
use App\Ai\Tools\ListCommands;
use App\Models\User;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class LaraKubeAssistantAgent implements Agent
{
    use Promptable, InteractsWithLaraKubeCli, RemembersConversations, InteractsWithGlobalConfig;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $path = base_path('resources/ai/larakube-assistant.md');

        return file_exists($path) ? file_get_contents($path) : 'You are LaraKube, a professional autonomous Kubernetes orchestrator for Laravel.';
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
        ];
    }
}
