<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAuditController extends Controller
{
    public function __invoke(Request $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $events = AuditLog::query()->where(function ($query) use ($application): void {
            $query->where(function ($query) use ($application): void {
                $query->where('subject_type', 'application')->where('subject_id', $application->public_id);
            })->orWhere('metadata->application_id', $application->public_id);
        })->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
        $events->setCollection($events->getCollection()->map(fn (AuditLog $event): array => [
            'id' => $event->id, 'action' => $event->action,
            'actor_id' => $event->actor_public_id, 'subject_id' => $event->subject_id,
            'client_id' => $event->metadata['client_id'] ?? null,
            'created_at' => $event->created_at->toISOString(),
        ]));

        return response()->json($events);
    }
}
