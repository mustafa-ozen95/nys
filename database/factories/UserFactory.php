<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PanelMode;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Panelin varsayılanı: günlük işte alanların çoğu kullanılmıyor.
            'panel_mode' => PanelMode::Simple,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            /*
             * VARSAYILAN ROL HİÇBİR ŞEY VERMEZ.
             *
             * Rol artık canlı okunuyor (bkz. RolePermissions): şablonu dolu
             * bir rolde açılan kullanıcı daha ilk satırda yetkili olurdu ve
             * "şu izni verince ne oluyor" sorusunu soran testler ölçemezdi.
             * Rol davranışını sınamak isteyen test ->role('teknik') der.
             */
            'role_id' => fn (): int => Role::query()->firstWhere('slug', 'yetkisiz')?->id
                ?? Role::factory()->create(['slug' => 'yetkisiz', 'label' => 'Yetkisiz', 'template' => []])->id,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->role('admin');
    }

    public function network(): static
    {
        return $this->role('network');
    }

    /**
     * Yerleşik rollerden birini verir. Rol kaydı göç ile oluşuyor; testte
     * yoksa aynı slug ile yaratılır.
     */
    public function role(string $slug): static
    {
        return $this->state(fn (): array => [
            'role_id' => Role::query()->firstWhere('slug', $slug)?->id
                ?? Role::factory()->create(['slug' => $slug, 'label' => $slug])->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Inactive]);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Banned]);
    }
}
