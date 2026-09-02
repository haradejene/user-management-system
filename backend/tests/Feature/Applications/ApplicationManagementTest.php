<?php

namespace Tests\Feature\Applications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_and_filter_applications(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        Application::factory()->create(['name' => 'HRM', 'slug' => 'hrm']);
        Application::factory()->create([
            'name' => 'CRM Legacy',
            'slug' => 'crm-legacy',
            'status' => ApplicationStatus::Inactive,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/admin/applications?search=hrm&status=active&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'hrm')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_administrator_can_create_and_view_an_application_by_public_id(): void
    {
        $administrator = User::factory()->systemAdmin()->create();

        $publicId = $this->actingAs($administrator)
            ->postJson('/api/admin/applications', [
                'name' => 'Human Resources Management',
                'slug' => ' HRM Portal ',
                'description' => 'The HRM service.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hrm-portal')
            ->assertJsonPath('data.status', ApplicationStatus::Active->value)
            ->assertJsonMissingPath('data.client_secret')
            ->assertJsonMissingPath('data.oauth_client_id')
            ->json('data.id');

        $application = Application::query()->where('public_id', $publicId)->firstOrFail();

        $this->actingAs($administrator)
            ->getJson("/api/admin/applications/{$application->public_id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'The HRM service.');

        $this->actingAs($administrator)
            ->getJson("/api/admin/applications/{$application->id}")
            ->assertNotFound();
    }

    public function test_administrator_can_update_an_application(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create(['slug' => 'crm']);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/applications/{$application->public_id}", [
                'name' => 'Customer Relationship Management',
                'slug' => 'CRM Platform',
                'description' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Customer Relationship Management')
            ->assertJsonPath('data.slug', 'crm-platform')
            ->assertJsonPath('data.description', null);

        $this->assertSame('crm-platform', $application->fresh()->slug);
    }

    public function test_application_input_and_unique_slug_are_validated(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $existing = Application::factory()->create(['slug' => 'erp']);

        $this->actingAs($administrator)
            ->postJson('/api/admin/applications', [
                'name' => '',
                'slug' => '---',
                'description' => str_repeat('x', 5001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'description']);

        $other = Application::factory()->create();
        $this->actingAs($administrator)
            ->patchJson("/api/admin/applications/{$other->public_id}", [
                'slug' => strtoupper($existing->slug),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->actingAs($administrator)
            ->getJson('/api/admin/applications?status=unknown&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'per_page']);
    }

    public function test_administrator_can_deactivate_and_activate_an_application(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $application = Application::factory()->create();

        $this->actingAs($administrator)
            ->patchJson("/api/admin/applications/{$application->public_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::Inactive->value);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/applications/{$application->public_id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', ApplicationStatus::Active->value);
    }

    public function test_standard_and_unauthenticated_users_cannot_manage_applications(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();

        $this->getJson('/api/admin/applications')->assertUnauthorized();

        $this->actingAs($user)
            ->getJson('/api/admin/applications')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/admin/applications', [
                'name' => 'Forbidden',
                'slug' => 'forbidden',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/admin/applications/{$application->public_id}/deactivate")
            ->assertForbidden();
    }

    public function test_application_registry_does_not_create_passport_client_fields_or_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('applications', [
            'public_id',
            'name',
            'slug',
            'description',
            'status',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('applications', 'secret'));
        $this->assertFalse(Schema::hasColumn('applications', 'redirect_uris'));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
    }
}
