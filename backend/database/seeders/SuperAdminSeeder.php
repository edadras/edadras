<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['tenant_id' => null, 'email' => env('SUPER_ADMIN_EMAIL', 'admin@gymflow.ai')],
            [
                'name' => 'Platform Admin',
                'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
                'is_super_admin' => true,
                'locale' => 'en',
                'status' => 'active',
            ],
        );
    }
}
