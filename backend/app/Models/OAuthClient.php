<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client as PassportClient;

class OAuthClient extends PassportClient
{
    protected $guarded = ['application_id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
