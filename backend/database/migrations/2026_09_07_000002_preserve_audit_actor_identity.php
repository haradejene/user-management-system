<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            // Historical identity must survive deletion of the live user row.
            $table->uuid('actor_public_id')->nullable();
            $table->index(['actor_public_id', 'created_at', 'id'], 'audit_logs_actor_history_index');
            $table->index(['action', 'created_at', 'id'], 'audit_logs_action_history_index');
            $table->index(['subject_type', 'subject_id', 'created_at', 'id'], 'audit_logs_subject_history_index');
        });

        DB::table('audit_logs')->whereNotNull('actor_id')->update([
            'actor_public_id' => DB::raw('(select public_id from users where users.id = audit_logs.actor_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_actor_history_index');
            $table->dropIndex('audit_logs_action_history_index');
            $table->dropIndex('audit_logs_subject_history_index');
            $table->dropColumn('actor_public_id');
        });
    }
};
