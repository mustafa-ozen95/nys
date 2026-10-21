<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchPasswordRotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPasswordRotation>
 */
class SwitchPasswordRotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'switch_id' => NetworkSwitch::factory(),
            'status' => PasswordRotationStatus::Succeeded,
            'stage' => PasswordRotationStage::RecoveryRemoved,
            'recovery_active' => false,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    /**
     * Cihazda kurtarma hesabı bırakmış tur: tam yetkili açık bir hesap
     * demektir ve ekranda uyarı olarak durur.
     */
    public function stranded(): static
    {
        return $this->state(fn (): array => [
            'status' => PasswordRotationStatus::Failed,
            'stage' => PasswordRotationStage::PasswordChanged,
            'recovery_username' => config('credentials.rotation.recovery_username'),
            'recovery_password' => 'kurtarmaParolasi9',
            'recovery_active' => true,
            'error' => 'yeni parola denenemedi',
        ]);
    }
}
