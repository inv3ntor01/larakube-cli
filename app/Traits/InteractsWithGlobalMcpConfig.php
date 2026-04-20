<?php

namespace App\Traits;

trait InteractsWithGlobalMcpConfig
{
    /**
     * Register LaraKube as a global MCP server for the Gemini CLI.
     */
    protected function registerGlobalGeminiMcp(): bool
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $path = $home.'/.gemini/settings.json';

        @mkdir(dirname($path), 0700, true);

        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // Ensure mcpServers exists
        if (! isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        // Direct assignment prevents array_merge_recursive from duplicating strings into arrays
        $config['mcpServers']['larakube'] = [
            'command' => '/usr/local/bin/larakube',
            'args' => ['mcp'],
        ];

        return (bool) file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Register LaraKube as a global MCP server for Claude Code.
     */
    protected function registerGlobalClaudeMcp(): bool
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $os = PHP_OS_FAMILY;

        $path = match ($os) {
            'Darwin' => $home.'/Library/Application Support/Claude/claude_desktop_config.json',
            'Linux' => $home.'/.config/Claude/claude_desktop_config.json',
            default => null,
        };

        if (! $path) {
            return false;
        }

        @mkdir(dirname($path), 0755, true);

        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        if (! isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['larakube'] = [
            'command' => '/usr/local/bin/larakube',
            'args' => ['mcp'],
        ];

        return (bool) file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
