<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\SwitchPort;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak VLAN.
 *
 * Ad yalnızca yaratılırken sorulur: cihazda VLAN'ın kimliği adıdır ve port
 * üyelikleri de adla tutulur. Yeniden adlandırma, VLAN'ı silip yeniden
 * yaratmak ve bütün üye portları yeniden yazmak demek olurdu.
 */
class VlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;

        return [
            /*
             * Junos adı: harfle başlamalı. Cihazda denendi — "90vlan"
             * "statement creation failed" ile reddediliyor, "all" ayrılmış
             * bir anahtar sözcük, "default" ise cihazda zaten var olan
             * VLAN'ın adı.
             */
            /*
             * AD CİHAZDA VLAN'IN KİMLİĞİDİR ve bir switchte tekildir.
             *
             * Panel yalnızca etiketi tekil tutuyordu: var olan bir adla "VLAN
             * ekle" demek, merge yüzünden cihazdaki VLAN'ı yeniden
             * etiketliyor, açıklamasını ve l3-interface'ini siliyor ve bütün
             * üye portlarını yeni etikete taşıyordu — panel ise "eklendi"
             * diyordu.
             */
            'name' => [
                Rule::requiredIf($this->route('vlan') === null),
                'string', 'max:64',
                'regex:/^[A-Za-z][A-Za-z0-9_-]*$/',
                Rule::notIn(['all', 'default']),
                Rule::unique(Vlan::class, 'name')
                    ->where('switch_id', $switchId)
                    ->ignore($this->route('vlan')),
            ],

            /*
             * Etiket ZORUNLU DEĞİL: Junos "vlans <ad>" satırını vlan-id
             * olmadan kabul ediyor ve cihazlarda böyle VLAN'lar bulunabilir.
             * Panel etiketi dayatsaydı, cihazdan okunan etiketsiz bir VLAN
             * kaydedilemez — düzenlenmek istendiğinde form onu reddederdi.
             *
             * Tekillik korunur: bir switchte aynı etiket iki kez tanımlanamaz.
             */
            'vlan_tag' => [
                'nullable', 'integer', 'min:1', 'max:4094',
                Rule::unique(Vlan::class, 'vlan_tag')
                    ->where('switch_id', $switchId)
                    ->ignore($this->route('vlan')),
            ],

            /*
             * Açıklama cihaza da yazılır: terminale düşen biri VLAN'ın ne işe
             * yaradığını panele bakmadan görsün.
             */
            'description' => ['nullable', 'string', 'max:255'],

            /*
             * L3 arayüzü cihazda bir arayüz birimidir ve ADI ŞEMA AİLESİNE
             * BAĞLIDIR: ELS öncesinde "vlan.100", ELS'te "irb.100". Doğrulama
             * ikisini de tanır — cihazdan okunan değer neyse envantere o
             * girer ve form kendi cihazının değerini geri çevirmemeli.
             */
            'l3_interface' => [
                'nullable', 'string', 'max:64',
                'regex:/^('.implode('|', JunosSchemaProfile::l3InterfaceNames()).')\.\d{1,4}$/',
            ],

            'ports' => ['array'],
            // Yalnızca bu switchin portları atanabilir; değerler uuid.
            'ports.*' => [
                'uuid',
                Rule::exists(SwitchPort::class, 'uuid')->where('switch_id', $switchId),
            ],
        ];
    }

    /**
     * VLAN ekranından her porta üyelik verilemez.
     *
     * Port ekranı bu iki durumu zaten engelliyordu, VLAN ekranı engellemiyordu
     * — aynı cihaz satırına iki yoldan gidiliyor ve yalnızca biri korunuyordu:
     *
     * - ae ÜYESİ port: cihaz "logical unit is not allowed on aggregated links"
     *   diyerek commit'i düşürür.
     * - ROUTED port: üyelik vermek inet ailesini kaldırmayı gerektirir, yani
     *   portun IP yapılandırmasını silmeyi. Port ekranı bunu açık bir uyarıyla
     *   soruyor; VLAN ekranında böyle bir seçim yok ve sessizce yapılmamalı.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardVlanIdForL3($validator);
            foreach ($this->ports() as $port) {
                if ($port->aggregate_port_id !== null) {
                    $validator->errors()->add(
                        'ports',
                        "{$port->name} bir ae arayüzüne üye; üye porta VLAN üyeliği verilemez. "
                        .'Üyelik ae arayüzünün kendisine verilir.',
                    );

                    continue;
                }

                if ($port->port_mode === PortMode::Routed) {
                    $validator->errors()->add(
                        'ports',
                        "{$port->name} routed bir port. VLAN üyeliği vermek portun IP yapılandırmasını "
                        .'silerdi; önce port ekranından portun modunu değiştirin.',
                    );
                }
            }
        });
    }

    /**
     * Etiket boş gönderilebilir; o zaman cihazdaki vlan-id satırı silinir.
     */
    public function vlanTag(): ?int
    {
        return $this->filled('vlan_tag') ? $this->integer('vlan_tag') : null;
    }

    public function l3Interface(): ?string
    {
        return $this->string('l3_interface')->toString() ?: null;
    }

    public function description(): ?string
    {
        return $this->string('description')->trim()->toString() ?: null;
    }

    /**
     * Seçilen portlar; üyelik cihaza port başına yazıldığı için modeller
     * VLAN'larıyla birlikte yüklenir.
     *
     * @return list<SwitchPort>
     */
    public function ports(): array
    {
        $uuids = $this->input('ports', []);

        if ($uuids === []) {
            return [];
        }

        return SwitchPort::query()
            ->with('vlans')
            ->where('switch_id', $this->route('switch')->id)
            ->whereIn('uuid', $uuids)
            ->get()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'ad',
            'vlan_tag' => 'VLAN etiketi',
            'l3_interface' => 'L3 arayüzü',
            'ports' => 'portlar',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Bu switchte aynı adla bir VLAN zaten var. Ad cihazda VLAN’ın kimliğidir; '
                .'aynı adla ekleme mevcut VLAN’ı yeniden etiketler ve üye portlarını taşırdı.',
            'vlan_tag.unique' => 'Bu switchte bu VLAN etiketi zaten tanımlı.',
            'name.regex' => 'VLAN adı harfle başlamalı; yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
            'name.not_in' => '“all” ve “default” Junos’ta ayrılmış adlardır, VLAN adı olarak kullanılamaz.',
            'l3_interface.regex' => 'L3 arayüzü vlan.100 biçiminde olmalı.',
        ];
    }

    /**
     * ELS L3 arayüzünü etiketsiz VLAN'a bağlatmıyor.
     *
     * Cihaz reddediyor: "l3-interface can be configured only under vlans
     * with 'vlan-id'/'vlan-tags'". Kısıt ELS'e özgüdür — ELS öncesinde
     * etiketsiz VLAN L3 arayüzü taşıyabilir — bu yüzden kuralı LEHÇE söyler.
     */
    private function guardVlanIdForL3(Validator $validator): void
    {
        $switch = $this->route('switch');

        $lehce = ($switch?->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();

        if (! $lehce->requiresVlanIdForL3()) {
            return;
        }

        if ($this->filled('l3_interface') && ! $this->filled('vlan_tag')) {
            $validator->errors()->add(
                'vlan_tag',
                'Bu cihazda L3 arayüzü olan VLAN etiketsiz olamaz; bir VLAN etiketi verin.',
            );
        }
    }
}
