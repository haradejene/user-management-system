<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_iam_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'public_id', 'name', 'email', 'email_verified_at', 'password',
            'status', 'is_system_admin', 'remember_token', 'created_at',
            'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('user_profiles', [
            'id', 'user_id', 'first_name', 'last_name', 'phone', 'profile_photo',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasTable('companies'));
        $this->assertTrue(Schema::hasTable('company_user'));
        $this->assertTrue(Schema::hasTable('applications'));
        $this->assertTrue(Schema::hasTable('application_user'));
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
    }

    public function test_eloquent_relationships_and_domain_casts_work(): void
    {
        $user = User::query()->create([
            'name' => 'Hara Dejene',
            'email' => 'hara@example.com',
            'password' => 'secret-password',
        ]);
        $user->profile()->create([
            'first_name' => 'Hara',
            'last_name' => 'Dejene',
        ]);

        $company = Company::query()->create([
            'name' => 'Example Company',
            'status' => MembershipStatus::Active,
        ]);
        $application = Application::query()->create([
            'name' => 'HRM',
            'slug' => 'hrm',
            'status' => ApplicationStatus::Active,
        ]);

        $user->companies()->attach($company, ['status' => MembershipStatus::Active->value]);
        $user->applications()->attach($application, [
            'status' => MembershipStatus::Active->value,
            'granted_by' => $user->id,
        ]);

        $this->assertNotNull($user->public_id);
        $this->assertSame(AccountStatus::Active, $user->fresh()->status);
        $this->assertSame('Hara', $user->profile->first_name);
        $this->assertTrue($user->companies->contains($company));
        $this->assertTrue($user->applications->contains($application));
        $this->assertSame(MembershipStatus::Active, $company->fresh()->status);
        $this->assertSame(ApplicationStatus::Active, $application->fresh()->status);
        $this->assertSame($user->id, $user->applications->first()->pivot->granted_by);
    }

    public function test_hard_deleting_a_user_cascades_domain_relationship_rows(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['first_name' => 'Hara']);
        $company = Company::query()->create(['name' => 'Example Company']);
        $application = Application::query()->create(['name' => 'CRM', 'slug' => 'crm']);

        $user->companies()->attach($company);
        $user->applications()->attach($application);
        $user->forceDelete();

        $this->assertDatabaseMissing('user_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('application_user', ['user_id' => $user->id]);
    }
}
