<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\FirewallFamily;
use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchIpAddress;
use App\Models\Vlan;
use App\Services\Capabilities\DeviceCapabilities;
use App\Services\Switching\SwitchAddressCandidateService;
use App\Services\Switching\SwitchPortService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak port ayarları.
 *
 * Alanlar birbirinden bağımsız değildir; form da doğrulama da bunu yansıtır:
 *
 * - Hız ile auto-negotiation aynı Junos ayarıdır (ether-options speed). İkisi
 *   birden yazılamaz, o yüzden tek alan vardır: hız boşsa auto-negotiation.
 * - Access portta VLAN üyeliği TEK olabilir; cihaz fazlasını "Access interface
 *   has more than one vlan member" ile reddeder. Etiketsiz VLAN da o tek
 *   üyedir, ayrıca sorulmaz.
 * - Trunk portta üyelik listedir ve etiketsiz (native) VLAN seçilenlerden
 *   biri olmalıdır.
 * - PoE yalnızca donanımı olan portlarda sorulur (is_poe_enabled null değilse).
 *
 * Port modu yalnızca access ya da trunk olabilir: "routed" family inet
 * yapılandırmasıdır ve IP adresleri ekranının işidir, "unconfigured" ise bir
 * seçim değil, hiç yapılandırılmamış olma hâlidir.
 */
class SwitchPortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Access portta VLAN seçici boş bırakılabilir; boş seçenek diziye boş
     * dize olarak gelir ve uuid doğrulamasına takılırdı.
     */
    protected function prepareForValidation(): void
    {
        $vlans = array_values(array_filter(
            (array) $this->input('vlans', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        $this->merge(['vlans' => $vlans]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;

        return [
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],

            /*
             * MTU sınırları cihazın kendi cevabıdır: "Value 9217 is not
             * within range (256..9216)" (jn32-test, 12.3R12.4).
             *
             * ae ÜYESİ portta yazılamaz — cihaz "ae child device mtu setting
             * and vlan-tagging is not allowed" diyor. O durumda MTU ae
             * arayüzünün kendisine verilir; arayüzde alan hiç çizilmez ama
             * istek doğrudan da gelebilir.
             */
            'mtu' => [
                'nullable', 'integer', 'min:256', 'max:9216',
                Rule::prohibitedIf(fn (): bool => $this->uyePort()),
            ],

            /*
             * ae ÜYESİ portta anahtarlama ayarı YAZILAMAZ.
             *
             * Üye port kendi birimini kaybeder; cihaz "logical unit is not
             * allowed on aggregated links" diyor (bkz. .ai/rules/junos.md ve
             * LinkAggregationConfigurator'ın üye eklerken unit'i silmesi).
             * Panel bunu göndermeye kalkarsa commit check düşer ve
             * kullanıcıya ham cihaz hatası çıkar; istek buraya hiç
             * gelmemeli.
             */
            'port_mode' => [
                Rule::requiredIf(fn (): bool => ! $this->uyePort()),
                Rule::prohibitedIf(fn (): bool => $this->uyePort()),
                Rule::in([PortMode::Access->value, PortMode::Trunk->value, PortMode::Routed->value]),
            ],

            /*
             * ARAYÜZE BAĞLI FİREWALL FİLTRESİ.
             *
             * Bağlama okunuyordu ama yazılamıyordu: bir port anahtarlamaya
             * çevrilirken "family inet" ile birlikte oradaki
             * "filter input KORUMA" satırı da siliniyor ve panelden bir daha
             * kurulamıyordu. Cihazda doğrulandı — bağlama "family inet"
             * altında input ve output olarak yazılabiliyor; ethernet-switching
             * ailesinde bu biçim YOK.
             *
             * Bu yüzden yalnızca ROUTED modda sorulur.
             */
            'input_filter' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => ! $this->isRouted()),
                Rule::exists(SwitchFirewallFilter::class, 'name')
                    ->where('switch_id', $switchId)
                    ->where('family', FirewallFamily::Inet->value),
            ],
            'output_filter' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => ! $this->isRouted()),
                Rule::exists(SwitchFirewallFilter::class, 'name')
                    ->where('switch_id', $switchId)
                    ->where('family', FirewallFamily::Inet->value),
            ],

            /*
             * ADRES BURADAN DA VERİLEBİLİR.
             *
             * IP adresleri ekranı zaten var ama portu yönlendirmeye çevirip
             * sonra başka bir ekrana gitmek gereksiz bir adım. Buradan
             * verilen adres AYNI COMMIT'te yazılır: port bir an adressiz
             * kalmaz.
             *
             * Kural iki ekranda da AYNI yerden gelir
             * (SwitchAddressCandidateService::cidrFor): adres, Adresleme'de
             * bu switche atanmış bir bloğun içinde olmalı ve maske o bloktan
             * alınır.
             */
            'ip_address' => [
                'nullable', 'ip',
                Rule::prohibitedIf(fn (): bool => ! $this->isRouted()),
            ],

            // Boş = auto-negotiation. Cihazın kabul ettiği değerler dışına çıkılmaz.
            'speed_config' => ['nullable', Rule::in($this->allowedSpeeds())],

            /*
             * Duplex ("ether-options link-mode"). Boş bırakmak cihazın kendi
             * seçmesi demektir; satır silinir.
             *
             * HALF yalnızca 10m/100m ile geçerlidir — cihaz aksini
             * reddediyor: "Half duplex is an invalid configuration when speed
             * is 10G/1G/Auto" (jn32-test, 12.3R12.4). Kısıt withValidator'da.
             */
            'duplex_config' => ['nullable', Rule::in(array_keys(config('netconf.link_modes')))],

            /*
             * PoE donanımı olmayan porta PoE gönderilmesi reddedilir.
             * Arayüzde alan hiç çizilmiyor ama istek doğrudan da gelebilir;
             * sessizce yok saymak, kullanıcının gönderdiği değerin
             * kaydedildiğini sanmasına yol açar.
             */
            'is_poe_enabled' => [
                'boolean',
                Rule::prohibitedIf(fn (): bool => $this->route('port')?->is_poe_enabled === null),
            ],

            // Port VLAN'sız bırakılabilir; access portta en fazla bir VLAN.
            'vlans' => [
                // ae ÜYESİNDE üyelik olamaz; ayar ae arayüzünde yapılır.
                Rule::prohibitedIf(fn (): bool => $this->uyePort()),
                // YÖNLENDİRME modunda VLAN üyeliği olamaz: cihaz aynı
                // arayüzde iki aileyi kabul etmiyor.
                Rule::prohibitedIf(fn (): bool => $this->isRouted()),
                'array',
                ...($this->isAccess() ? ['max:1'] : []),
            ],
            // Yalnızca bu switchte tanımlı VLAN'lar atanabilir; değerler uuid.
            'vlans.*' => [
                'uuid',
                Rule::exists(Vlan::class, 'uuid')->where('switch_id', $switchId),
            ],

            'untagged_vlan' => [
                // Access portta tek üye zaten etiketsizdir, ayrıca sorulmaz.
                Rule::prohibitedIf(fn (): bool => $this->isAccess() || $this->isRouted()),
                'nullable', 'uuid',
                Rule::exists(Vlan::class, 'uuid')->where('switch_id', $switchId),
                Rule::in($this->input('vlans', [])),
            ],
        ];
    }

    /**
     * Tek alana bakarak ifade edilemeyen kurallar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardAddress($validator);
            $this->guardRoutedObjectRights($validator);
            $this->validateDuplex($validator);
        });
    }

    /**
     * Yönlendirme modunda adres ve filtre bağlaması, kendi ekranlarının
     * yetkisini ister.
     *
     * Bu form switch_ports yetkisiyle açılır ama routed modda cihaza IP adresi
     * ve firewall filtresi bağlaması da yazar; ikisinin kendi yetkisi var
     * (switch_ip_addresses, switch_firewall). Yetki yalnızca DEĞİŞİKLİK
     * olduğunda aranır: form mevcut adres ve filtreleri olduğu gibi geri
     * gönderir, yoksa yalnızca portu düzenleyen kullanıcı (ör. açıklama
     * değiştiren) kendi ekranında artık kayıt yapamazdı.
     */
    private function guardRoutedObjectRights(Validator $validator): void
    {
        if (! $this->isRouted()) {
            return;
        }

        $switch = $this->route('switch');
        $port = $this->route('port');
        $kullanici = $this->user();

        $adres = $this->string('ip_address')->toString();

        if ($adres !== '' && $port !== null
            && ! $port->ipAddresses->contains(fn ($kayit): bool => (string) $kayit->address === $adres)
            && $kullanici->cannot('updateSwitchIpAddresses', $switch)) {
            $validator->errors()->add('ip_address', 'IP adresi vermek için IP adresleri yetkisi gerekir.');
        }

        $bagli = $port === null ? [] : app(SwitchPortService::class)->boundFilters($switch, (string) $port->name);

        foreach (['input' => 'input_filter', 'output' => 'output_filter'] as $yon => $alan) {
            // Alan hiç gelmediyse cihazdaki bağlamaya dokunulmaz.
            if (! $this->has($alan)) {
                continue;
            }

            if ($this->string($alan)->toString() !== (string) ($bagli[$yon] ?? '')
                && $kullanici->cannot('updateSwitchFirewall', $switch)) {
                $validator->errors()->add($alan, 'Filtre bağlamak için firewall yetkisi gerekir.');
            }
        }
    }

    /**
     * Half-duplex yalnızca 10m/100m ile geçerlidir.
     *
     * Cihaz aksini reddediyor: "Half duplex is an invalid configuration when
     * speed is 10G/1G/Auto" (jn32-test, 12.3R12.4). Arayüzde de seçilemez
     * hâle getirilir; ikisi birlikte durur.
     */
    /**
     * Bu CİHAZA yazılabilen hızlar.
     *
     * Ekran zaten daraltıyor; sunucu da daraltmazsa elle gönderilen bir
     * değer cihaza gider ve kullanıcı "invalid value" görür. EX2300'de
     * 1000BASE-T zorla ayarlanamıyor (test4'te ölçüldü).
     *
     * Hiç ölçülmemiş cihazda liste daralmaz.
     *
     * @return list<string>
     */
    private function allowedSpeeds(): array
    {
        $switch = $this->route('switch');
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();
        $hizlar = array_map(strval(...), array_keys($lehce->speedValues()));

        /*
         * Kısıt PIC BAŞINA: yerleşik bakır port 1 Gbps'i zorla kabul etmezken
         * aynı cihazın SFP yuvası kabul ediyor (test4'te ölçüldü). Bu yüzden
         * soru cihaza değil, DÜZENLENEN PORTA sorulur.
         */
        $port = $this->route('port');

        $olculen = $port === null
            ? null
            : app(DeviceCapabilities::class)->speedsFor($switch, (string) $port->name);

        if ($olculen !== null) {
            $hizlar = array_values(array_intersect($hizlar, $olculen));
        }

        /*
         * Düzenlenen portta ZATEN yazılı olan hız her zaman geçerlidir.
         * Yoksa ölçüm daraldığında o port hiç kaydedilemez hâle gelirdi:
         * kullanıcı hıza dokunmasa bile form o değeri gönderir.
         */
        $mevcut = $port?->speed_config;

        if ($mevcut !== null && ! in_array((string) $mevcut, $hizlar, true)) {
            $hizlar[] = (string) $mevcut;
        }

        return $hizlar;
    }

    private function validateDuplex(Validator $validator): void
    {
        if ($this->string('duplex_config')->toString() !== 'half') {
            return;
        }

        $izinli = (array) config('netconf.half_duplex_speeds');

        if (! in_array($this->string('speed_config')->toString(), $izinli, true)) {
            $validator->errors()->add(
                'duplex_config',
                'Half-duplex yalnızca '.implode(' ya da ', $izinli).' Mbps hızla seçilebilir; '
                .'cihaz otomatik ve 1000 Mbps ile reddediyor.',
            );
        }
    }

    public function isAccess(): bool
    {
        return $this->string('port_mode')->toString() === PortMode::Access->value;
    }

    /**
     * Adres, Adresleme'de bu switche atanmış bir bloğun içinde olmalı ve
     * cihazda başka bir arayüzde durmamalı.
     *
     * Aynı iki kural IP adresleri ekranında da var ve İKİSİ DE aynı
     * kaynaktan geliyor; ayrı yazılsalardı biri sessizce ayrışırdı.
     */
    private function guardAddress(Validator $validator): void
    {
        if (! $this->isRouted() || ! $this->filled('ip_address') || $validator->errors()->has('ip_address')) {
            return;
        }

        $adres = $this->string('ip_address')->toString();
        $switch = $this->route('switch');

        if ($this->ipCidr() === null) {
            $validator->errors()->add(
                'ip_address',
                'Bu adres, Adresleme’de bu switche atanmış hiçbir bloğun içinde değil.',
            );

            return;
        }

        $baskaArayuzde = SwitchIpAddress::query()
            ->where('switch_id', $switch->id)
            ->where('address', $adres)
            ->where('interface_name', '!=', $this->route('port')?->name.'.0')
            ->exists();

        if ($baskaArayuzde) {
            $validator->errors()->add('ip_address', 'Bu adres cihazda başka bir arayüzde tanımlı.');
        }
    }

    /**
     * Buradan verilen adresin cihaza yazılacak hâli ("adres/maske").
     *
     * Maske sorulmaz; Adresleme'deki bloktan gelir — IP adresleri ekranıyla
     * aynı kural, aynı kaynak.
     */
    public function ipCidr(): ?string
    {
        if (! $this->isRouted() || ! $this->filled('ip_address')) {
            return null;
        }

        return app(SwitchAddressCandidateService::class)
            ->cidrFor($this->route('switch'), $this->string('ip_address')->toString());
    }

    /**
     * Port YÖNLENDİRME moduna alınıyor mu?
     *
     * Cihaz aynı arayüzde hem ethernet-switching hem inet ailesini kabul
     * etmiyor; bu yüzden yönlendirme modunda VLAN üyeliği sorulmaz, buna
     * karşılık firewall filtresi bağlanabilir.
     */
    public function isRouted(): bool
    {
        return $this->string('port_mode')->toString() === PortMode::Routed->value;
    }

    /**
     * Bu port bir toplama (ae) arayüzünün ÜYESİ mi?
     *
     * Üye portun kendi birimi, VLAN üyeliği ve MTU'su olamaz; hepsi ae
     * arayüzünde durur.
     */
    private function uyePort(): bool
    {
        return $this->route('port')?->aggregate_port_id !== null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'description' => 'açıklama',
            'enabled' => 'port durumu',
            'port_mode' => 'port modu',
            'speed_config' => 'hız',
            'duplex_config' => 'duplex',
            'vlans' => 'VLAN üyeliği',
            'untagged_vlan' => 'etiketsiz VLAN',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mtu.prohibited' => 'Bir ae’ye üye portta MTU ayarlanamaz; MTU ae arayüzünün kendisine verilir.',
            'vlans.max' => 'Access portta tek VLAN seçilebilir; birden fazlası için port modunu Trunk yapın.',
            'untagged_vlan.prohibited' => 'Access portta etiketsiz VLAN ayrıca seçilmez; seçilen VLAN zaten etiketsizdir.',
            'untagged_vlan.in' => 'Etiketsiz VLAN, seçilen VLAN’lardan biri olmalı.',
            'speed_config.in' => 'Cihaz yalnızca 10, 100 ve 1000 Mbps değerlerini kabul ediyor.',
            'is_poe_enabled.prohibited' => 'Bu portta PoE donanımı yok.',
        ];
    }
}
