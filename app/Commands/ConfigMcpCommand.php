<?php

namespace App\Commands;

use App\Traits\InteractsWithGlobalMcpConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ConfigMcpCommand extends Command
{
    use InteractsWithGlobalMcpConfig, LaraKubeOutput;

    protected $signature = 'config:mcp {--all : Register for all supported AI tools} 
                                       {--gemini : Register for Gemini CLI} 
                                       {--claude : Register for Claude Code (Desktop)}
                                       {--docs : Register LaraKube Docs (Algolia MCP) for Gemini CLI}';

    protected $description = 'Register LaraKube as a global MCP server for AI tools';

    public function handle()
    {
        $this->renderHeader();
        $this->laraKubeInfo('Global AI / MCP Configuration');

        $all = $this->option('all');
        $gemini = $this->option('gemini') || $all;
        $claude = $this->option('claude') || $all;
        $docs = $this->option('docs') || $all;

        if (! $gemini && ! $claude && ! $docs) {
            $this->warn('  ⚠ No targets specified. Use --gemini, --claude, --docs, or --all.');

            return 1;
        }

        if ($gemini) {
            $this->task('Registering global MCP for Gemini CLI', function () {
                return $this->registerGlobalGeminiMcp();
            });
        }

        if ($claude) {
            $this->task('Registering global MCP for Claude Code', function () {
                return $this->registerGlobalClaudeMcp();
            });
        }

        if ($docs) {
            $this->task('Registering global MCP for LaraKube Docs (Algolia)', function () {
                return $this->registerGlobalDocsMcp();
            });
        }

        $this->line('');
        $this->laraKubeInfo('✅ Global MCP configuration complete!');
        $this->info('Restart your AI agents to pick up the new LaraKube tools.');

        return 0;
    }
}
