<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Netconf\ResidualXml;
use Database\Factories\SwitchVoipInterfaceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir arayüzün VoIP (ses) VLAN ayarı.
 *
 * IP telefon ile ona bağlı bilgisayar tek kabloyu paylaşır: telefon ses
 * VLAN'ında ETİKETLİ, bilgisayar portun access VLAN'ında ETİKETSİZ gider.
 * Bu yüzden ses VLAN'ı portun access VLAN'ından farklı olmak zorundadır.
 */
class SwitchVoipInterface extends Model
{
    /** @use HasFactory<SwitchVoipInterfaceFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * Junos'un VoIP'te kabul ettiği sanal arayüz adları; port tablosunda
     * karşılıkları yoktur ve porta uygulanan kontroller onlara uygulanmaz.
     *
     * "all" burada YOKTUR. Panel onu uzun süre sundu; cihaz iki lehçede de
     * reddediyor (aynı belge, tek fark arayüz adı):
     *
     *   test2 / 12.3  → "Interface all not found or does not have
     *                    ethernet-switching family"
     *   test4 / 24.4  → "ethernet-switching is not enabled on all!"
     *
     * "access-ports" ikisinde de KABUL ediliyor.
     *
     * @var list<string>
     */
    public const VIRTUAL_INTERFACES = ['access-ports'];

    protected $fillable = [
        'switch_id', 'interface_name', 'vlan_name', 'forwarding_class', 'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Ayarın tek satırlık özeti.
     *
     * Blade üç alanı kendisi birleştirmez; hazır metni model verir
     * (bkz. .ai/rules/arayuz.md → liste satırı).
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            filled($this->vlan_name) && filled($this->forwarding_class) => "{$this->vlan_name} · {$this->forwarding_class}",
            filled($this->vlan_name) => (string) $this->vlan_name,
            filled($this->forwarding_class) => "sınıf {$this->forwarding_class}",
            default => 'ayar yok',
        });
    }

    /**
     * Arayüz adının BİRİMSİZ hâli: cihaz "ge-0/0/5.0" yazar, port tablosunda
     * "ge-0/0/5" durur. Eşleştirme tek yerden yapılır.
     *
     * @return Attribute<string, never>
     */
    protected function portName(): Attribute
    {
        return Attribute::get(fn (): string => (string) preg_replace('/\.\d+$/', '', (string) $this->interface_name));
    }

    /**
     * Cihazın sanal adı mı ("all", "access-ports")?
     *
     * @return Attribute<bool, never>
     */
    protected function isVirtual(): Attribute
    {
        return Attribute::get(fn (): bool => in_array($this->interface_name, self::VIRTUAL_INTERFACES, true));
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => ResidualXml::names($this->residual_xml));
    }
}
