<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('lunarpanel-xero.tables.orders', 'lunar_orders'), function (Blueprint $table): void {
            $ordersTable = config('lunarpanel-xero.tables.orders', 'lunar_orders');

            if (! Schema::hasColumn($ordersTable, 'xero_invoice_number')) {
                $table->string('xero_invoice_number')->nullable()->after('xero_invoice_id');
            }

            if (! Schema::hasColumn($ordersTable, 'xero_invoice_status')) {
                $table->string('xero_invoice_status')->nullable()->after('xero_invoice_number');
            }

            if (! Schema::hasColumn($ordersTable, 'xero_online_invoice_url')) {
                $table->text('xero_online_invoice_url')->nullable()->after('xero_invoice_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table(config('lunarpanel-xero.tables.orders', 'lunar_orders'), function (Blueprint $table): void {
            $ordersTable = config('lunarpanel-xero.tables.orders', 'lunar_orders');

            foreach (['xero_online_invoice_url', 'xero_invoice_status', 'xero_invoice_number'] as $column) {
                if (Schema::hasColumn($ordersTable, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
