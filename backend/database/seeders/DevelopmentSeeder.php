<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $companies = collect([
                'example-corporation' => $this->upsertCompany('Example Corporation'),
                'demo-company' => $this->upsertCompany('Demo Company'),
            ]);

            $applications = collect([
                'hrm' => $this->upsertApplication('HRM', 'Human Resources Management'),
                'crm' => $this->upsertApplication('CRM', 'Customer Relationship Management'),
                'erp' => $this->upsertApplication('ERP', 'Enterprise Resource Planning'),
            ]);

            $admin = $this->upsertUser(
                'Central IAM Administrator',
                'admin@central-iam.test',
                'Central',
                'Administrator',
                true,
            );
            $hara = $this->upsertUser('Hara Dejene', 'hara@example.test', 'Hara', 'Dejene');
            $sara = $this->upsertUser('Sara Ahmed', 'sara@example.test', 'Sara', 'Ahmed');
            $daniel = $this->upsertUser('Daniel Bekele', 'daniel@example.test', 'Daniel', 'Bekele');

            $this->syncMemberships($admin, $companies->values()->all());
            $this->syncMemberships($hara, [$companies['example-corporation']]);
            $this->syncMemberships($sara, $companies->values()->all());
            $this->syncMemberships($daniel, [$companies['demo-company']]);

            $this->syncApplicationAccess($admin, $applications->values()->all(), $admin);
            $this->syncApplicationAccess($hara, [$applications['hrm'], $applications['crm']], $admin);
            $this->syncApplicationAccess($sara, [$applications['crm'], $applications['erp']], $admin);
            $this->syncApplicationAccess($daniel, [$applications['erp']], $admin);
        });
    }

    private function upsertCompany(string $name): Company
    {
        $company = Company::withTrashed()->firstOrNew(['name' => $name]);
        $company->status = MembershipStatus::Active;
        $company->deleted_at = null;
        $company->save();

        return $company;
    }

    private function upsertApplication(string $name, string $description): Application
    {
        $slug = strtolower($name);
        $application = Application::withTrashed()->firstOrNew(['slug' => $slug]);
        $application->fill([
            'name' => $name,
            'description' => $description,
            'status' => ApplicationStatus::Active,
        ]);
        $application->deleted_at = null;
        $application->save();

        return $application;
    }

    private function upsertUser(
        string $name,
        string $email,
        string $firstName,
        string $lastName,
        bool $isSystemAdmin = false,
    ): User {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password',
            'status' => AccountStatus::Active,
            'is_system_admin' => $isSystemAdmin,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ])->save();

        $user->profile()->updateOrCreate([], [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        return $user;
    }

    /** @param list<Company> $companies */
    private function syncMemberships(User $user, array $companies): void
    {
        $memberships = collect($companies)->mapWithKeys(fn (Company $company): array => [
            $company->id => ['status' => MembershipStatus::Active->value],
        ]);

        $user->companies()->syncWithoutDetaching($memberships->all());
    }

    /** @param list<Application> $applications */
    private function syncApplicationAccess(User $user, array $applications, User $grantedBy): void
    {
        $access = collect($applications)->mapWithKeys(fn (Application $application): array => [
            $application->id => [
                'status' => MembershipStatus::Active->value,
                'granted_by' => $grantedBy->id,
            ],
        ]);

        $user->applications()->syncWithoutDetaching($access->all());
    }
}
