<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OAuthApplicationRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'oauth_application_requests';

    protected $fillable = [
        'name',
        'slug',
        'developer_name',
        'developer_email',
        'organization',
        'company_id',
        'description',
        'redirect_uris',
        'confidential',
        'requested_scopes',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'application_id',
        'oauth_client_id',
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'requested_scopes' => 'array',
            'confidential' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        static::creating(function (OAuthApplicationRequest $req): void {
            if (empty($req->reference_code)) {
                $req->reference_code = 'REQ-'.strtoupper(Str::random(8));
            }
            if (empty($req->slug)) {
                $baseSlug = Str::slug($req->name);
                $req->slug = ! empty($baseSlug) ? $baseSlug : 'app-'.strtolower(Str::random(6));
            }
            if (empty($req->requested_scopes)) {
                $req->requested_scopes = ['openid', 'profile', 'email'];
            }
            if (empty($req->status)) {
                $req->status = 'pending';
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }
}

