<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecureAccessAction;
use App\Models\Concerns\HasUuid;
use App\Support\Netconf\ResidualXml;
use Database\Factories\SwitchSecureVlanFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir VLAN'ın erişim güvenliği kuralları (secure-access-port vlan <ad>).
 *
 * DHCP snooping buradan açılır: cihaz VLAN'daki DHCP trafiğini inceler ve
 * "hangi MAC hangi IP'yi hangi porttan aldı" tablosunu kurar. ARP denetimi
 * ve IP kaynak koruması O TABLOYA dayanır — snooping kapalıyken ikisi de
 * koruma sağlamaz.
 */
class SwitchSecureVlan extends Model
{
    /** @use HasFactory<SwitchSecureVlanFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id', 'vlan_name',
        'examine_dhcp', 'arp_inspection', 'ip_source_guard',
        'mac_move_limit', 'mac_move_action',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'examine_dhcp' => 'boolean',
            'arp_inspection' => 'boolean',
            'ip_source_guard' => 'boolean',
            'mac_move_limit' => 'integer',
            'mac_move_action' => SecureAccessAction::class,
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Açık olan korumalar, ekranda rozet olarak basılır.
     *
     * @return Attribute<list<string>, never>
     */
    protected function protections(): Attribute
    {
        return Attribute::get(fn (): array => array_keys(array_filter([
            'DHCP snooping' => $this->examine_dhcp,
            'ARP denetimi' => $this->arp_inspection,
            'IP kaynak koruması' => $this->ip_source_guard,
        ])));
    }

    /**
     * MAC taşıma sınırının okunur hâli.
     *
     * @return Attribute<?string, never>
     */
    protected function limitLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->mac_move_limit === null
            ? null
            : $this->mac_move_limit.' MAC · '.($this->mac_move_action?->label() ?? 'cihazın varsayılanı'));
    }

    /**
     * Satırın özeti. Blade üç alanı birleştirmez; hazır metni model verir.
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(fn (): string => $this->limit_label === null
            ? '—'
            : 'MAC taşıma: '.$this->limit_label);
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => ResidualXml::names($this->residual_xml));
    }
}
