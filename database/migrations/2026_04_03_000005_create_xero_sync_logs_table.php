<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('lunarpanel-xero.tables.sync_logs', 'xero_sync_logs'), function (Blueprint $table): void {
            $table->id();
            $table->string('operation');
            $table->string('status');
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->json('context')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['resource_type', 'resource_id']);
            $table->index(['operation', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('lunarpanel-xero.tables.sync_logs', 'xero_sync_logs'));
    }
};
