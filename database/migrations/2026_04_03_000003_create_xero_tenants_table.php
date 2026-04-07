<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('lunarpanel-xero.tables.tenants', 'xero_tenants'), function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('tenant_name');
            $table->string('tenant_type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('updated_at_xero')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('lunarpanel-xero.tables.tenants', 'xero_tenants'));
    }
};
