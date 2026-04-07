<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('lunarpanel-xero.tables.settings', 'xero_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique()->default('default');
            $table->string('invoice_status')->default(config('lunarpanel-xero.defaults.invoice_status', 'DRAFT'));
            $table->string('active_tenant_id')->nullable();
            $table->string('default_account_code')->nullable();
            $table->json('connection_meta')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('lunarpanel-xero.tables.settings', 'xero_settings'));
    }
};
