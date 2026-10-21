<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityChannel;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'channel',
        'action',
        'description',
        'reason',
        'ip_address',
    ];

    /*
     * hash, previous_hash ve chain_version BİLEREK fillable DEĞİLDİR: onları yalnızca
     * ActivityChain yazar. Toplu atamaya açılsalardı bir controller kendi
     * özetini uydurabilir ve zincirin varlık sebebi ortadan kalkardı.
     */

    protected function casts(): array
    {
        return [
            'channel' => ActivityChannel::class,
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
     * Kullanıcının GÖREBİLECEĞİ kayıt satırları.
     *
     * Bu scope, trait'ten gelen genel `visibleTo`yu BİLEREK gölgeler: kayıt
     * satırının kendisine yetki verilmiyor, satırın ANLATTIĞI şeye veriliyor.
     *
     * Kural: kendi hareketlerin + görebildiğin cihazların ve sunucuların
     * olayları. Geri kalanı — başkalarının sayfa gezintileri, kullanıcı ve rol
     * işlemleri — yalnızca yöneticide.
     *
     * NEDEN: kayıt yetkisi (`logs.view`) yönetici olmayan birine de
     * verilebiliyor ve süzme olmadığında o kişi GÖREMEDİĞİ switchlerin
     * geçmişini de okuyordu. Üstelik sayfa gezintisi satırları adreslerinde
     * cihaz uuid'si taşıyor: "yok" sanılan envanter oradan sızıyordu. Aynı
     * projenin bildirim ekranı zaten tersini şart koşuyor.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, string $action = 'view'): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->where(function (Builder $dis) use ($user): void {
            $dis->where('user_id', $user->getKey())
                ->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', (new NetworkSwitch)->getMorphClass())
                    ->whereIn('subject_id', NetworkSwitch::query()->visibleTo($user)->select('id')))
                ->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', (new Server)->getMorphClass())
                    ->whereIn('subject_id', Server::query()->visibleTo($user)->select('id')));
        });
    }

    /**
     * Ekranın filtrelerini tek yerde uygular.
     *
     * @param  Builder<$this>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query
            ->search($filters['q'] ?? null)
            ->when($filters['channel'] ?? null, fn (Builder $q, string $channel) => $q->where('channel', $channel))
            ->when($filters['user'] ?? null, fn (Builder $q, string $uuid) => $q->whereHas(
                'user',
                fn (Builder $inner) => $inner->where('uuid', $uuid),
            ))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to));
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('action', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%")
        ));
    }
}
