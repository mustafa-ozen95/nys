<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecureAccessAction;
use App\Models\Concerns\HasUuid;
use App\Support\Netconf\ResidualXml;
use Database\Factories\SwitchSecureInterfaceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir arayüzün erişim güvenliği kuralları (secure-access-port interface <ad>).
 *
 * "dhcp_trusted" ÜÇ DURUMLUDUR ve tek bir boolean değildir:
 *
 *   null  → cihazın varsayılanı; satır yazılmaz
 *   true  → dhcp-trusted; port DHCP sunucusu cevabı verebilir
 *   false → no-dhcp-trusted; port DHCP sunucusu gibi davranamaz
 *
 * true YALNIZCA uplink portuna verilir. Erişim portuna verildiğinde o porta
 * takılan herhangi bir cihaz ağın DHCP sunucusu gibi davranabilir ve
 * snooping'in bütün koruması ortadan kalkar.
 */
class SwitchSecureInterface extends Model
{
    /** @use HasFactory<SwitchSecureInterfaceFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id', 'interface_name', 'vlan_name',
        'dhcp_trusted', 'mac_limit', 'mac_limit_action', 'allowed_macs',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dhcp_trusted' => 'boolean',
            'mac_limit' => 'integer',
            'mac_limit_action' => SecureAccessAction::class,
            'allowed_macs' => 'array',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Arayüz adının port tablosundaki karşılığı.
     *
     * Cihaz bu düğümde adı BİRİMLİ tutar (ge-0/0/5.0), port envanteri ise
     * birimsiz (ge-0/0/5). Soymadan arandığında cihazdan okunmuş bir satır
     * hiçbir zaman bulunamaz: ne doğrulama portu tanır ne de ekran modunu
     * gösterebilir.
     */
    public static function basePortName(string $name): string
    {
        return (string) preg_replace('/\.\d+$/', '', trim($name));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function portName(): Attribute
    {
        return Attribute::get(fn (): string => self::basePortName((string) $this->interface_name));
    }

    /**
     * Güven durumunun okunur hâli; üç durumun üçü de ayrı görünür.
     *
     * @return Attribute<string, never>
     */
    protected function trustLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->dhcp_trusted) {
            true => 'DHCP güvenilir (uplink)',
            false => 'DHCP güvenilmez',
            default => 'Cihazın varsayılanı',
        });
    }

    /**
     * Formdaki üç durumlu seçicinin değeri.
     *
     * Blade eşleme yapmaz: "yok" ile "hayır" ayrı değerlerdir ve ikisini
     * ayırt eden ifadeyi görünümde tekrarlamak, iki formdan birinde yanlış
     * yazılmasıyla biter.
     *
     * @return Attribute<string, never>
     */
    protected function trustValue(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->dhcp_trusted) {
            true => '1',
            false => '0',
            default => '',
        });
    }

    /**
     * MAC sınırının okunur hâli.
     *
     * @return Attribute<?string, never>
     */
    protected function limitLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->mac_limit === null
            ? null
            : $this->mac_limit.' MAC · '.($this->mac_limit_action?->label() ?? 'cihazın varsayılanı'));
    }

    /**
     * Satırın özeti. Blade üç alanı birleştirmez; hazır metni model verir.
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $fragments = array_filter([
                $this->limit_label === null ? null : 'MAC sınırı: '.$this->limit_label,
                empty($this->allowed_macs) ? null : count($this->allowed_macs).' izinli MAC',
            ]);

            return $fragments === [] ? '—' : implode(' · ', $fragments);
        });
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => ResidualXml::names($this->residual_xml));
    }
}
