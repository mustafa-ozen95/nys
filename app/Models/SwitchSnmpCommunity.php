<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchSnmpCommunityFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihazdaki bir SNMP community'si.
 *
 * Ad bir SIRDIR: okuma parolası gibi çalışır ve onu bilen cihazın bütün
 * SNMP verisini çeker. Bu yüzden şifreli saklanır, $hidden'dır, ekranda
 * basılmaz ve cihaz farkı kayda geçerken maskelenir.
 *
 * Şifreli sütunda benzersizlik kurulamaz (aynı değer her seferinde farklı
 * şifrelenir); bunun için adın özeti ayrı bir sütunda tutulur ve kısıt
 * onun üzerindedir.
 */
class SwitchSnmpCommunity extends Model
{
    /** @use HasFactory<SwitchSnmpCommunityFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'authorization',
        'clients',
    ];

    /** @var list<string> */
    protected $hidden = [
        'name',
        'name_digest',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'clients' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Özet adla birlikte yazılır; iki yerden güncellenirse ayrışırdı.
        static::saving(function (self $community): void {
            $community->name_digest = self::digest((string) $community->name);
        });
    }

    /**
     * Ad ekranda hiç gösterilmez; kullanıcı hangi kaydı düzenlediğini
     * son iki karakterden anlar.
     *
     * @return Attribute<string, never>
     */
    protected function maskedName(): Attribute
    {
        return Attribute::get(function (): string {
            $name = (string) $this->name;

            return $name === '' ? '—' : str_repeat('•', 6).mb_substr($name, -2);
        });
    }

    public static function digest(string $name): string
    {
        return hash('sha256', $name);
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }
}
