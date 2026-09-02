<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_central_iam_administrators_can_manage_iam_resources(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $application = Application::factory()->create();

        $this->assertTrue(Gate::forUser($administrator)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('update', $user));
        $this->assertTrue(Gate::forUser($administrator)->allows('delete', $company));
        $this->assertTrue(Gate::forUser($administrator)->allows('create', Application::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('manageAccess', Application::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('forceDelete', $application));
    }

    public function test_standard_users_cannot_manage_iam_resources(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Company::class));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Application::class));
        $this->assertFalse(Gate::forUser($user)->allows('manageAccess', Application::class));
    }

    public function test_standard_users_cannot_access_user_administration_records(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('view', $user));
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherUser));
    }

    public function test_inactive_central_iam_administrators_are_denied(): void
    {
        $administrator = User::factory()->systemAdmin()->create([
            'status' => AccountStatus::Suspended,
        ]);

        $this->assertFalse(Gate::forUser($administrator)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($administrator)->allows('create', Company::class));
        $this->assertFalse(Gate::forUser($administrator)->allows('viewAny', Application::class));
    }

    public function test_central_iam_admin_middleware_rejects_standard_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/authorization-probe')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You are not authorized to access the IAM administration interface.'
            );
    }

    public function test_central_iam_admin_middleware_allows_administrators(): void
    {
        $this->actingAs(User::factory()->systemAdmin()->create())
            ->getJson('/authorization-probe')
            ->assertOk()
            ->assertJson(['authorized' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/authorization-probe', fn () => ['authorized' => true])
            ->middleware(['auth:sanctum', 'active', 'central-iam-admin']);
    }
}
