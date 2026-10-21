<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SwitchWriteStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Kuyruğa alınmış bir cihaz yazmasının durumu.
 *
 * Yazma HTTP isteğini bekletmez: ELS switchte bir dakikaya yaklaşabilir ve
 * sayfayı kilitlerdi. Kullanıcı sonucu bu kayıttan öğrenir.
 *
 * YAZMA ARGÜMANLARI BURADA DURMAZ — parola, community ve anahtar taşıyabilir
 * ve bu kayıt dışa aktarılabilir. Argümanları yalnızca kuyruktaki iş taşır.
 */
class SwitchWriteJob extends Model
{
    use HasUuid;

    protected $fillable = [
        'switch_id', 'user_id', 'summary', 'status', 'message', 'diff',
        'reason', 'ip_address', 'subject_type', 'subject_id',
        'started_at', 'finished_at',
    ];

    /**
     * Henüz bitmemiş işler.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [SwitchWriteStatus::Queued, SwitchWriteStatus::Running]);
    }

    /**
     * Belirli bir kaynağa ait işler.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SwitchWriteStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Yazmanın hedefi: port, VLAN, filtre... Switch geneli işlerde null.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<NetworkSwitch, $this>
     */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
