<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('lunarpanel-xero.tables.product_variants', 'lunar_product_variants'), function (Blueprint $table): void {
            if (! Schema::hasColumn(config('lunarpanel-xero.tables.product_variants', 'lunar_product_variants'), 'xero_account_code')) {
                $table->string('xero_account_code')->nullable()->after('sku');
            }

            if (! Schema::hasColumn(config('lunarpanel-xero.tables.product_variants', 'lunar_product_variants'), 'xero_item_code')) {
                $table->string('xero_item_code')->nullable()->after('xero_account_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table(config('lunarpanel-xero.tables.product_variants', 'lunar_product_variants'), function (Blueprint $table): void {
            foreach (['xero_item_code', 'xero_account_code'] as $column) {
                if (Schema::hasColumn(config('lunarpanel-xero.tables.product_variants', 'lunar_product_variants'), $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
