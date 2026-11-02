<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_application_requests', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('organization')->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_application_requests', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};

