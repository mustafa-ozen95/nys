<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallPolicer;
use App\Models\SwitchPrefixList;
use App\Models\SwitchRoutingInstance;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Firewall filtresinin bir terimi.
 *
 * Doğrulama BİÇİM düzeyindedir. Junos "protokolsüz port", "protokolsüz
 * icmp-type" ve "eylemsiz terim" gibi kullanımları KABUL EDİYOR (cihazda
 * denendi); panel cihazda olmayan bir kısıt uydurmaz. Yönlendirme formdaki
 * açıklamalarla yapılır, reddederek değil.
 *
 * Uydurma OLMAYAN iki kısıt vardır ve ikisi de cihazın kendi kuralıdır:
 *
 *   - AİLESİ UYUŞMAYAN koşul: "next-header" yalnızca inet6'da, "ether-type"
 *     ve MAC adresleri yalnızca ethernet-switching'de, IPv4 başlık alanları
 *     (precedence, ip-options, parça bayrakları) yalnızca inet'te bulunur.
 *   - ÇAKIŞAN seçenekler: tcp-flags ile tcp-initial/tcp-established,
 *     is-fragment ile first-fragment, "next term" ile sonlandırıcı eylem.
 *
 * Atıf yapılan prefix list ve policer cihazda TANIMLI OLMALIDIR — tanımsız
 * ada atıf commit'i düşürür.
 *
 * NOT: "reject <kod>" bu platform/sürümde YOKTUR (test1'de ölçüldü, "syntax
 * error"); yalnızca sade reject vardır ve o yüzden kod alanı da yoktur.
 */
class FirewallTermRequest extends FormRequest
{
    /**
     * Junos'un yaygın protokol adları. Sayısal değer de kabul edilir.
     *
     * @var list<string>
     */
    public const PROTOCOLS = ['tcp', 'udp', 'icmp', 'icmp6', 'ospf', 'gre', 'esp', 'ah', 'pim', 'vrrp'];

    /**
     * @var list<string>
     */
    public const ICMP_TYPES = [
        'echo-request', 'echo-reply', 'unreachable', 'time-exceeded',
        'source-quench', 'redirect', 'parameter-problem', 'router-advertisement',
    ];

    /**
     * IPv6'da protokolün karşılığı "next-header"dır.
     *
     * @var list<string>
     */
    public const NEXT_HEADERS = ['tcp', 'udp', 'icmp6', 'ospf', 'esp', 'ah', 'ipv6-frag', 'no-next-header'];

    /**
     * @var list<string>
     */
    public const IP_OPTIONS = [
        'any', 'loose-source-route', 'route-record', 'router-alert',
        'security', 'stream-id', 'strict-source-route', 'timestamp',
    ];

    /**
     * @var list<string>
     */
    public const FRAGMENT_FLAGS = ['dont-fragment', 'more-fragments', 'reserved'];

    /**
     * @var list<string>
     */
    public const PRECEDENCES = [
        'critical-ecp', 'flash', 'flash-override', 'immediate',
        'internet-control', 'net-control', 'priority', 'routine',
    ];

    /**
     * @var list<string>
     */
    public const ETHER_TYPES = ['arp', 'ipv4', 'ipv6', 'mpls', 'oam', 'ppp', 'pppoe'];

    /**
     * Junos'un varsayılan iletim sınıfları.
     *
     * @var list<string>
     */
    public const FORWARDING_CLASSES = [
        'assured-forwarding', 'best-effort', 'expedited-forwarding', 'network-control',
    ];

    /**
     * @var list<string>
     */
    public const LOSS_PRIORITIES = ['low', 'medium-low', 'medium-high', 'high'];

    /**
     * @var list<string>
     */
    public const DSCP_VALUES = [
        'be', 'ef', 'af11', 'af12', 'af13', 'af21', 'af22', 'af23',
        'af31', 'af32', 'af33', 'af41', 'af42', 'af43',
        'cs1', 'cs2', 'cs3', 'cs4', 'cs5', 'cs6', 'cs7', 'nc1', 'nc2',
    ];

    /**
     * Yalnızca inet6 filtresinde bulunan koşullar.
     *
     * @var list<string>
     */
    private const INET6_ONLY = ['next_headers', 'traffic_classes'];

    /**
     * Yalnızca ethernet-switching filtresinde bulunan koşullar.
     *
     * @var list<string>
     */
    private const ETHERNET_ONLY = ['ether_types', 'source_mac_addresses', 'destination_mac_addresses'];

    /**
     * Yalnızca IPv4 başlığında bulunan koşullar.
     *
     * @var list<string>
     */
    private const INET_ONLY = ['precedences', 'ip_options', 'fragment_flags', 'is_fragment', 'is_first_fragment'];

    /**
     * Formdan "her satıra bir tane" biçiminde gelen liste alanları.
     *
     * @var list<string>
     */
    private const LIST_FIELDS = [
        'source_addresses', 'destination_addresses',
        'source_prefix_lists', 'source_prefix_list_excepts',
        'destination_prefix_lists', 'destination_prefix_list_excepts',
        'protocols', 'next_headers', 'ports', 'source_ports', 'destination_ports',
        'icmp_types', 'icmp_codes', 'dscps', 'traffic_classes', 'precedences',
        'packet_lengths', 'ttls', 'ip_options', 'fragment_flags', 'interfaces',
        'ether_types', 'source_mac_addresses', 'destination_mac_addresses',
    ];

    /**
     * Cihaza gönderilen boolean alanlar.
     *
     * @var list<string>
     */
    private const FLAG_FIELDS = [
        'is_tcp_initial', 'is_tcp_established', 'is_fragment', 'is_first_fragment',
        'goes_to_next_term', 'is_port_mirrored', 'is_sampled', 'is_syslogged', 'is_logged',
    ];

    /**
     * Cihaza gönderilen tekil metin alanları.
     *
     * @var list<string>
     */
    private const TEXT_FIELDS = [
        'tcp_flags', 'routing_instance', 'policer', 'forwarding_class',
        'loss_priority', 'dscp', 'analyzer', 'count_name',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Filtrenin ailesi. Terim uçlarında rotadaki filtreden gelir; yeni filtre
     * yaratılırken formdan (bkz. FirewallFilterRequest).
     */
    public function family(): FirewallFamily
    {
        $filter = $this->route('firewallFilter');

        return $filter instanceof SwitchFirewallFilter ? $filter->family : FirewallFamily::Inet;
    }

    protected function prepareForValidation(): void
    {
        $temiz = [];

        foreach (self::LIST_FIELDS as $alan) {
            // Formdan boş satır ve boş seçenek gelir; exists/ip kurallarına takılırdı.
            $deger = $this->input($alan, []);
            $deger = is_string($deger) ? preg_split('/[\r\n,]+/', $deger) ?: [] : (array) $deger;

            $temiz[$alan] = array_values(array_filter(
                array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $deger),
                static fn (string $v): bool => $v !== '',
            ));
        }

        $this->merge($temiz);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/'],

            /*
             * Terimin kaçıncı sırada değerlendirileceği. Junos'ta İLK EŞLEŞEN
             * terim kazanır; sıra bu yüzden bir tercih değil, trafiğin
             * kendisidir. Boş bırakılırsa sona eklenir.
             */
            'position' => ['nullable', 'integer', 'min:0', 'max:255'],

            ...$this->matchRules(),
            ...$this->actionRules(),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function matchRules(): array
    {
        $switchId = $this->route('switch')->id;
        $prefixList = Rule::exists(SwitchPrefixList::class, 'name')->where('switch_id', $switchId);

        return [
            // Adresler CIDR olarak yazılır; tek adres için /32 verilir.
            'source_addresses' => ['array'],
            'source_addresses.*' => ['string', 'max:45'],
            'destination_addresses' => ['array'],
            'destination_addresses.*' => ['string', 'max:45'],

            'source_prefix_lists' => ['array'],
            'source_prefix_lists.*' => [$prefixList],
            'destination_prefix_lists' => ['array'],
            'destination_prefix_lists.*' => [$prefixList],

            /*
             * "except" koşulu TERSİNE çevirir ve yalnızca seçili listeler için
             * işaretlenebilir; listede olmayan bir ada except demek sessizce
             * yok sayılırdı.
             */
            'source_prefix_list_excepts' => ['array'],
            'source_prefix_list_excepts.*' => [Rule::in($this->list('source_prefix_lists'))],
            'destination_prefix_list_excepts' => ['array'],
            'destination_prefix_list_excepts.*' => [Rule::in($this->list('destination_prefix_lists'))],

            'protocols' => ['array'],
            'protocols.*' => ['string', 'max:10'],

            'ports' => ['array'],
            'ports.*' => ['integer', 'min:1', 'max:65535'],
            'source_ports' => ['array'],
            'source_ports.*' => ['integer', 'min:1', 'max:65535'],
            'destination_ports' => ['array'],
            'destination_ports.*' => ['integer', 'min:1', 'max:65535'],

            'icmp_types' => ['array'],
            'icmp_types.*' => [Rule::in(self::ICMP_TYPES)],
            'icmp_codes' => ['array'],
            'icmp_codes.*' => ['string', 'max:32'],

            /*
             * TCP bayrakları ifade olarak yazılabilir: "syn", "!ack",
             * "(syn & !ack)". Cihaz ifadeyi kendisi çözer.
             */
            'tcp_flags' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9 ()!&|+,._-]+$/'],
            'is_tcp_initial' => ['boolean'],
            'is_tcp_established' => ['boolean'],

            'dscps' => ['array'],
            'dscps.*' => ['string', 'max:16'],
            'packet_lengths' => ['array'],
            'packet_lengths.*' => ['string', 'max:16', 'regex:/^\d{1,5}(-\d{1,5})?$/'],
            // ttl 0..255; aralık da yazılabilir (ör. "10-20"; cihaz kabul ediyor).
            'ttls' => ['array'],
            'ttls.*' => ['string', 'max:8', 'regex:/^\d{1,3}(-\d{1,3})?$/'],
            'interfaces' => ['array'],
            'interfaces.*' => ['string', 'max:64'],

            /*
             * Ailesi uyuşmayan koşullar burada BİÇİM olarak doğrulanır;
             * "hangi ailede kullanılabilir" kuralı withValidator'dadır.
             * Kural listesindeki "prohibited" işaretsiz kutuyu da reddederdi:
             * her kutunun önünde aynı adı taşıyan gizli bir "0" durur ve "0"
             * boş sayılmaz.
             */
            'precedences' => ['array'],
            'precedences.*' => [Rule::in(self::PRECEDENCES)],
            'ip_options' => ['array'],
            'ip_options.*' => [Rule::in(self::IP_OPTIONS)],
            'fragment_flags' => ['array'],
            'fragment_flags.*' => [Rule::in(self::FRAGMENT_FLAGS)],
            'is_fragment' => ['boolean'],
            'is_first_fragment' => ['boolean'],

            'next_headers' => ['array'],
            'next_headers.*' => ['string', 'max:20'],
            'traffic_classes' => ['array'],
            'traffic_classes.*' => ['string', 'max:16'],

            'ether_types' => ['array'],
            'ether_types.*' => ['string', 'max:16'],
            'source_mac_addresses' => ['array'],
            'source_mac_addresses.*' => ['string', 'max:32'],
            'destination_mac_addresses' => ['array'],
            'destination_mac_addresses.*' => ['string', 'max:32'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function actionRules(): array
    {
        return [
            'action' => ['nullable', Rule::enum(FirewallAction::class)],
            'goes_to_next_term' => ['boolean'],

            // Tanımsız policer'a atıf commit'i düşürür.
            'policer' => [
                'nullable', 'string', 'max:63',
                Rule::exists(SwitchFirewallPolicer::class, 'name')
                    ->where('switch_id', $this->route('switch')->id),
            ],

            /*
             * Tablo CİHAZDA TANIMLI olmalı. Panel tabloları da modelliyor
             * (switch_routing_instances); olmayan bir ada atıf yapan terim
             * commit check'te "Routing instance … not defined" ile düşerdi ve
             * kullanıcı hatayı ancak cihaz reddedince görürdü.
             */
            'routing_instance' => [
                'nullable', 'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::exists(SwitchRoutingInstance::class, 'name')
                    ->where('switch_id', $this->route('switch')->id),
            ],
            'forwarding_class' => ['nullable', 'string', 'max:63'],
            'loss_priority' => ['nullable', Rule::in(self::LOSS_PRIORITIES)],
            'dscp' => ['nullable', 'string', 'max:16'],
            'analyzer' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/'],
            'is_port_mirrored' => ['boolean'],
            'is_sampled' => ['boolean'],
            'is_syslogged' => ['boolean'],

            'count_name' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/'],
            'is_logged' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardFamily($validator);

            // Cihaz "tcp-flags" ile kısayolları birlikte kabul etmez.
            if ($this->filled('tcp_flags') && ($this->boolean('is_tcp_initial') || $this->boolean('is_tcp_established'))) {
                $validator->errors()->add(
                    'tcp_flags',
                    'TCP bayrak ifadesi ile “ilk paket”/“kurulu bağlantı” kısayolları birlikte kullanılamaz.',
                );
            }

            if ($this->boolean('is_tcp_initial') && $this->boolean('is_tcp_established')) {
                $validator->errors()->add(
                    'is_tcp_established',
                    '“İlk paket” ile “kurulu bağlantı” aynı anda seçilemez; ikisi birbirini dışlar.',
                );
            }

            if ($this->boolean('is_fragment') && $this->boolean('is_first_fragment')) {
                $validator->errors()->add(
                    'is_first_fragment',
                    '“Parçalı” ile “ilk parça” aynı anda seçilemez.',
                );
            }

            /*
             * "next term" zinciri bitirmez, sıradaki terime geçer; accept,
             * discard ve reject ise bitirir. Cihaz ikisini birlikte reddeder.
             */
            if ($this->boolean('goes_to_next_term') && $this->filled('action')) {
                $validator->errors()->add(
                    'goes_to_next_term',
                    '“Sonraki terime geç” ile bir sonuç (kabul/düşür/reddet) birlikte seçilemez.',
                );
            }
        });
    }

    /**
     * Ailesi uyuşmayan koşulu REDDEDER.
     *
     * Sessizce yok saymak, kullanıcının yazdığı koşulun kaydedildiğini
     * sanmasına yol açardı — bir firewall filtresinde bunun bedeli kesinti
     * ya da açık kapıdır. Cihaz da bu koşulları kabul etmez.
     */
    private function guardFamily(Validator $validator): void
    {
        $kapilar = [
            [self::INET6_ONLY, FirewallFamily::Inet6],
            [self::ETHERNET_ONLY, FirewallFamily::EthernetSwitching],
            [self::INET_ONLY, FirewallFamily::Inet],
        ];

        foreach ($kapilar as [$alanlar, $aile]) {
            if ($this->family() === $aile) {
                continue;
            }

            foreach ($alanlar as $alan) {
                if ($this->hasValue($alan)) {
                    $validator->errors()->add(
                        $alan,
                        "Bu koşul yalnızca {$aile->label()} filtresinde kullanılabilir; "
                            ."bu filtre {$this->family()->label()}.",
                    );
                }
            }
        }
    }

    /**
     * Alan gerçekten dolduruldu mu? Onay kutusu ile liste ayrı bakılır:
     * işaretsiz kutu "0" gönderir ve "boş" sayılmaz.
     */
    private function hasValue(string $field): bool
    {
        return in_array($field, self::FLAG_FIELDS, true)
            ? $this->boolean($field)
            : $this->list($field) !== [];
    }

    /**
     * FirewallConfigurator'ın beklediği biçim.
     *
     * "residual_xml" düzenlenen terimin KENDİSİNDEN gelir: panelin
     * göstermediği satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    public function termAttributes(): array
    {
        $action = $this->string('action')->toString();
        $term = $this->route('term');

        $attributes = [
            'name' => $this->string('name')->toString(),
            'action' => $action === '' ? null : FirewallAction::from($action),
        ];

        foreach (self::LIST_FIELDS as $alan) {
            $attributes[$alan] = $this->list($alan);
        }

        foreach (self::FLAG_FIELDS as $alan) {
            $attributes[$alan] = $this->boolean($alan);
        }

        foreach (self::TEXT_FIELDS as $alan) {
            $attributes[$alan] = $this->string($alan)->trim()->toString() ?: null;
        }

        return [...$attributes, ...$this->preserved($term)];
    }

    /**
     * Panelin göstermediği ama KORUNAN ham satırlar.
     *
     * @return array<string, mixed>
     */
    private function preserved(mixed $term): array
    {
        return [
            'residual_xml' => $term?->residual_xml,
            'from_residual_xml' => $term?->from_residual_xml,
            'then_residual_xml' => $term?->then_residual_xml,
        ];
    }

    /**
     * Terimin ekleneceği sıra; verilmezse listenin sonu.
     */
    public function positionOr(int $default): int
    {
        return filled($this->input('position')) ? $this->integer('position') : $default;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return array_values(array_filter(
            (array) $this->input($key, []),
            static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'terim adı',
            'source_addresses' => 'kaynak adresler',
            'destination_addresses' => 'hedef adresler',
            'source_prefix_lists' => 'kaynak prefix listeler',
            'destination_prefix_lists' => 'hedef prefix listeler',
            'protocols' => 'protokoller',
            'next_headers' => 'sonraki başlıklar',
            'ports' => 'portlar',
            'icmp_types' => 'ICMP türleri',
            'icmp_codes' => 'ICMP kodları',
            'tcp_flags' => 'TCP bayrakları',
            'dscps' => 'DSCP değerleri',
            'traffic_classes' => 'trafik sınıfları',
            'precedences' => 'öncelikler',
            'packet_lengths' => 'paket boyları',
            'ttls' => 'ttl değerleri',
            'ip_options' => 'IP seçenekleri',
            'fragment_flags' => 'parça bayrakları',
            'interfaces' => 'arayüzler',
            'ether_types' => 'ether türleri',
            'source_mac_addresses' => 'kaynak MAC adresleri',
            'destination_mac_addresses' => 'hedef MAC adresleri',
            'position' => 'sıra',
            'action' => 'sonuç',
            'goes_to_next_term' => 'sonraki terime geç',
            'policer' => 'policer',
            'forwarding_class' => 'iletim sınıfı',
            'loss_priority' => 'kayıp önceliği',
            'routing_instance' => 'yönlendirme tablosu',
            'analyzer' => 'analyzer',
            'count_name' => 'sayaç adı',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'routing_instance.exists' => 'Bu switchte böyle bir yönlendirme tablosu yok.',
            'source_prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'destination_prefix_lists.*.exists' => 'Bu prefix list cihazda tanımlı değil.',
            'policer.exists' => 'Bu policer cihazda tanımlı değil; önce policer olarak ekleyin.',
        ];
    }
}
