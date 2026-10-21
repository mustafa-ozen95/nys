<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeVariant;
use App\Models\Concerns\HasUuid;
use Database\Factories\DeviceOperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihazla yapılan bir işlemin yapısal kaydı.
 *
 * Başarısızlıklar ve uyarılar buraya yazılır; başarılı işlemler zaten
 * etkinlik kaydında (activity_logs) duruyor ve burada tekrarlanmaz —
 * config/activity.php ile açılabilir.
 */
class DeviceOperation extends Model
{
    /** @use HasFactory<DeviceOperationFactory> */
    use HasFactory;

    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'switch_id', 'target', 'user_id', 'operation', 'stage', 'status',
        'kind', 'summary', 'device_message', 'errors', 'warnings',
        'document', 'diff', 'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'warnings' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Kullanıcının GÖREBİLECEĞİ cihaz işlemleri.
     *
     * İşlem satırı bir cihaza aittir; yetki de o cihazın yetkisidir.
     * Süzülmediğinde `logs.view` yetkisi olan biri göremediği switchlerin
     * yazma geçmişini de okuyordu.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereIn('switch_id', NetworkSwitch::query()->visibleTo($user)->select('id'));
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

    /**
     * @return Attribute<BadgeVariant, never>
     */
    protected function statusBadge(): Attribute
    {
        return Attribute::get(fn (): BadgeVariant => match ($this->status) {
            'failed' => BadgeVariant::Red,
            'warning' => BadgeVariant::Orange,
            default => BadgeVariant::Green,
        });
    }

    /**
     * @return Attribute<string, never>
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->status) {
            'failed' => 'Başarısız',
            'warning' => 'Uyarı',
            default => 'Başarılı',
        });
    }

    /**
     * @return Attribute<string, never>
     */
    protected function operationLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->operation) {
            'write' => 'Cihaza yazma',
            'rollback' => 'Geri alma',
            'push' => 'Config yükleme',
            'sync' => 'Eşitleme',
            'measure' => 'Ölçüm',
            'probe' => 'Şema sondası',
            'rotate' => 'Parola turu',
            'read' => 'Okuma',
            default => $this->operation,
        });
    }

    /**
     * @return Attribute<string, never>
     */
    protected function stageLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->stage) {
            'load' => 'yükleme',
            'check' => 'commit check',
            'commit' => 'commit',
            'confirm' => 'onay',
            'read' => 'okuma',
            'connect' => 'bağlantı',
            'lock' => 'kilit',
            default => (string) $this->stage,
        });
    }

    /**
     * Kullanıcının aradığı şey genelde cihazın kendi cümlesidir.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(fn (Builder $q) => $q
            ->where('summary', 'like', $like)
            ->orWhere('device_message', 'like', $like)
            ->orWhere('target', 'like', $like));
    }

    /**
     * @param  Builder<$this>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query->search($filters['q'] ?? null)
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['operation'] ?? null, fn (Builder $q, string $v) => $q->where('operation', $v))
            ->when($filters['switch'] ?? null, fn (Builder $q, string $v) => $q->whereRelation('switch', 'uuid', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v));
    }
}
