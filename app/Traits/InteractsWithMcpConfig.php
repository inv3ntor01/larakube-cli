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
     * Scaffold generic MCP settings (compatible with Cursor/VSCode).
     */
    protected function scaffoldMcpSettings(string $projectPath): void
    {
        $path = $projectPath.'/.vscode/settings.json';
        @mkdir(dirname($path), 0755, true);

        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // VSCode uses: mcp.servers.larakube
        if (! isset($config['mcp'])) {
            $config['mcp'] = [];
        }
        if (! isset($config['mcp']['servers'])) {
            $config['mcp']['servers'] = [];
        }

        $config['mcp']['servers']['larakube'] = [
            'command' => '/usr/local/bin/larakube',
            'args' => ['mcp'],
        ];

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Scaffold Gemini CLI configuration (Legacy/Project-specific).
     */
    protected function scaffoldGeminiConfig(string $projectPath): void
    {
        $path = $projectPath.'/.gemini/settings.json';
        @mkdir(dirname($path), 0755, true);

        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        if (! isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['larakube'] = [
            'command' => '/usr/local/bin/larakube',
            'args' => ['mcp'],
        ];

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Scaffold Claude Code configuration (Legacy/Project-specific).
     */
    protected function scaffoldClaudeConfig(string $projectPath): void
    {
        $path = $projectPath.'/mcp.json';
        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        if (! isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['larakube'] = [
            'command' => '/usr/local/bin/larakube',
            'args' => ['mcp'],
        ];

        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
