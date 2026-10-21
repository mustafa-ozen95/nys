<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\SecureAccessAction;
use App\Models\SwitchSecureVlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Bir VLAN'ın erişim güvenliği kuralı (secure-access-port vlan <ad>).
 *
 * Cihazın kendi kuralları formda ÖNCEDEN söylenir ve sunucuda da durur:
 *
 * - VLAN bu switchte TANIMLI olmalı. "all" sanal adını ELS ÖNCESİ kabul eder
 *   (test1, commit check: "vlan all { examine-dhcp; }" KABUL); ELS ise
 *   "Invalid vlan name specified. Reserved word cannot be used" diyerek
 *   reddeder (test4'te ölçüldü). Kararı lehçe verir.
 * - ARP denetimi ve IP kaynak koruması DHCP snooping tablosuna dayanır.
 *   Snooping kapalıyken tablo boştur ve IP kaynak koruması o VLAN'daki
 *   statik adresli her cihazın trafiğini düşürür — koruma değil kesinti.
 * - Eylem tek başına yazılmaz.
 */
class SecureVlanRequest extends SecureAccessRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $mevcut = $this->route('secureVlan');

        return [
            'vlan_name' => [
                Rule::requiredIf($mevcut === null),
                'string', 'max:64',
                Rule::unique(SwitchSecureVlan::class, 'vlan_name')
                    ->where('switch_id', $this->switch()->id)
                    ->ignore($mevcut),
            ],

            'examine_dhcp' => ['boolean'],
            'arp_inspection' => ['boolean'],
            'ip_source_guard' => ['boolean'],

            /*
             * Üst sınır cihazda ÖLÇÜLMEDİ; aralık makul bir çerçevedir.
             * Cihaz kendi sınırını commit check'te söyler ve hata cihaz
             * işlem kaydına düşer.
             */
            'mac_move_limit' => ['nullable', 'integer', 'between:1,65535'],
            'mac_move_action' => ['nullable', Rule::enum(SecureAccessAction::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardSchema($validator, 'vlan_name');
            $this->guardVlanExists($validator);
            $this->guardLimitAction($validator, 'mac_move_limit', 'mac_move_action');
            $this->guardSnoopingDependency($validator);
        });
    }

    /**
     * Tanımsız VLAN adı commit'i düşürür; panel önce söyler.
     */
    private function guardVlanExists(Validator $validator): void
    {
        $name = $this->name();

        if ($name === '') {
            return;
        }

        if ($name === 'all') {
            if (! $this->dialect()->supportsAllKeyword()) {
                $validator->errors()->add(
                    'vlan_name',
                    'Bu Junos ailesi "all" adını ayrılmış sözcük sayıyor; VLAN’ı tek tek seçin.',
                );
            }

            return;
        }

        if (! $this->switch()->vlans()->where('name', $name)->exists()) {
            $validator->errors()->add('vlan_name', 'Bu switchte böyle bir VLAN yok.');
        }
    }

    /**
     * ARP denetimi ve IP kaynak koruması snooping tablosuna dayanır; tablo
     * boşken koruma değil KESİNTİ üretirler.
     */
    private function guardSnoopingDependency(Validator $validator): void
    {
        if ($this->boolean('examine_dhcp')) {
            return;
        }

        $bagimlilar = [
            'arp_inspection' => 'ARP denetimi',
            'ip_source_guard' => 'IP kaynak koruması',
        ];

        foreach ($bagimlilar as $alan => $etiket) {
            if ($this->boolean($alan)) {
                $validator->errors()->add(
                    $alan,
                    "{$etiket} DHCP snooping tablosuna dayanır; snooping kapalıyken tablo boş kalır ve "
                        .'bu VLAN’daki trafik düşürülür. Önce DHCP snooping’i açın.',
                );
            }
        }
    }

    /**
     * Cihaza yazılacak ayarlar. Korunan ham satırlar düzenlenen kayıttan
     * gelir; panelde gösterilmeyen satırlar kaybolmasın.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $mevcut = $this->route('secureVlan');

        return [
            'examine_dhcp' => $this->boolean('examine_dhcp'),
            'arp_inspection' => $this->boolean('arp_inspection'),
            'ip_source_guard' => $this->boolean('ip_source_guard'),
            'mac_move_limit' => $this->filled('mac_move_limit') ? $this->integer('mac_move_limit') : null,
            'mac_move_action' => $this->filled('mac_move_action') ? $this->string('mac_move_action')->toString() : null,
            'residual_xml' => $mevcut instanceof SwitchSecureVlan ? $mevcut->residual_xml : null,
        ];
    }

    /**
     * Düzenlemede ad KAYITTAN gelir: VLAN adı bu kuralın kimliğidir ve
     * değiştirilmesi başka bir kural demektir.
     */
    public function vlanName(): string
    {
        $mevcut = $this->route('secureVlan');

        return $mevcut instanceof SwitchSecureVlan ? (string) $mevcut->vlan_name : $this->name();
    }

    private function name(): string
    {
        return trim($this->string('vlan_name')->toString());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vlan_name' => 'VLAN',
            'examine_dhcp' => 'DHCP snooping',
            'arp_inspection' => 'ARP denetimi',
            'ip_source_guard' => 'IP kaynak koruması',
            'mac_move_limit' => 'MAC taşıma sınırı',
            'mac_move_action' => 'sınır eylemi',
        ];
    }
}
