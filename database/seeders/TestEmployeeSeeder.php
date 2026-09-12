<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * STEP 04 dev/testing convenience ONLY - creates one employee account so
 * the employee login flow can be exercised manually. There is no public
 * employee registration endpoint by design (real staff creation is a
 * later step's functionality).
 *
 * NOT wired into DatabaseSeeder::run() - never runs automatically. Run
 * explicitly:
 *   php artisan db:seed --class=TestEmployeeSeeder
 *
 * Do not run this against a production database. The password below is
 * an obvious placeholder, not a real credential - change or remove this
 * seeder before any real deployment.
 */
class TestEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        Employee::firstOrCreate(
            ['email' => 'admin@test.local'],
            [
                'name' => 'Test Admin',
                'password' => 'ChangeMe123!',
                'role' => 'admin',
                'status' => 'active',
            ]
        );
    }
}
