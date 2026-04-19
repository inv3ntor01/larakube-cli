<?php

namespace App\Traits;

trait InteractsWithMcpConfig
{
    /**
     * Scaffold all necessary AI/MCP configuration files for the project.
     * We focus primarily on IDE-specific settings to avoid global conflicts.
     */
    protected function scaffoldMcpConfigs(string $projectPath): void
    {
        $this->scaffoldMcpSettings($projectPath);
    }

    /**
     * Scaffold Gemini CLI configuration.
     */
    protected function scaffoldGeminiConfig(string $projectPath): void
    {
        $path = $projectPath . '/.gemini/settings.json';
        @mkdir(dirname($path), 0755, true);

        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        
        $config = array_merge_recursive($existing, [
            'mcpServers' => [
                'larakube' => [
                    'command' => '/usr/local/bin/larakube',
                    'args' => ['mcp'],
                ],
            ],
        ]);

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Scaffold generic MCP settings (compatible with Cursor/VSCode).
     */
    protected function scaffoldMcpSettings(string $projectPath): void
    {
        $path = $projectPath . '/.vscode/settings.json';
        @mkdir(dirname($path), 0755, true);

        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // VSCode uses a different structure for MCP
        $config = array_merge_recursive($existing, [
            'mcp' => [
                'servers' => [
                    'larakube' => [
                        'command' => '/usr/local/bin/larakube',
                        'args' => ['mcp'],
                    ],
                ],
            ],
        ]);

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Scaffold Claude Code configuration.
     */
    protected function scaffoldClaudeConfig(string $projectPath): void
    {
        $path = $projectPath . '/mcp.json';
        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        $config = array_merge_recursive($existing, [
            'mcpServers' => [
                'larakube' => [
                    'command' => '/usr/local/bin/larakube',
                    'args' => ['mcp'],
                ],
            ],
        ]);

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
