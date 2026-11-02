<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_application_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('reference_code', 32)->unique()->index();
            $table->string('name');
            $table->string('slug');
            $table->string('developer_name');
            $table->string('developer_email')->index();
            $table->string('organization')->nullable();
            $table->text('description')->nullable();
            $table->text('redirect_uris');
            $table->boolean('confidential')->default(true);
            $table->text('requested_scopes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->uuid('oauth_client_id')->nullable()->index();
            $table->text('client_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_application_requests');
    }
};

