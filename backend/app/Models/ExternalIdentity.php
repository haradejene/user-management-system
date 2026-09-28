<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentity extends Model
{
    protected $fillable = ['issuer', 'subject'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
