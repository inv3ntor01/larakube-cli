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
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class ClusterDoctorAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $path = base_path('resources/ai/larakube-doctor.md');

        return file_exists($path) ? file_get_contents($path) : 'You are the LaraKube Cluster Doctor.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new ListPods,
            new DiagnosePod,
            new GetProjectConfig,
            new ApplyHealingPatch,
            new ListCommands,
            new GetCommandHelp,
            new ExecuteCommand,
            new SearchDocumentation,
        ];
    }
}
