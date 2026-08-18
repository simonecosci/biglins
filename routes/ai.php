<?php

use App\Mcp\Servers\BiglinsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('biglins', BiglinsServer::class);

Mcp::web('/mcp/biglins', BiglinsServer::class)->middleware(['throttle:60,1', 'auth:sanctum,api']);

Mcp::oauthRoutes();
