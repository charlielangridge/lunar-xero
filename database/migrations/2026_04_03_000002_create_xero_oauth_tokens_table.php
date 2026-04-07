<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('lunarpanel-xero.tables.oauth_tokens', 'xero_oauth_tokens'), function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key')->unique()->default('default');
            $table->longText('access_token');
            $table->longText('refresh_token')->nullable();
            $table->longText('id_token')->nullable();
            $table->string('token_type')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('lunarpanel-xero.tables.oauth_tokens', 'xero_oauth_tokens'));
    }
};
