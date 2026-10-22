<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Panelin ilk yöneticisi. Parola env üzerinden verilir; koda gömülmez.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Bilgi verilmediyse hesap AÇILMAZ. Varsayılan bir adres ve parolayla
        // hesap açmak, imajı indiren herkeste aynı girişin bulunması demek
        // olurdu. Yönetici `admin:add` ile eklenir.
        if (blank(config('app.admin_email')) || blank(config('app.admin_password'))) {
            return;
        }

        User::updateOrCreate(
            ['email' => config('app.admin_email')],
            [
                'name' => config('app.admin_name'),
                'password' => Hash::make(config('app.admin_password')),
                'role_id' => Role::query()->where('is_admin', true)->value('id'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
