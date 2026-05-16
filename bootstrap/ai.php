<?php

use App\Mcp\Servers\LaraKubeCliServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('mcp', LaraKubeCliServer::class);
