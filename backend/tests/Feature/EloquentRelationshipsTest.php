<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_have_a_profile(): void
    {
        $user = User::factory()->create();

        $profile = $user->profile()->create([
            'first_name' => 'Hara',
            'last_name' => 'Dejene',
        ]);

        $this->assertTrue($profile->user->is($user));
        $this->assertTrue($user->fresh()->profile->is($profile));
    }

    public function test_a_user_can_belong_to_multiple_companies(): void
    {
        $user = User::factory()->create();
        $companies = collect([
            Company::query()->create(['name' => 'Company One']),
            Company::query()->create(['name' => 'Company Two']),
        ]);

        $user->companies()->attach($companies->pluck('id'));

        $this->assertCount(2, $user->fresh()->companies);
        $this->assertEqualsCanonicalizing(
            $companies->pluck('id')->all(),
            $user->companies()->pluck('companies.id')->all(),
        );
    }

    public function test_a_company_can_have_multiple_users(): void
    {
        $company = Company::query()->create(['name' => 'Example Company']);
        $users = User::factory()->count(2)->create();

        $company->users()->attach($users->pluck('id'));

        $this->assertCount(2, $company->fresh()->users);
        $this->assertEqualsCanonicalizing(
            $users->pluck('id')->all(),
            $company->users()->pluck('users.id')->all(),
        );
    }

    public function test_a_user_can_have_access_to_multiple_applications(): void
    {
        $user = User::factory()->create();
        $applications = collect([
            Application::query()->create(['name' => 'HRM', 'slug' => 'hrm']),
            Application::query()->create(['name' => 'CRM', 'slug' => 'crm']),
        ]);

        $user->applications()->attach($applications->pluck('id'));

        $this->assertCount(2, $user->fresh()->applications);
        $this->assertEqualsCanonicalizing(
            $applications->pluck('id')->all(),
            $user->applications()->pluck('applications.id')->all(),
        );
    }

    public function test_an_application_can_have_multiple_users(): void
    {
        $application = Application::query()->create(['name' => 'ERP', 'slug' => 'erp']);
        $users = User::factory()->count(2)->create();

        $application->users()->attach($users->pluck('id'));

        $this->assertCount(2, $application->fresh()->users);
        $this->assertEqualsCanonicalizing(
            $users->pluck('id')->all(),
            $application->users()->pluck('users.id')->all(),
        );
    }
}
