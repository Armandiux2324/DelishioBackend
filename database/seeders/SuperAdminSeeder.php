<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = Validator::make(config('superadmin'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
            'locale' => ['required', 'in:en,es'],
        ])->validate();

        $existing = User::withTrashed()->where('email', $data['email'])->first();
        if ($existing) {
            if ($existing->trashed() || $existing->user_type !== 'super_admin') {
                throw new RuntimeException('SUPER_ADMIN_EMAIL already belongs to another or deleted account.');
            }

            return;
        }

        User::create($data + ['user_type' => 'super_admin'])
            ->forceFill(['email_verified_at' => now(), 'two_factor_enabled' => true])->save();
    }
}
