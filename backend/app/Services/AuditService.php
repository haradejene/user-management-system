<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public function __construct(private Request $request) {}

    /** Metadata is allowlisted; never pass request bodies or model snapshots. */
    public function record(string $action, ?Model $subject = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        $actor ??= $this->request->user() ?? Auth::user();

        return AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'actor_public_id' => $actor?->public_id,
            'action' => $action,
            'subject_type' => $subject ? strtolower(class_basename($subject)) : null,
            'subject_id' => $subject?->public_id,
            'metadata' => array_intersect_key($metadata, array_flip([
                'changed_fields', 'previous_status', 'status', 'company_id', 'application_id', 'reason',
            ])),
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr($this->request->userAgent() ?? '', 0, 1024),
            'created_at' => now(),
        ]);
    }
}
