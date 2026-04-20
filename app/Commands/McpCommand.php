<?php

namespace App\Commands;

use App\Mcp\LaraKubeServer;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Transport\StdioTransport;
use LaravelZero\Framework\Commands\Command;

class McpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Model Context Protocol (MCP) server for LaraKube';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // CRITICAL: We do NOT call $this->renderHeader() here.
        // MCP servers must be absolutely silent on STDOUT except for JSON-RPC messages.

        $transport = new StdioTransport(Str::uuid()->toString());
        $server = new LaraKubeServer($transport);

        $server->start();

        $transport->run();
    }
}
