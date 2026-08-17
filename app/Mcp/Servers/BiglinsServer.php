<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateCustomerTool;
use App\Mcp\Tools\CreateEstimationTool;
use App\Mcp\Tools\CreateInvoiceTool;
use App\Mcp\Tools\ListCustomersTool;
use App\Mcp\Tools\ListEstimationsTool;
use App\Mcp\Tools\ListInvoicesTool;
use App\Mcp\Tools\SendEstimationEmailTool;
use App\Mcp\Tools\SendInvoiceEmailTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Biglins')]
#[Version('1.0.0')]
#[Instructions('Manage customers, estimations, and invoices for a Biglins company, and send them by email. Every tool takes an explicit company_id — use list_customers/list_estimations/list_invoices to discover existing records before creating new ones.')]
class BiglinsServer extends Server
{
    protected array $tools = [
        ListCustomersTool::class,
        CreateCustomerTool::class,
        ListEstimationsTool::class,
        CreateEstimationTool::class,
        ListInvoicesTool::class,
        CreateInvoiceTool::class,
        SendEstimationEmailTool::class,
        SendInvoiceEmailTool::class,
    ];
}
