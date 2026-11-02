<?php

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\OAuthApplicationRequest;
use App\Models\OAuthClient;
use App\Services\AuditService;
use App\Services\OAuthClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminOAuthRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = OAuthApplicationRequest::query()
            ->with(['application', 'oauthClient', 'reviewer', 'company']);

        $status = $request->query('status');
        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('developer_name', 'like', "%{$search}%")
                    ->orWhere('developer_email', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) ($request->query('per_page', 15))));
        $paginated = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json($paginated);
    }

    public function stats(): JsonResponse
    {
        $pending = OAuthApplicationRequest::query()->where('status', 'pending')->count();
        $approved = OAuthApplicationRequest::query()->where('status', 'approved')->count();
        $rejected = OAuthApplicationRequest::query()->where('status', 'rejected')->count();

        return response()->json([
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ]);
    }

    public function show(OAuthApplicationRequest $oauthApplicationRequest): JsonResponse
    {
        $oauthApplicationRequest->load(['application', 'oauthClient', 'reviewer']);

        return response()->json([
            'data' => $this->transform($oauthApplicationRequest),
        ]);
    }

    public function approve(
        Request $request,
        OAuthApplicationRequest $oauthApplicationRequest,
        OAuthClientService $clientService,
        AuditService $audit
    ): JsonResponse {
        if ($oauthApplicationRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This request has already been '.$oauthApplicationRequest->status.'.',
            ], Response::HTTP_CONFLICT);
        }

        $admin = $request->user();
        $notes = $request->input('notes');

        $result = DB::transaction(function () use ($oauthApplicationRequest, $clientService, $audit, $admin, $notes) {
            // Lock record
            $locked = OAuthApplicationRequest::query()->lockForUpdate()->findOrFail($oauthApplicationRequest->getKey());
            if ($locked->status !== 'pending') {
                abort(Response::HTTP_CONFLICT, 'This request has already been '.$locked->status.'.');
            }

            // 1. Generate unique slug for Application
            $baseSlug = Str::slug($locked->name);
            if (empty($baseSlug)) {
                $baseSlug = 'app-'.strtolower(Str::random(6));
            }
            $slug = $baseSlug;
            $counter = 1;
            while (Application::query()->where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }

            // 2. Create Application
            $application = Application::create([
                'name' => $locked->name,
                'slug' => $slug,
                'description' => $locked->description,
                'company_id' => $locked->company_id,
                'status' => ApplicationStatus::Active,
            ]);

            // 3. Create OAuth Client using OAuthClientService
            $client = $clientService->create($application, [
                'name' => $locked->name.' Client',
                'redirect_uris' => $locked->redirect_uris,
                'confidential' => $locked->confidential,
            ]);

            // 4. Update request status
            $locked->update([
                'status' => 'approved',
                'application_id' => $application->getKey(),
                'oauth_client_id' => $client->getKey(),
                'client_secret' => $client->secret,
                'reviewed_by' => $admin?->getKey(),
                'reviewed_at' => now(),
                'admin_notes' => $notes ?? $locked->admin_notes,
            ]);

            $audit->record('oauth_request.approved', $application, [
                'client_id' => $client->getKey(),
                'application_id' => (string) $application->getKey(),
                'status' => 'approved',
            ], $admin);

            return $locked->fresh(['application', 'oauthClient', 'reviewer']);
        });

        return response()->json([
            'message' => 'OAuth application request approved successfully.',
            'data' => $this->transform($result),
            ...($result->client_secret ? ['client_secret' => $result->client_secret] : []),
        ]);
    }

    public function reject(
        Request $request,
        OAuthApplicationRequest $oauthApplicationRequest,
        AuditService $audit
    ): JsonResponse {
        if ($oauthApplicationRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This request has already been '.$oauthApplicationRequest->status.'.',
            ], Response::HTTP_CONFLICT);
        }

        $admin = $request->user();
        $notes = $request->input('notes') ?? $request->input('reason');

        $result = DB::transaction(function () use ($oauthApplicationRequest, $audit, $admin, $notes) {
            $locked = OAuthApplicationRequest::query()->lockForUpdate()->findOrFail($oauthApplicationRequest->getKey());
            if ($locked->status !== 'pending') {
                abort(Response::HTTP_CONFLICT, 'This request has already been '.$locked->status.'.');
            }

            $locked->update([
                'status' => 'rejected',
                'reviewed_by' => $admin?->getKey(),
                'reviewed_at' => now(),
                'admin_notes' => $notes ?? $locked->admin_notes,
            ]);

            $audit->record('oauth_request.rejected', null, [
                'status' => 'rejected',
                'reason' => $notes,
            ], $admin);

            return $locked->fresh(['reviewer']);
        });

        return response()->json([
            'message' => 'OAuth application request rejected.',
            'data' => $this->transform($result),
        ]);
    }

    private function transform(OAuthApplicationRequest $req): array
    {
        return [
            'id' => $req->public_id,
            'reference_code' => $req->reference_code,
            'name' => $req->name,
            'slug' => $req->slug,
            'developer_name' => $req->developer_name,
            'developer_email' => $req->developer_email,
            'organization' => $req->organization,
            'company' => $req->company ? [
                'id' => $req->company->public_id,
                'name' => $req->company->name,
            ] : null,
            'description' => $req->description,
            'redirect_uris' => $req->redirect_uris,
            'confidential' => $req->confidential,
            'requested_scopes' => $req->requested_scopes,
            'status' => $req->status,
            'admin_notes' => $req->admin_notes,
            'created_at' => $req->created_at?->toISOString(),
            'reviewed_at' => $req->reviewed_at?->toISOString(),
            'reviewer' => $req->reviewer ? [
                'id' => $req->reviewer->public_id,
                'name' => $req->reviewer->name,
                'email' => $req->reviewer->email,
            ] : null,
            'application' => $req->application ? [
                'id' => $req->application->public_id,
                'name' => $req->application->name,
                'slug' => $req->application->slug,
            ] : null,
            'oauth_client_id' => $req->oauth_client_id,
            'client_secret' => $req->client_secret,
        ];
    }
}

