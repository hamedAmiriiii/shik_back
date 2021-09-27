<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        //\App\Models\User::factory(10)->create();
        Role::factory()
            ->count(sizeof(User::USER_TYPE))
            ->sequence(fn($sequence) => ['name' => User::USER_TYPE[$sequence->index + 1]])
            ->create();
    }
}
