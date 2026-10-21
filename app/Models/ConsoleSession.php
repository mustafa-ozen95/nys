<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionProtocol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Açılmış bir uzak oturumun denetim izi.
 *
 * Oturumun canlı hâli önbellektedir; buradaki satır "ne zaman açıldı, en son
 * ne zaman ses verdi, nasıl kapandı" sorularını yanıtlar. Kapanışı istemci
 * haber veremezse (tarayıcı çöktü, makine uyudu, ağ koptu) zamanlayıcı bu
 * satırdan anlar.
 */
class ConsoleSession extends Model
{
    protected $table = 'konsol_oturumlari';

    protected $fillable = [
        'token',
        'user_id',
        'subject_type',
        'subject_id',
        'label',
        'protocol',
        'port',
        'opened_at',
        'last_seen_at',
        'closed_at',
        'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => ConnectionProtocol::class,
            'port' => 'integer',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Kapanmamış ama SESİ KESİLMİŞ oturumlar.
     *
     * Sınır, canlı tutma aralığının birkaç katıdır: ağdaki bir saniyelik
     * kesinti oturumu düşmüş saymamalı.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSessizKalan(Builder $query, int $saniye): void
    {
        $query->whereNull('closed_at')->where('last_seen_at', '<', now()->subSeconds($saniye));
    }

    /** @param  Builder<$this>  $query */
    public function scopeAcik(Builder $query): void
    {
        $query->whereNull('closed_at');
    }
}
