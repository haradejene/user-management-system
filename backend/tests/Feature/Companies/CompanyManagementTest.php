<?php

namespace Tests\Feature\Companies;

use App\Enums\MembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_and_filter_companies(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        Company::factory()->create(['name' => 'Active Match']);
        Company::factory()->create([
            'name' => 'Inactive Match',
            'status' => MembershipStatus::Inactive,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/admin/companies?search=match&status=active&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Match')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_administrator_can_view_create_and_update_a_company_by_public_id(): void
    {
        $administrator = User::factory()->systemAdmin()->create();

        $created = $this->actingAs($administrator)
            ->postJson('/api/admin/companies', ['name' => 'Example Company'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Example Company')
            ->assertJsonPath('data.status', MembershipStatus::Active->value)
            ->json('data.id');

        $company = Company::query()->where('public_id', $created)->firstOrFail();

        $this->actingAs($administrator)
            ->getJson("/api/admin/companies/{$company->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->public_id);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/companies/{$company->public_id}", ['name' => 'Renamed Company'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Company');

        $this->assertSame('Renamed Company', $company->fresh()->name);
        $this->actingAs($administrator)
            ->getJson("/api/admin/companies/{$company->id}")
            ->assertNotFound();
    }

    public function test_company_requests_are_validated(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create();

        $this->actingAs($administrator)
            ->postJson('/api/admin/companies', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($administrator)
            ->patchJson("/api/admin/companies/{$company->public_id}", ['name' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($administrator)
            ->getJson('/api/admin/companies?status=unknown&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'per_page']);
    }

    public function test_administrator_can_deactivate_and_reactivate_a_company_without_losing_members(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->users()->attach($member, ['status' => MembershipStatus::Active->value]);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/companies/{$company->public_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', MembershipStatus::Inactive->value);

        $this->assertTrue($company->users()->whereKey($member->id)->exists());

        $this->actingAs($administrator)
            ->patchJson("/api/admin/companies/{$company->public_id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', MembershipStatus::Active->value);
    }

    public function test_administrator_can_add_list_and_remove_company_members(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create();
        $member = User::factory()->create();

        $this->actingAs($administrator)
            ->postJson("/api/admin/companies/{$company->public_id}/members", [
                'user_id' => $member->public_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $member->public_id)
            ->assertJsonPath('data.membership_status', MembershipStatus::Active->value)
            ->assertJsonMissingPath('data.password');

        $this->actingAs($administrator)
            ->getJson("/api/admin/companies/{$company->public_id}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $member->public_id);

        $this->actingAs($administrator)
            ->deleteJson("/api/admin/companies/{$company->public_id}/members/{$member->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('company_user', [
            'company_id' => $company->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_administrator_can_list_companies_belonging_to_a_user(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $company->users()->attach($user, ['status' => MembershipStatus::Inactive->value]);

        $this->actingAs($administrator)
            ->getJson("/api/admin/users/{$user->public_id}/companies")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $company->public_id)
            ->assertJsonPath('data.0.membership_status', MembershipStatus::Inactive->value);
    }

    public function test_duplicate_and_missing_memberships_are_rejected(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $company->users()->attach($member, ['status' => MembershipStatus::Active->value]);

        $this->actingAs($administrator)
            ->postJson("/api/admin/companies/{$company->public_id}/members", [
                'user_id' => $member->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('company_user', 1);

        $nonMember = User::factory()->create();
        $this->actingAs($administrator)
            ->deleteJson("/api/admin/companies/{$company->public_id}/members/{$nonMember->public_id}")
            ->assertNotFound();

        $this->assertTrue(Schema::hasColumns('company_user', ['company_id', 'user_id', 'status']));
        $this->assertFalse(Schema::hasColumn('company_user', 'role'));
    }

    public function test_members_cannot_be_added_to_an_inactive_company(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create(['status' => MembershipStatus::Inactive]);
        $member = User::factory()->create();

        $this->actingAs($administrator)
            ->postJson("/api/admin/companies/{$company->public_id}/members", [
                'user_id' => $member->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company');
    }

    public function test_membership_input_is_validated(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $company = Company::factory()->create();

        $this->actingAs($administrator)
            ->postJson("/api/admin/companies/{$company->public_id}/members", [
                'user_id' => 'not-a-uuid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_standard_and_unauthenticated_users_cannot_manage_companies_or_memberships(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();

        $this->getJson('/api/admin/companies')->assertUnauthorized();

        $this->actingAs($user)
            ->getJson('/api/admin/companies')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/admin/companies', ['name' => 'Forbidden'])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/admin/companies/{$company->public_id}/members", [
                'user_id' => $user->public_id,
            ])
            ->assertForbidden();
    }
}
