<?php

declare(strict_types=1);

use CharlieLangridge\LunarXero\Enums\InvoiceStatus;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\Transaction;

return [
    'defaults' => [
        'invoice_status' => InvoiceStatus::Draft->value,
        'sync_queue' => 'xero',
        'account_cache_ttl' => 600,
    ],

    'oauth' => [
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
        'redirect_uri' => env('XERO_REDIRECT_URI'),
        'read_only' => env('XERO_READ_ONLY', false),
        'read_scopes' => [
            'openid',
            'email',
            'profile',
            'offline_access',
            'accounting.contacts.read',
            'accounting.settings',
            'accounting.transactions.read',
            'accounting.reports.read',
        ],
        'write_scopes' => [
            'openid',
            'email',
            'profile',
            'offline_access',
            'accounting.contacts',
            'accounting.settings',
            'accounting.transactions',
            'accounting.reports.read',
        ],
        'authorize_url' => 'https://login.xero.com/identity/connect/authorize',
        'token_url' => 'https://identity.xero.com/connect/token',
        'connections_url' => 'https://api.xero.com/connections',
        'revoke_url' => 'https://identity.xero.com/connect/revocation',
    ],

    'tables' => [
        'settings' => 'xero_settings',
        'oauth_tokens' => 'xero_oauth_tokens',
        'tenants' => 'xero_tenants',
        'payment_type_mappings' => 'xero_payment_type_mappings',
        'sync_logs' => 'xero_sync_logs',
        'customers' => 'lunar_customers',
        'products' => 'lunar_products',
        'product_variants' => 'lunar_product_variants',
        'orders' => 'lunar_orders',
    ],

    'models' => [
        'customer' => Customer::class,
        'product' => Product::class,
        'variant' => ProductVariant::class,
        'order' => Order::class,
        'transaction' => Transaction::class,
    ],

    'events' => [
        'order_created' => 'Lunar\\Events\\OrderCreated',
        'payment_completed' => 'Lunar\\Events\\PaymentCaptured',
    ],

    'routes' => [
        'prefix' => env('LUNARPANEL_XERO_ROUTE_PREFIX'),
        'middleware' => null,
    ],

    'cache' => [
        'accounts_key' => 'lunar-xero.accounts',
        'tenants_key' => 'lunar-xero.tenants',
    ],

    'charity' => [
        'enabled' => true,
        'meta_root_path' => 'meta.charity_vat_relief',
        'name_path' => 'meta.charity_vat_relief.charity_name',
        'number_path' => 'meta.charity_vat_relief.charity_number',
        'declaration_name_path' => 'meta.charity_vat_relief.declaration_name',
        'declared_at_path' => 'meta.charity_vat_relief.declared_at',
    ],
];
