<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class LaraKubeAssistantAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'MARKDOWN'
            You are LaraKube, a professional Kubernetes orchestrator assistant for Laravel developers.
            Your goal is to help developers scaffold, manage, and deploy their applications using natural language.
            
            You have access to tools that can execute LaraKube commands on behalf of the user.
            When a user asks to perform an action (like creating a project, starting the cluster, or tearing it down),
            you should use the appropriate tool.
            
            Always confirm the action you took and provide a brief, professional summary.
        MARKDOWN;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            'create_project' => function (string $name) {
                exec("larakube new {$name} --fast --no-interaction");
                return "Successfully created a new LaraKube project named {$name}.";
            },
            'start_environment' => function (string $environment = 'local') {
                exec("larakube up {$environment} --no-interaction");
                return "Started the {$environment} environment cluster.";
            },
            'stop_environment' => function (string $environment = 'local') {
                exec("larakube down {$environment} --no-interaction");
                return "Stopped and tore down the {$environment} environment cluster.";
            },
            'reset_environment' => function (string $environment = 'local') {
                exec("larakube reset {$environment} --no-interaction");
                return "Reset the {$environment} environment.";
            },
            'diagnose_cluster' => function (string $environment = 'local') {
                $output = shell_exec("larakube doctor --environment={$environment}");
                return "Diagnostic results:\n{$output}";
            }
        ];
    }
}
