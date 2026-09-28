<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $attributes = [
        'status' => AccountStatus::Active->value,
        'is_system_admin' => false,
        'session_version' => 0,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'session_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'is_system_admin' => 'boolean',
            'session_version' => 'integer',
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

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user', 'user_id', 'company_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'application_user', 'user_id', 'application_id')
            ->withPivot(['status', 'granted_by'])
            ->withTimestamps();
    }

    public function isCentralIamAdministrator(): bool
    {
        return ! $this->trashed() && $this->exists && static::query()
            ->whereKey($this->getKey())
            ->where('status', AccountStatus::Active->value)
            ->where('is_system_admin', true)->exists();
    }

    public function isActive(): bool
    {
        return ! $this->trashed() && $this->exists && static::query()
            ->whereKey($this->getKey())->where('status', AccountStatus::Active->value)->exists();
    }

    public function hasAccessToApplication(Application $application): bool
    {
        return $this->applicationAccessState($application)['effective_access'];
    }

    /** @return array{assigned: bool, effective_access: bool, ineffective_reason: string|null} */
    public function applicationAccessState(Application $application): array
    {
        $user = $this->exists ? static::withTrashed()->whereKey($this->getKey())->first() : null;
        $registeredApplication = $application->exists
            ? Application::withTrashed()->whereKey($application->getKey())->first()
            : null;
        $assignment = $user && $registeredApplication
            ? DB::table('application_user')
                ->where('user_id', $user->getKey())
                ->where('application_id', $registeredApplication->getKey())
                ->first()
            : null;

        $assigned = $assignment !== null;
        $reason = match (true) {
            $user === null => 'user_missing',
            $user->deleted_at !== null => 'user_deleted',
            $user->status !== AccountStatus::Active => 'user_'.$user->status->value,
            $registeredApplication === null => 'application_missing',
            $registeredApplication->deleted_at !== null => 'application_deleted',
            $registeredApplication->status !== ApplicationStatus::Active => 'application_'.$registeredApplication->status->value,
            ! $assigned => 'not_assigned',
            $assignment->status !== MembershipStatus::Active->value => 'assignment_'.$assignment->status,
            default => null,
        };

        return [
            'assigned' => $assigned,
            'effective_access' => $reason === null,
            'ineffective_reason' => $reason,
        ];
    }
}
