<?php

namespace App\Commands;

use App\Data\GlobalConfigData;
use App\Traits\InteractsWithOs;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use Phar;

class McpRegisterCommand extends Command
{
    use InteractsWithOs, LaraKubeOutput;

    protected $signature = 'mcp:register
                            {--claude : Register the CLI MCP with Claude Desktop}
                            {--all : Register with all supported AI tools}';

    protected $description = 'Register the LaraKube CLI MCP server with AI tools';

    public function handle()
    {
        $this->renderHeader();

        $claude = $this->option('claude') || $this->option('all');

        if (! $claude) {
            $this->error('  Please specify a tool to register with (--claude or --all).');

            return 1;
        }

        // Get the absolute path of the binary being executed
        $binaryPath = Phar::running(false) ?: realpath($_SERVER['argv'][0]);

        // Clean phar:// wrappers and trailing slashes
        $binaryPath = str_replace('phar://', '', $binaryPath);
        $binaryPath = rtrim($binaryPath, '/');

        if (! $binaryPath || str_ends_with($binaryPath, 'php')) {
            $this->error('  Could not reliably detect the LaraKube binary path.');
            $this->line('  👉 Please run this command using the compiled larakube binary.');

            return 1;
        }

        if ($claude) {
            $this->registerWithClaude($binaryPath);
        }

        return 0;
    }

    protected function registerWithClaude(string $binaryPath): void
    {
        $home = home_path();

        $configPath = $this->isDarwin()
            ? $home.'/Library/Application Support/Claude/claude_desktop_config.json'
            : $home.'/.config/Claude/claude_desktop_config.json';

        $this->updateJsonConfig($configPath, 'Claude Desktop', [
            'mcpServers' => [
                'larakube-cli' => [
                    'command' => $binaryPath,
                    'args' => ['mcp:start', 'mcp'],
                ],
                'larakube-console' => [
                    'url' => 'https://console.'.GlobalConfigData::load()->getLocalTld().'/mcp',
                ],
            ],
        ]);
    }

    protected function updateJsonConfig(string $path, string $toolName, array $newConfig): void
    {
        $this->laraKubeInfo("Registering with {$toolName}...");

        $dir = dirname($path);
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $config = [];
        if (File::exists($path)) {
            $config = json_decode(File::get($path), true) ?: [];
        }

        // Merge MCP servers
        $config['mcpServers'] = array_merge($config['mcpServers'] ?? [], $newConfig['mcpServers']);

        File::put($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("  ✔ Successfully registered with {$toolName}.");
        $this->line("    <fg=gray>Path: {$path}</>");
        $this->line('');
    }
}
