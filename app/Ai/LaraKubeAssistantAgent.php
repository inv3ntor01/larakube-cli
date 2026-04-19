<?php

namespace App\Ai;

use App\Traits\InteractsWithLaraKubeCli;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class LaraKubeAssistantAgent implements Agent
{
    use Promptable, InteractsWithLaraKubeCli;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'MARKDOWN'
            You are LaraKube, the autonomous Kubernetes orchestrator for Laravel.
            Your goal is to scaffold and manage infrastructure by executing CLI commands.
            
            ### CRITICAL RULES:
            1.  **EXECUTE IMMEDIATELY**: When a user asks for an action (e.g., "new project", "up", "stop"), you MUST call `execute_command` immediately.
            2.  **NO PREAMBLES**: Do not say "I will...", "Sure, let me...", or "I'm going to...". 
            3.  **DISCOVERY**: If you don't know the flags for a command, call `get_command_help`.
            4.  **CONTEXT**: Use `get_project_context` to see the current architectural blueprint if you are inside a project.
            5.  **SILENT SUCCESS**: If a tool execution is successful, provide a brief, professional confirmation of the result.
        MARKDOWN;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            'list_commands' => [
                'description' => 'List all available LaraKube commands.',
                'callback' => fn() => $this->listCliCommands(),
            ],
            'get_command_help' => [
                'description' => 'Get flags and arguments for a specific command (e.g., new, up).',
                'callback' => fn(string $command) => $this->getCliCommandHelp($command),
            ],
            'get_project_context' => [
                'description' => 'Read the .larakube.json blueprint for the current directory.',
                'callback' => function() {
                    $path = getcwd() . '/.larakube.json';
                    return file_exists($path) ? file_get_contents($path) : 'No LaraKube project found in this directory.';
                }
            ],
            'execute_command' => [
                'description' => 'Execute a LaraKube CLI command (e.g., "new my-app --fast").',
                'callback' => function (string $command) {
                    $result = $this->executeCliCommand($command);
                    return $result['success'] 
                        ? "SUCCESS: {$result['command']}\n{$result['output']}"
                        : "FAILURE: {$result['output']}";
                },
            ],
        ];
    }
}
