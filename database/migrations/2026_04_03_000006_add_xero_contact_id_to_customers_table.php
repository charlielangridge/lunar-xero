<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('lunarpanel-xero.tables.customers', 'lunar_customers'), function (Blueprint $table): void {
            if (! Schema::hasColumn(config('lunarpanel-xero.tables.customers', 'lunar_customers'), 'xero_contact_id')) {
                $table->string('xero_contact_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table(config('lunarpanel-xero.tables.customers', 'lunar_customers'), function (Blueprint $table): void {
            if (Schema::hasColumn(config('lunarpanel-xero.tables.customers', 'lunar_customers'), 'xero_contact_id')) {
                $table->dropColumn('xero_contact_id');
            }
        });
    }
};
