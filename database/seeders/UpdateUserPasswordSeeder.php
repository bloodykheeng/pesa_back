<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UpdateUserPasswordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // php artisan db:seed --class=UpdateUserPasswordSeeder

        // Find the user by email
        $user = User::where('email', 'johndoe@gmail.com')->first();

        if ($user) {
            // Update the user's password
            $user->password = Hash::make('#@1Password');
            $user->save();

            $this->command->info('Password updated for user: johndoe@gmail.com');
        } else {
            $this->command->error('User not found.');
        }
    }
}