<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir sunucunun parola değiştirme turu.
 *
 * Switch turlarıyla aynı kalıptadır (SwitchPasswordRotation) ve aynı aşama/
 * durum sözlüğünü kullanır; ekranlar ikisini aynı dille anlatabilsin diye.
 *
 * Kurtarma parolası ŞİFRELİ saklanır ve `$hidden` içindedir: makinede tam
 * yetkili bir hesabın parolasıdır ve panelin makineye geri dönebileceği tek
 * kapıdır. Hiçbir ekranda ve JSON yanıtında basılmaz.
 *
 * Tur bittiğinde kurtarma parolası SİLİNİR (bkz. kurtarmayiTemizle):
 * makinede karşılığı kalmamış bir sırrı tutmanın hiçbir faydası yok.
 */
class ServerPasswordRotation extends Model
{
    use HasUuid;

    protected $fillable = [
        'server_id',
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
     * Makinede kurtarma hesabı bırakmış turlar.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeStranded(Builder $query): void
    {
        $query->where('recovery_active', true);
    }

    /** Kurtarma hesabı makineden silindi: paneldeki sırrı da sil. */
    public function recoveryClean(): void
    {
        $this->forceFill([
            'recovery_active' => false,
            'recovery_password' => null,
            'new_password' => null,
        ])->save();
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
