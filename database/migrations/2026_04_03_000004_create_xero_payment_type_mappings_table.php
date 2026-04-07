<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('lunarpanel-xero.tables.payment_type_mappings', 'xero_payment_type_mappings'), function (Blueprint $table): void {
            $table->id();
            $table->string('payment_type')->unique();
            $table->string('account_code');
            $table->string('account_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('lunarpanel-xero.tables.payment_type_mappings', 'xero_payment_type_mappings'));
    }
};
