<?php
// ponytail: Standard simple database seeder calling modular sub-seeders
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed default admin user
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@nusaevo.com',
            'password' => bcrypt('password'),
        ]);

        $this->call([
            InventorySeeder::class,
        ]);
    }
}
