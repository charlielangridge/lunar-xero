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
            if (! Schema::hasColumn(config('lunarpanel-xero.tables.orders', 'lunar_orders'), 'xero_invoice_id')) {
                $table->string('xero_invoice_id')->nullable()->after('reference')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table(config('lunarpanel-xero.tables.orders', 'lunar_orders'), function (Blueprint $table): void {
            if (Schema::hasColumn(config('lunarpanel-xero.tables.orders', 'lunar_orders'), 'xero_invoice_id')) {
                $table->dropColumn('xero_invoice_id');
            }
        });
    }
};
