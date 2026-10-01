<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->string('nonce', 255)->nullable()->after('scopes');
            $table->index(['client_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropIndex(['client_id', 'nonce']);
            $table->dropColumn('nonce');
        });
    }
};
