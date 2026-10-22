<?php

declare(strict_types=1);

namespace App\Http\Requests\Addressing;

use App\Enums\AddressConnectionType;
use App\Enums\AddressPurpose;
use App\Enums\SwitchRole;
use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\Vlan;
use App\Rules\AlignedNetwork;
use App\Rules\AnnounceablePrefix;
use App\Rules\NotOverlappingBlock;
use App\Rules\NotReservedRange;
use App\Rules\WithinParentBlock;
use App\Services\Addressing\AssignmentRights;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class AddressBlockRequest extends FormRequest
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
        return $this->cidrRules() + $this->assignmentRules() + [
            /*
             * Alt blok üst bloğun amacını devralır, o yüzden alt ağ formunda
             * sorulmaz; kök blokta da boş bırakılabilir (müşteri sayılır).
             */
            'purpose' => ['nullable', Rule::enum(AddressPurpose::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Atama alanları. Düzenlemede CIDR değişmediği için yalnızca bunlar gelir.
     *
     * @return array<string, array<int, mixed>>
     */
    private function assignmentRules(): array
    {
        return [
            'core_switch_id' => [
                'nullable',
                /*
                 * Prefix list omurgada durur. VLAN ve port ise omurgada da
                 * olabilir; erişim switchi seçilmemişse hedef omurgadan
                 * okunur, o yüzden en az biri gerekir.
                 */
                Rule::requiredIf(fn (): bool => $this->routesTo(AddressConnectionType::PrefixList)),
                Rule::requiredIf(fn (): bool => $this->routesToInterface()
                    && ! $this->filled('access_switch_id')),
                $this->switchExists([SwitchRole::Core]),
            ],

            'access_switch_id' => [
                'nullable',
                $this->switchExists([SwitchRole::Access]),
            ],
            'connection_type' => ['nullable', Rule::enum(AddressConnectionType::class)],

            /*
             * Ayrı bir anons alanı yoktur: blok omurgadaki bir prefix list'e
             * girmişse anons ediliyordur. ASN de sorulmaz — anons
             * e-postasının kendi ASN alanı vardır.
             */
            // VLAN ve port elle yazılmaz, switchte var olanlardan seçilir.
            /*
             * Yönteme ait olmayan alan sessizce yok sayılmaz, reddedilir.
             * Arayüzde gizlemek yetmez: istek doğrudan da gelebilir ve
             * kullanıcı gönderdiği değerin kaydedildiğini sanır.
             */
            /*
             * VLAN ETİKETİYLE değil KAYDIYLA seçilir. Etiket tekildir ama
             * kalıcı değil; cihazda etiketsiz VLAN da geçerlidir ve etiketle
             * seçilseydi böyle bir VLAN'a blok hiç bağlanamazdı.
             */
            'vlan_id' => [
                'nullable', 'uuid',
                Rule::requiredIf(fn (): bool => $this->routesTo(AddressConnectionType::Vlan)),
                Rule::prohibitedIf(fn (): bool => ! $this->routesTo(AddressConnectionType::Vlan)),
                Rule::exists(Vlan::class, 'uuid')->where('switch_id', $this->targetSwitchId()),
            ],
            /*
             * Prefix list omurga switchte durur; seçilen liste o switche ait
             * olmalı. Erişim switchi bu türde rol oynamaz.
             */
            'prefix_list_id' => [
                'nullable', 'uuid',
                Rule::requiredIf(fn (): bool => $this->routesTo(AddressConnectionType::PrefixList)),
                Rule::prohibitedIf(fn (): bool => ! $this->routesTo(AddressConnectionType::PrefixList)),
                Rule::exists(SwitchPrefixList::class, 'uuid')
                    ->where('switch_id', $this->switchId('core_switch_id')),
            ],
            /*
             * Statik route hedefi iki yoldan gelir: envanterdeki bir switchin
             * uplink adresi ya da elle girilen IP. İkisi de aynı alana yazılır;
             * hangisinin dolu olacağını kaynak seçimi belirler.
             */
            'next_hop_source' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->routesTo(AddressConnectionType::NextHop)),
                Rule::prohibitedIf(fn (): bool => ! $this->routesTo(AddressConnectionType::NextHop)),
                Rule::in(['switch', 'manual']),
            ],
            'next_hop_switch' => [
                'nullable', 'ipv4',
                Rule::requiredIf(fn (): bool => $this->nextHopSource() === 'switch'),
                Rule::prohibitedIf(fn (): bool => $this->nextHopSource() !== 'switch'),
            ],
            'next_hop' => [
                'nullable', 'ipv4',
                Rule::requiredIf(fn (): bool => $this->nextHopSource() === 'manual'),
                Rule::prohibitedIf(fn (): bool => $this->nextHopSource() !== 'manual'),
            ],
            'port_name' => [
                'nullable', 'string', 'max:255',
                Rule::requiredIf(fn (): bool => $this->routesTo(AddressConnectionType::Port)),
                Rule::prohibitedIf(fn (): bool => ! $this->routesTo(AddressConnectionType::Port)),
                Rule::exists(SwitchPort::class, 'name')->where('switch_id', $this->targetSwitchId()),
            ],
        ];
    }

    /**
     * Ağ adresi ile maske ayrı alanlardır: maske listeden seçilir, adres
     * yazılır ve maskeye hizalı olmak zorundadır. Böylece hatalı bir blok
     * sessizce düzeltilerek kaydedilmez.
     *
     * @return array<string, array<int, mixed>>
     */
    private function cidrRules(): array
    {
        if ($this->isMethod('PUT')) {
            return [];
        }

        $parent = $this->parent();
        $prefix = $this->integer('prefix_length');

        return [
            /*
             * Üst sınır AİLEYE bağlıdır: IPv4'te /32, IPv6'da /128. Sabit
             * "max:32" bütün adresleme modülünü IPv4'e kilitlerdi.
             */
            'prefix_length' => [
                'required', 'integer', 'min:1', 'max:'.($this->isIpv6() ? 128 : 32),
                new AnnounceablePrefix($this->isAnnounced(), $this->isIpv6()),
            ],
            'network' => array_values(array_filter([
                'required', 'string',

                /*
                 * MASKE BU ALANA YAZILMAZ — ama yazılabiliyor ve genel
                 * "geçerli bir IP adresi olmalıdır" mesajı NE YAPACAĞINI
                 * söylemiyor (ör. "192.0.2.0/24" yazıldığında). Kural,
                 * düzeltmesiyle birlikte söylenir.
                 *
                 * SESSİZCE AYRIŞTIRILMAZ: kullanıcının yazdığı /24 ile
                 * listedeki maske farklı olabilir ve hangisinin kazandığı
                 * ekranda görünmezdi. Panelin bu konudaki duruşu zaten belli
                 * (bkz. AlignedNetwork: "sessizce düzeltilmez").
                 */
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! str_contains($value, '/')) {
                        return;
                    }

                    [$address, $mask] = array_pad(explode('/', trim($value), 2), 2, '');

                    $fail(trim(
                        'Bu alana yalnızca ağ adresi yazılır, maske ayrı seçilir: '
                        ."\"{$address}\" yazıp maskeyi yandaki listeden seçin"
                        .($mask === '' ? '.' : " (/{$mask}).")
                    ));
                },

                'ip',
                new AlignedNetwork($prefix),
                new NotReservedRange($prefix),
                $parent !== null ? new WithinParentBlock($parent, $prefix) : null,
                new NotOverlappingBlock($prefix, $parent),
            ])),
        ];
    }

    /**
     * Girilen ağ adresinin ailesi. Maske sınırı ve ayrılmış aralık kontrolü
     * buna bağlı.
     */
    private function isIpv6(): bool
    {
        $network = $this->string('network')->trim()->toString();

        return $network !== '' && str_contains($network, ':');
    }

    private function routesTo(AddressConnectionType $type): bool
    {
        return $this->string('connection_type')->toString() === $type->value;
    }

    /**
     * Alanın adı rolü söylüyorsa doğrulama da rolü kontrol etmeli; listeyi
     * filtrelemek yetmez, istek doğrudan da gelebilir.
     *
     * @param  list<SwitchRole>  $roles
     */
    private function switchExists(array $roles = []): Exists
    {
        $rule = Rule::exists(NetworkSwitch::class, 'uuid')->whereNull('deleted_at');

        return $roles === []
            ? $rule
            : $rule->whereIn('role', array_map(fn (SwitchRole $role): string => $role->value, $roles));
    }

    /**
     * Formlar switchi uuid ile taşır; veritabanı ilişkisi tamsayıdır.
     */
    private function switchId(string $field): ?int
    {
        return $this->filled($field)
            ? NetworkSwitch::query()->where('uuid', $this->string($field)->toString())->value('id')
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'network' => 'ağ adresi',
            'prefix_length' => 'maske',
            'description' => 'başlık',
            'notes' => 'açıklama',
            'core_switch_id' => 'omurga switch',
            'access_switch_id' => 'erişim switch',
            'connection_type' => 'route',
            'vlan_id' => 'VLAN',
            'port_name' => 'port adı',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateDeviceWritePermission($validator);
            $this->validateAssignmentRights($validator);
            $this->validateVlanHasL3Interface($validator);
        });
    }

    /**
     * VLAN'a bağlanan blok, VLAN'ın L3 arayüzüne geçit adresi yazar.
     *
     * Arayüz adı VLAN kaydından okunur; tanımlı değilse yazılacak bir ad
     * yoktur ve blok panelde "bağlandı" görünürken cihazda hiçbir arayüz onu
     * taşımazdı. Aynı satıra giden iki yol (IP adresleri ekranı ve
     * adresleme ekranı) aynı korumadan geçer.
     */
    /**
     * Seçilen VLAN — hedef switchte olmak zorunda; başka switchin VLAN'ı
     * kabul edilmez.
     */
    private function vlan(): ?Vlan
    {
        if (! $this->filled('vlan_id')) {
            return null;
        }

        return Vlan::query()
            ->where('switch_id', $this->targetSwitchId())
            ->firstWhere('uuid', $this->string('vlan_id')->toString());
    }

    private function validateVlanHasL3Interface(Validator $validator): void
    {
        if (! $this->routesTo(AddressConnectionType::Vlan)) {
            return;
        }

        $vlan = $this->vlan();

        if ($vlan === null || filled($vlan->l3_interface)) {
            return;
        }

        $validator->errors()->add(
            'vlan_id',
            'Seçilen VLAN’ın L3 arayüzü tanımlı değil; blok bu VLAN’a bağlanamaz. '
            .'Önce VLAN kaydına L3 arayüzünü girin.',
        );
    }

    /**
     * Prefix list ataması CİHAZA YAZAR: seçilen listeye
     * "set policy-options prefix-list <liste> <blok>" satırı eklenir.
     *
     * Cihaza yazmak ayrı bir yetkidir ("configure"), adres bloğu düzenleme
     * yetkisi onu kapsamaz. Bunsuz, yalnızca adresleme yetkisi olan biri
     * omurga switchin yapılandırmasını değiştirebilirdi.
     */
    private function validateDeviceWritePermission(Validator $validator): void
    {
        if (! $this->routesTo(AddressConnectionType::PrefixList)) {
            return;
        }

        $switch = NetworkSwitch::query()->find($this->switchId('core_switch_id'));

        if ($switch === null || $this->user()?->can('configure', $switch)) {
            return;
        }

        $validator->errors()->add(
            'prefix_list_id',
            'Prefix list ataması omurga switche yazılır; bunun için o switchte “Cihaza Yaz” yetkisi gerekir.',
        );
    }

    /**
     * Route ve arayüz (VLAN/port) ataması da cihaza yazar; her biri o cihaz
     * nesnesinin kendi yetkisini ister (bkz. AssignmentRights).
     */
    private function validateAssignmentRights(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $once = $this->route('block');
        $once = $this->isMethod('PUT') && $once instanceof AddressBlock ? $once : null;

        // Kaydedilmemiş, yeni hâli taşıyan blok: parmak izi servislerden alınır.
        $after = (new AddressBlock)->forceFill($this->blockAttributes());

        foreach (app(AssignmentRights::class)->missing($this->user(), $once, $after) as $alan => $message) {
            $validator->errors()->add($alan, $message);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'core_switch_id.exists' => 'Omurga alanına yalnızca omurga switchi seçilebilir.',
            'prefix_list_id.exists' => 'Seçilen prefix list, seçilen omurga switchte tanımlı değil.',
            'vlan_id.prohibited' => 'VLAN yalnızca VLAN yönteminde gönderilir.',
            'port_name.prohibited' => 'Port yalnızca port yönteminde gönderilir.',
            'next_hop.prohibited' => 'Sonraki atlama IP’si yalnızca elle giriş seçiliyken gönderilir.',
            'next_hop_switch.prohibited' => 'Uplink IP yalnızca switchten seçim yapılırken gönderilir.',
            'next_hop_source.prohibited' => 'Sonraki atlama kaynağı yalnızca Static Route yönteminde gönderilir.',
            'core_switch_id.required' => 'VLAN ve port bir switchin üzerindedir: omurga ya da erişim switchi seçilmeli.',
            'access_switch_id.exists' => 'Erişim alanına yalnızca erişim switchi seçilebilir.',
            'access_switch_id.required' => 'Route için erişim switchi seçilmeli.',
            'access_switch_id.prohibited' => 'Anons edilen prefix bir erişim switchine route edilmez.',
            'connection_type.prohibited' => 'Anons edilen prefix VLAN veya porta bağlanmaz.',
            'vlan_id.exists' => 'Seçilen VLAN bu switchte tanımlı değil.',
            'port_name.exists' => 'Seçilen port bu switchte tanımlı değil.',
            'vlan_id.required' => 'VLAN ataması için bir VLAN seçilmeli.',
            'port_name.required' => 'Port ataması için port adı girilmeli.',
        ];
    }

    /**
     * Bloğun ekleneceği üst blok; kök blokta null.
     */
    public function parent(): ?AddressBlock
    {
        $parent = $this->route('block');

        return $this->isMethod('POST') && $parent instanceof AddressBlock ? $parent : null;
    }

    /**
     * Bağlantı türü boşsa atama alanlarının tamamı temizlenir; aksi halde
     * kullanılmayan alan (VLAN seçiliyken port adı) veritabanında kalırdı.
     *
     * @return array<string, mixed>
     */
    /**
     * VLAN ya da port yöntemi seçilmiş mi. İkisi de bir switchin arayüzüne
     * bağlanır; o switch omurga da olabilir erişim de.
     */
    public function routesToInterface(): bool
    {
        return $this->routesTo(AddressConnectionType::Vlan)
            || $this->routesTo(AddressConnectionType::Port);
    }

    /**
     * VLAN ve portun okunacağı switch: erişim switchi seçilmişse o, değilse
     * omurga. VLAN ve port yalnızca erişim switchinde olmak zorunda değil.
     */
    public function targetSwitchId(): ?int
    {
        return $this->switchId('access_switch_id') ?? $this->switchId('core_switch_id');
    }

    /**
     * Blok omurgadaki bir prefix list'e giriyorsa anons ediliyordur:
     * "set policy-options prefix-list musteri1-PREFIXES 192.0.2.0/24".
     */
    public function isAnnounced(): bool
    {
        return $this->routesTo(AddressConnectionType::PrefixList);
    }

    /**
     * Yalnızca statik route yönteminde anlamlı: hedefin nereden geldiği.
     */
    private function nextHopSource(): ?string
    {
        if (! $this->routesTo(AddressConnectionType::NextHop)) {
            return null;
        }

        return $this->string('next_hop_source')->toString() ?: null;
    }

    /**
     * Statik route hedefi: ya envanterdeki bir switchin uplink adresi ya da
     * elle girilen IP. Müşteri switchleri envanterde olmadığı için elle
     * giriş şart.
     */
    public function nextHop(): ?string
    {
        return $this->string('next_hop_source')->toString() === 'switch'
            ? ($this->string('next_hop_switch')->toString() ?: null)
            : ($this->string('next_hop')->toString() ?: null);
    }

    public function blockAttributes(): array
    {
        $data = $this->safe()->only(['description', 'notes']);

        /*
         * Amaç yalnızca verildiyse gönderilir: alt ağ formunda sorulmuyor ve
         * boş gönderilseydi üst bloktan devralma bozulurdu.
         */
        if ($this->filled('purpose')) {
            $data['purpose'] = $this->enum('purpose', AddressPurpose::class);
        }

        if ($this->isMethod('POST')) {
            $data['cidr'] = $this->string('network')->toString().'/'.$this->integer('prefix_length');
        }
        $type = $this->enum('connection_type', AddressConnectionType::class);

        $announced = $this->isAnnounced();

        return $data + [
            'core_switch_id' => $this->switchId('core_switch_id'),
            'access_switch_id' => $this->switchId('access_switch_id'),
            'connection_type' => $type,
            'vlan_id' => $type === AddressConnectionType::Vlan ? $this->vlan()?->id : null,
            'port_name' => $type === AddressConnectionType::Port ? $this->string('port_name')->toString() : null,
            'next_hop' => $type === AddressConnectionType::NextHop ? $this->nextHop() : null,
            'switch_prefix_list_id' => $type === AddressConnectionType::PrefixList
                ? SwitchPrefixList::query()->where('uuid', $this->string('prefix_list_id'))->value('id')
                : null,
            // Anons ayrı bir alan değil: prefix list'e giren blok anonstur.
            'is_announced' => $announced,
        ];
    }
}
