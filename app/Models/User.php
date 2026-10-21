<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PanelMode;
use App\Enums\UserStatus;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\OturumlariKapatir;
use App\Services\Authorization\UserPermissionResolver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Yetkilendirme mantığı bilinçli olarak bu modelde değildir;
 * App\Services\Authorization\UserPermissionResolver sınıfında toplanır.
 */
class User extends Authenticatable
{
    use AuthorizesAccess;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuid;
    use Notifiable;
    use OturumlariKapatir;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'status',
        'panel_mode',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Rol her istekte okunur: isAdmin() bütün yetki kontrollerinin ilk
     * sorusudur. Eager yüklenmezse kullanıcı listesi satır başına bir sorgu
     * daha atardı.
     *
     * @var list<string>
     */
    protected $with = ['role'];

    protected function casts(): array
    {
        return [
            'notifications_seen_at' => 'datetime',
            'panel_mode' => PanelMode::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Kullanıcının rolü.
     *
     * Rol bir KAYITTIR, enum değil: panelden yeni rol açılabilsin ve hazır
     * yetkileri panelden düzenlenebilsin diye.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<UserPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Bütün yetki kontrollerini geçer mi?
     *
     * Tek karar noktası burasıdır (bkz. AppServiceProvider'daki Gate::before).
     * Rolü okunamayan kullanıcı yönetici SAYILMAZ: eksik veri yetki
     * üretmemeli.
     */
    public function isAdmin(): bool
    {
        return $this->role?->is_admin === true;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Gelişmiş moda geçebilir mi? Yönetici her zaman; diğerleri "Gelişmiş
     * mod" rol yetkisiyle.
     */
    public function gelismisModaGecebilir(): bool
    {
        return $this->isAdmin()
            || app(UserPermissionResolver::class)->allows($this, 'gorunum_modu', 'view');
    }

    /**
     * GEÇERLİ mod. Kayıtlı tercih "gelişmiş" olsa bile yetkisi yoksa basit
     * sayılır: yetki sonradan alınan kişi gelişmişte kalmamalı.
     */
    public function etkinPanelModu(): PanelMode
    {
        return $this->panel_mode === PanelMode::Advanced && $this->gelismisModaGecebilir()
            ? PanelMode::Advanced
            : PanelMode::Simple;
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('email', 'like', "%{$value}%")
        ));
    }
}
