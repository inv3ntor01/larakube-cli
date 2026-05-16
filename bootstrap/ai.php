<?php

use App\Mcp\Servers\LaraKubeCliServer;
use Laravel\Mcp\Facades\Mcp;

// MCP servers must run indefinitely
set_time_limit(0);

Mcp::local('mcp', LaraKubeCliServer::class);
