<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ağ ekibi kullanıcıları.
 *
 * Hesaplar ve parolalar yerel envanter dosyasından gelir (local/inventory.php,
 * .gitignore'da). Yetkileri NetworkTeamPermissionSeeder verir.
 */
class NetworkTeamSeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, email: string, password: string}>
     */
    public static function members(): array
    {
        /** @var array<int, array{name: string, email: string, password: string}> */
        return LocalInventory::take('ekip');
    }

    public function run(): void
    {
        foreach (self::members() as $member) {
            User::updateOrCreate(
                ['email' => $member['email']],
                [
                    'name' => $member['name'],
                    'password' => Hash::make($member['password']),
                    'role_id' => Role::query()->where('slug', 'network')->value('id'),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
