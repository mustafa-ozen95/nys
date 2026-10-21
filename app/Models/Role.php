<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeVariant;
use App\Models\Concerns\HasUuid;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel rolü.
 *
 * Rol tek başına yetki DEĞİLDİR; hazır bir yetki şablonudur. Şablon
 * uygulandıktan sonra kişiye özel istisnalar yetki ekranından verilir —
 * "şu switchin portlarına dokunsun ama VLAN'larına dokunmasın" gibi.
 *
 * Yönetici rolü (`is_admin`) bunun dışındadır: bütün kontrolleri geçer.
 * En az bir yönetici rolü KALMAK ZORUNDA; yoksa paneli kimse yönetemez.
 *
 * Yerleşik roller (`is_system`) silinemez ve slug'ı değişmez: seeder, test
 * ve şablon dosyaları onlara adıyla atıfta bulunuyor.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'slug',
        'label',
        'description',
        'badge',
        'is_admin',
        'is_default',
        'template',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'badge' => BadgeVariant::class,
            'is_admin' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'template' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Rolün hazır yetki şablonu: kaynak => aksiyonlar.
     *
     * @return array<string, list<string>>
     */
    public function abilities(): array
    {
        return $this->template ?? [];
    }

    /**
     * Rol silinebilir mi? Yerleşik roller ve kullanıcısı olan roller
     * silinemez — kullanıcı rolsüz kalırsa hiçbir kontrolden geçemez.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_system && $this->users()->count() === 0;
    }

    /**
     * Yeni kullanıcının varsayılan rolü.
     *
     * Yönetici olmayan biri kullanıcı açtığında rol seçemez; bu rol verilir.
     * Yönetici rolü varsayılan olamaz — "kullanıcı ekle" yetkisi, yönetici
     * üretme yetkisi değildir.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_admin', false)->first()
            ?? static::query()->where('is_admin', false)->ordered()->first();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('label');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
