<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'status' => MembershipStatus::Active,
        ];
    }
}
