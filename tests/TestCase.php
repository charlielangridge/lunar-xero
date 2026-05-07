<?php

declare(strict_types=1);

namespace CharlieLangridge\LunarXero\Tests;

use CharlieLangridge\LunarXero\LunarXeroServiceProvider;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Customer;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Payment;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Product;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\ProductVariant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LunarXeroServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('lunarpanel-xero.routes.middleware', []);
        $app['config']->set('lunarpanel-xero.tables.customers', 'test_customers');
        $app['config']->set('lunarpanel-xero.tables.products', 'test_products');
        $app['config']->set('lunarpanel-xero.tables.product_variants', 'test_product_variants');
        $app['config']->set('lunarpanel-xero.tables.orders', 'test_orders');
        $app['config']->set('lunarpanel-xero.models.customer', Customer::class);
        $app['config']->set('lunarpanel-xero.models.product', Product::class);
        $app['config']->set('lunarpanel-xero.models.variant', ProductVariant::class);
        $app['config']->set('lunarpanel-xero.models.order', Order::class);
        $app['config']->set('lunarpanel-xero.models.transaction', Payment::class);
        $app['config']->set('lunarpanel-xero.oauth.client_id', 'test-client');
        $app['config']->set('lunarpanel-xero.oauth.client_secret', 'test-secret');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('test_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('xero_contact_id')->nullable();
            $table->boolean('xero_include_order_line_notes')->default(false);
            $table->timestamps();
        });

        Schema::create('test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('published');
            $table->string('xero_account_code')->nullable();
            $table->string('xero_item_code')->nullable();
            $table->json('attribute_data')->nullable();
            $table->timestamps();
        });

        Schema::create('test_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('test_products');
            $table->string('sku')->nullable();
            $table->string('option_values')->nullable();
            $table->string('xero_account_code')->nullable();
            $table->string('xero_item_code')->nullable();
            $table->timestamps();
        });

        Schema::create('test_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('test_customers');
            $table->string('reference')->nullable();
            $table->string('customer_reference')->nullable();
            $table->json('meta')->nullable();
            $table->string('xero_invoice_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_order_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('test_orders');
            $table->string('type')->default('billing');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('line_one')->nullable();
            $table->string('line_two')->nullable();
            $table->string('line_three')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamps();
        });

        Schema::create('test_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('test_orders');
            $table->foreignId('product_id')->nullable()->constrained('test_products');
            $table->foreignId('product_variant_id')->nullable()->constrained('test_product_variants');
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('test_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('test_orders');
            $table->boolean('success')->nullable();
            $table->string('type')->nullable();
            $table->string('driver')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('gateway')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
