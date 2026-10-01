<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Also remove the column on installations that ran the foundation migration.
        if (Schema::hasColumn('oauth_auth_codes', 'redirect_uri')) {
            Schema::table('oauth_auth_codes', function (Blueprint $table): void {
                $table->dropColumn('redirect_uri');
            });
        }
    }

    public function down(): void
    {
        // Deliberately do not restore redundant authorization metadata.
    }
};
