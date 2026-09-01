<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_factories_create_valid_models(): void
    {
        $user = User::factory()->systemAdmin()->create();
        $profile = UserProfile::factory()->for($user)->create();
        $company = Company::factory()->create();
        $application = Application::factory()->create();

        $this->assertTrue($profile->user->is($user));
        $this->assertTrue($user->is_system_admin);
        $this->assertNotNull($company->public_id);
        $this->assertNotNull($application->public_id);
    }

    public function test_development_seeder_is_idempotent_and_creates_expected_access_data(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('user_profiles', 4);
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('applications', 3);
        $this->assertDatabaseCount('company_user', 6);
        $this->assertDatabaseCount('application_user', 8);

        $admin = User::query()->where('email', 'admin@central-iam.test')->firstOrFail();
        $hara = User::query()->where('email', 'hara@example.test')->firstOrFail();
        $sara = User::query()->where('email', 'sara@example.test')->firstOrFail();
        $daniel = User::query()->where('email', 'daniel@example.test')->firstOrFail();

        $this->assertTrue($admin->is_system_admin);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertEqualsCanonicalizing(['crm', 'erp', 'hrm'], $admin->applications()->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['crm', 'hrm'], $hara->applications()->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['crm', 'erp'], $sara->applications()->pluck('slug')->all());
        $this->assertSame(['erp'], $daniel->applications()->pluck('slug')->all());
        $this->assertSame(0, DB::table('application_user')->whereNull('granted_by')->count());
    }
}
