<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchPasswordRotationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir cihazın parola değiştirme turu.
 *
 * Kurtarma parolası ŞİFRELİ saklanır ve `$hidden` içindedir: cihazda tam
 * yetkili bir hesabın parolasıdır ve panelin cihaza geri dönebileceği tek
 * kapıdır. Hiçbir ekranda ve JSON yanıtında basılmaz; yalnızca panelin
 * kendisi kullanır.
 *
 * Tur bittiğinde kurtarma parolası SİLİNİR (bkz. clearRecovery): cihazda
 * karşılığı kalmamış bir sırrı tutmanın hiçbir faydası yok.
 */
class SwitchPasswordRotation extends Model
{
    /** @use HasFactory<SwitchPasswordRotationFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'user_id',
        'status',
        'stage',
        'recovery_username',
        'recovery_password',
        'new_password',
        'recovery_active',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'recovery_password',
        'new_password',
    ];

    protected function casts(): array
    {
        return [
            'status' => PasswordRotationStatus::class,
            'stage' => PasswordRotationStage::class,
            'recovery_password' => 'encrypted',
            'new_password' => 'encrypted',
            'recovery_active' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Cihazda kurtarma hesabı bırakmış turlar.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStranded(Builder $query): void
    {
        $query->where('recovery_active', true);
    }

    /**
     * Kurtarma hesabı cihazdan silindi: paneldeki sırrı da sil.
     */
    public function clearRecovery(): void
    {
        $this->forceFill([
            'recovery_active' => false,
            'recovery_password' => null,
            'new_password' => null,
        ])->save();
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
