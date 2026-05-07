<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $customersTable = config('lunarpanel-xero.tables.customers', 'lunar_customers');

        Schema::table($customersTable, function (Blueprint $table) use ($customersTable): void {
            if (! Schema::hasColumn($customersTable, 'xero_include_order_line_notes')) {
                $table->boolean('xero_include_order_line_notes')->default(false)->after('xero_contact_id');
            }
        });
    }

    public function down(): void
    {
        $customersTable = config('lunarpanel-xero.tables.customers', 'lunar_customers');

        Schema::table($customersTable, function (Blueprint $table) use ($customersTable): void {
            if (Schema::hasColumn($customersTable, 'xero_include_order_line_notes')) {
                $table->dropColumn('xero_include_order_line_notes');
            }
        });
    }
};
