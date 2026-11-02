<?php

namespace Tests\Feature\Developers;

use App\Models\Application;
use App\Models\Company;
use App\Models\OAuthApplicationRequest;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthApplicationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_active_companies_for_developers(): void
    {
        Company::factory()->create(['name' => 'Active Corp', 'status' => 'active']);
        Company::factory()->create(['name' => 'Inactive Corp', 'status' => 'inactive']);

        $response = $this->getJson('/api/developers/companies');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Corp');
    }

    public function test_public_user_can_submit_oauth_application_request(): void
    {
        $payload = [
            'name' => 'Acme Analytics',
            'developer_name' => 'Alice Developer',
            'developer_email' => 'Alice@example.com',
            'organization' => 'Acme Corp',
            'description' => 'Real-time telemetry and reporting service.',
            'redirect_uris' => [
                'https://analytics.acme.test/auth/callback',
                'https://analytics.acme.test/login/callback',
            ],
            'confidential' => true,
        ];

        $response = $this->postJson('/api/developers/oauth-requests', $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'reference_code',
                    'public_id',
                    'name',
                    'developer_name',
                    'developer_email',
                    'status',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('oauth_application_requests', [
            'name' => 'Acme Analytics',
            'developer_name' => 'Alice Developer',
            'developer_email' => 'alice@example.com',
            'status' => 'pending',
            'confidential' => true,
        ]);
    }

    public function test_public_submission_validates_required_fields_and_redirect_uris(): void
    {
        $response = $this->postJson('/api/developers/oauth-requests', [
            'name' => '',
            'developer_name' => '',
            'developer_email' => 'not-an-email',
            'redirect_uris' => ['https://example.com/callback#fragment', 'invalid-url'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'developer_name', 'developer_email', 'redirect_uris.0', 'redirect_uris.1']);
    }

    public function test_public_user_can_lookup_status_by_reference_code(): void
    {
        $request = OAuthApplicationRequest::create([
            'name' => 'Billing Portal',
            'developer_name' => 'Bob Smith',
            'developer_email' => 'bob@example.com',
            'redirect_uris' => ['https://billing.test/callback'],
            'confidential' => true,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/developers/oauth-requests/'.$request->reference_code);

        $response->assertOk()
            ->assertJsonPath('data.reference_code', $request->reference_code)
            ->assertJsonPath('data.name', 'Billing Portal')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_public_lookup_returns_404_for_unknown_reference(): void
    {
        $response = $this->getJson('/api/developers/oauth-requests/REQ-NONEXIST');
        $response->assertNotFound();
    }

    public function test_unauthenticated_or_non_admin_cannot_access_admin_oauth_requests(): void
    {
        $this->getJson('/api/admin/oauth-requests')->assertUnauthorized();

        $regularUser = User::factory()->create(['is_system_admin' => false]);
        $this->actingAs($regularUser)->getJson('/api/admin/oauth-requests')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_oauth_requests(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        OAuthApplicationRequest::create([
            'name' => 'Pending App',
            'developer_name' => 'Charlie',
            'developer_email' => 'charlie@example.com',
            'redirect_uris' => ['https://pending.test/callback'],
            'status' => 'pending',
        ]);

        OAuthApplicationRequest::create([
            'name' => 'Approved App',
            'developer_name' => 'Diana',
            'developer_email' => 'diana@example.com',
            'redirect_uris' => ['https://approved.test/callback'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/oauth-requests?status=pending');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pending App');

        $stats = $this->actingAs($admin)->getJson('/api/admin/oauth-requests/stats');
        $stats->assertOk()
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('approved', 1)
            ->assertJsonPath('total', 2);
    }

    public function test_admin_can_approve_pending_request_and_it_creates_application_and_client(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $appRequest = OAuthApplicationRequest::create([
            'name' => 'Customer Portal',
            'developer_name' => 'Eva Green',
            'developer_email' => 'eva@example.com',
            'description' => 'Self-service customer portal.',
            'redirect_uris' => ['https://portal.customer.test/auth/callback'],
            'confidential' => true,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/oauth-requests/'.$appRequest->public_id.'/approve', [
            'notes' => 'Approved for staging environment.',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'OAuth application request approved successfully.')
            ->assertJsonPath('data.status', 'approved');

        $appRequest->refresh();
        $this->assertEquals('approved', $appRequest->status);
        $this->assertNotNull($appRequest->application_id);
        $this->assertNotNull($appRequest->oauth_client_id);
        $this->assertNotNull($appRequest->client_secret);
        $this->assertEquals($admin->id, $appRequest->reviewed_by);

        $application = Application::find($appRequest->application_id);
        $this->assertNotNull($application);
        $this->assertEquals('Customer Portal', $application->name);

        $client = OAuthClient::find($appRequest->oauth_client_id);
        $this->assertNotNull($client);
        $this->assertEquals($application->id, $client->application_id);
        $this->assertEquals(['https://portal.customer.test/auth/callback'], $client->redirect_uris);

        // Developer checks status on public endpoint and retrieves credentials
        $publicCheck = $this->getJson('/api/developers/oauth-requests/'.$appRequest->reference_code);
        $publicCheck->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.client_secret', $appRequest->client_secret);
    }

    public function test_can_submit_and_approve_with_linked_company(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Global', 'status' => 'active']);
        $admin = User::factory()->systemAdmin()->create();

        $response = $this->postJson('/api/developers/oauth-requests', [
            'name' => 'Acme Portal',
            'developer_name' => 'Dev Alice',
            'developer_email' => 'alice@acme.test',
            'company_id' => $company->public_id,
            'redirect_uris' => ['https://portal.acme.test/callback'],
        ]);

        $response->assertCreated();
        $refCode = $response->json('data.reference_code');

        $req = OAuthApplicationRequest::where('reference_code', $refCode)->first();
        $this->assertEquals($company->id, $req->company_id);

        $this->actingAs($admin)->postJson('/api/admin/oauth-requests/'.$req->public_id.'/approve')
            ->assertOk();

        $req->refresh();
        $application = Application::find($req->application_id);
        $this->assertEquals($company->id, $application->company_id);
    }

    public function test_admin_cannot_approve_already_approved_request(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $appRequest = OAuthApplicationRequest::create([
            'name' => 'Already Done',
            'developer_name' => 'Frank',
            'developer_email' => 'frank@example.com',
            'redirect_uris' => ['https://done.test/callback'],
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/oauth-requests/'.$appRequest->public_id.'/approve');
        $response->assertStatus(409);
    }

    public function test_admin_can_reject_pending_request(): void
    {
        $admin = User::factory()->systemAdmin()->create();

        $appRequest = OAuthApplicationRequest::create([
            'name' => 'Rejected App',
            'developer_name' => 'Grace',
            'developer_email' => 'grace@example.com',
            'redirect_uris' => ['https://rejected.test/callback'],
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/oauth-requests/'.$appRequest->public_id.'/reject', [
            'notes' => 'Incomplete redirect URI verification.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $appRequest->refresh();
        $this->assertEquals('rejected', $appRequest->status);
        $this->assertEquals('Incomplete redirect URI verification.', $appRequest->admin_notes);
        $this->assertEquals($admin->id, $appRequest->reviewed_by);

        // Public lookup shows rejected status
        $publicCheck = $this->getJson('/api/developers/oauth-requests/'.$appRequest->reference_code);
        $publicCheck->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.admin_notes', 'Incomplete redirect URI verification.');
    }
}

