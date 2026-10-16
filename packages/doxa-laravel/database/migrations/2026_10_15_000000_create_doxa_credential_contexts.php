<?php

use Doxa\Laravel\Credentials\CredentialSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        CredentialSchema::create(DB::connection(config('doxa.credentials.connection'))->getSchemaBuilder(), config('doxa.credentials.table', 'doxa_credential_contexts'));
    }

    public function down(): void
    {
        DB::connection(config('doxa.credentials.connection'))->getSchemaBuilder()->dropIfExists(config('doxa.credentials.table', 'doxa_credential_contexts'));
    }
};
