<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Netconf\ResidualXml;
use Database\Factories\SwitchStormControlFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir arayüzün fırtına denetimi ayarı.
 *
 * Yayın fırtınası bir anahtarı dakikalar içinde kullanılamaz hâle getirir;
 * eşik aşıldığında cihaz fazla trafiği düşürür (ya da portu kapatır).
 */
class SwitchStormControl extends Model
{
    /** @use HasFactory<SwitchStormControlFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id', 'interface_name', 'level', 'bandwidth',
        'no_broadcast', 'no_unknown_unicast', 'no_multicast',
        'no_registered_multicast', 'no_unregistered_multicast',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'bandwidth' => 'integer',
            'no_broadcast' => 'boolean',
            'no_unknown_unicast' => 'boolean',
            'no_multicast' => 'boolean',
            'no_registered_multicast' => 'boolean',
            'no_unregistered_multicast' => 'boolean',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Eşiğin okunur hâli: yüzde mi kbps mi.
     *
     * @return Attribute<string, never>
     */
    protected function thresholdLabel(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->level !== null => "%{$this->level}",
            $this->bandwidth !== null => number_format((int) $this->bandwidth, 0, ',', '.').' kbps',
            default => 'cihaz varsayılanı',
        });
    }

    /**
     * Denetim DIŞINDA bırakılan trafik türleri.
     *
     * @return Attribute<list<string>, never>
     */
    protected function exemptions(): Attribute
    {
        return Attribute::get(fn (): array => array_keys(array_filter([
            'yayın' => $this->no_broadcast,
            'bilinmeyen tekil' => $this->no_unknown_unicast,
            'çoklu gönderim' => $this->no_multicast,
            'kayıtlı çoklu gönderim' => $this->no_registered_multicast,
            'kayıtsız çoklu gönderim' => $this->no_unregistered_multicast,
        ])));
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => ResidualXml::names($this->residual_xml));
    }
}
