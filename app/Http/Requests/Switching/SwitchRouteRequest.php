<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\RouteFlag;
use App\Models\AddressBlock;
use App\Models\SwitchRoute;
use App\Support\Net\Cidr;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak statik route.
 *
 * Protokol alınmaz: panelden yalnızca statik route yazılabilir. Direct, Local,
 * OSPF ve BGP yolları cihaz tarafından öğrenilir; yapılandırmada karşılıkları
 * yoktur ve değiştirilemezler.
 *
 * Hedef ağ TEK ALANDA gelir ("adres/maske"), prefix list girişinde olduğu
 * gibi: adres ve maske ayrı sorulursa kullanıcı ikisini uyumsuz
 * bırakabilir. Tek alan bu ihtimali kaldırır ve iki ekran aynı biçimi
 * kullanır.
 *
 * IPv4 ve IPv6 ikisi de yazılabilir. IPv6 statik route'lar cihazda
 * "routing-options rib inet6.0 static" altındadır; RIB adı hedefin
 * ailesinden türetilir, kullanıcıya sorulmaz.
 */
class SwitchRouteRequest extends FormRequest
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
        return [
            'cidr' => ['required', 'string', 'max:45'],

            /*
             * Yolun ne yapacağı AÇIKÇA sorulur.
             *
             * Boş bırakılan sonraki atlama sessizce "discard" sayılmaz:
             * öyle sayılsaydı kullanıcı alanı temizlediğinde route bir
             * blackhole'a döner ve bunu hiçbir yerde görmezdi. Seçim açıktır
             * ve cihazın desteklediği "reject" de yazılabilir.
             */
            'action' => ['required', Rule::in(['next-hop', 'discard', 'reject', 'receive'])],

            /*
             * Birden çok atlama = ECMP. Cihaz destekliyor (ölçüldü); panel
             * hepsini yazar, yoksa yedekli çıkışı olan route düzenlenemezdi.
             */
            'next_hops' => [
                Rule::requiredIf(fn (): bool => $this->action() === 'next-hop'),
                Rule::prohibitedIf(fn (): bool => $this->action() !== 'next-hop'),
                'array', 'max:16',
            ],
            'next_hops.*' => ['required', 'ip'],

            // Kendi preference/metric'i olan yedek atlamalar.
            'qualified_next_hops' => ['array', 'max:16'],
            'qualified_next_hops.*.name' => ['required', 'ip'],
            'qualified_next_hops.*.preference' => ['nullable', 'integer', 'between:0,4294967295'],
            'qualified_next_hops.*.metric' => ['nullable', 'integer', 'between:0,4294967295'],
            'qualified_next_hops.*.interface' => ['nullable', 'string', 'max:64'],

            'preference' => ['nullable', 'integer', 'between:0,4294967295'],
            'metric' => ['nullable', 'integer', 'between:0,4294967295'],
            'tag' => ['nullable', 'integer', 'between:0,4294967295'],
            'tag2' => ['nullable', 'integer', 'between:0,4294967295'],

            'flags' => ['array'],
            'flags.*' => [Rule::enum(RouteFlag::class)],

            'communities' => ['array', 'max:16'],
            // Junos community biçimi: "65000:100", "no-export" gibi adlar da geçerli.
            'communities.*' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9:._-]+$/'],

            'next_table' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    private function action(): string
    {
        return $this->string('action')->toString();
    }

    /**
     * Cihaza yazılacak ayarların tamamı.
     *
     * "residual_xml" düzenlenen kaydın KENDİSİNDEN gelir: panelin göstermediği
     * satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $route = $this->route('route');

        return [
            'rib' => $this->rib(),
            'next_hops' => $this->nextHops(),
            'qualified_next_hops' => array_values($this->input('qualified_next_hops', [])),
            'preference' => $this->integerOrNull('preference'),
            'metric' => $this->integerOrNull('metric'),
            'tag' => $this->integerOrNull('tag'),
            'tag2' => $this->integerOrNull('tag2'),
            'flags' => array_values($this->input('flags', [])),
            'communities' => array_values(array_filter($this->input('communities', []))),
            'next_table' => $this->string('next_table')->trim()->toString() ?: null,
            'residual_xml' => $route instanceof SwitchRoute ? $route->residual_xml : null,
        ];
    }

    /**
     * Hedefin ailesi RIB'i belirler. IPv6 statik route "rib inet6.0"
     * altındadır; kullanıcıya sorulmaz, hedeften türetilir.
     */
    private function rib(): ?string
    {
        $route = $this->route('route');

        // Cihazdan okunmuş özel bir RIB varsa (VRF) korunur.
        if ($route instanceof SwitchRoute && filled($route->rib) && $route->rib !== 'inet6.0') {
            return $route->rib;
        }

        return $this->parsed()?->isIpv6 ? 'inet6.0' : null;
    }

    /**
     * @return list<string>
     */
    private function nextHops(): array
    {
        return match ($this->action()) {
            'next-hop' => array_values(array_filter($this->input('next_hops', []))),
            'reject' => ['Reject'],
            'receive' => ['Receive'],
            default => ['Discard'],
        };
    }

    private function integerOrNull(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }

    /**
     * Envanterin "birincil atlama" alanı; ekran tek satırda bunu gösterir.
     */
    public function nextHop(): ?string
    {
        return $this->nextHops()[0] ?? null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('cidr')) {
                return;
            }

            $cidr = $this->parsed();

            if ($cidr === null) {
                $validator->errors()->add(
                    'cidr',
                    'Hedef ağ “adres/maske” biçiminde olmalı. Örnek: 198.18.21.0/24 ya da 2001:db8::/48',
                );

                return;
            }

            // Maskeye hizalı olmayan ağ sessizce düzeltilmez.
            if ($cidr->network !== $this->address()) {
                $validator->errors()->add(
                    'cidr',
                    "Adres maskeye hizalı değil; /{$cidr->prefixLength} için ağ adresi {$cidr->network} olmalı.",
                );

                return;
            }

            $alreadyExists = SwitchRoute::query()
                ->where('switch_id', $this->route('switch')->id)
                ->where('destination', $cidr->network)
                ->where('prefix_length', $cidr->prefixLength)
                ->when($this->route('route'), fn ($query, $route) => $query->whereKeyNot($route->getKey()))
                ->exists();

            if ($alreadyExists) {
                $validator->errors()->add('cidr', 'Bu route zaten tanımlı.');
            }

            $this->guardConflictingFlags($validator);
            $this->guardHopFamily($validator, $cidr->isIpv6);
            $this->guardAssignedToOtherDevice($validator, $cidr);
        });
    }

    /**
     * BAŞKA BİR CİHAZA BAĞLANMIŞ BLOK BURADAN ROUTE EDİLEMEZ.
     *
     * Bir blok bir switchin VLAN'ına, portuna ya da route'una bağlandığı anda
     * o aralık orada kullanılıyor demektir; ikinci bir cihazdan route etmek
     * trafiği ikiye bölen sessiz bir çakışmadır ve ancak sahada fark edilir.
     *
     * Açılır listede zaten görünmüyor (bkz. AddressCandidateService) ama alan
     * elle giriş de kabul ediyor: varsayılan route ve sağlayıcı özet ağları
     * envanterde durmaz. Asıl kural bu yüzden BURADA.
     *
     * Envanterde karşılığı OLMAYAN ağ serbesttir — kural yalnızca panelin
     * bildiği bloklar için geçerli; bilmediği bir ağ hakkında konuşamaz.
     */
    private function guardAssignedToOtherDevice(Validator $validator, Cidr $cidr): void
    {
        $switch = $this->route('switch');

        $block = AddressBlock::query()
            ->where('network', $cidr->network)
            ->where('prefix_length', $cidr->prefixLength)
            ->whereNotNull('connection_type')
            ->first();

        if ($block === null) {
            return;
        }

        $owner = $block->access_switch_id ?? $block->core_switch_id;

        if ($owner === null || $owner === $switch->getKey()) {
            return;
        }

        $block->loadMissing(['accessSwitch', 'coreSwitch']);
        $name = $block->accessSwitch?->hostname ?? $block->coreSwitch?->hostname ?? 'başka bir cihaz';

        $validator->errors()->add(
            'cidr',
            "{$block->cidr} zaten {$name} üzerinde kullanılıyor; aynı ağ ikinci bir cihazdan route edilemez.",
        );
    }

    /**
     * Birbirini dışlayan seçenekler. Cihaz zaten reddediyor ("Must not specify
     * resolve with retain" — test1'de ölçüldü); panel önce ve anlaşılır söyler.
     */
    private function guardConflictingFlags(Validator $validator): void
    {
        $selected = (array) $this->input('flags', []);
        $conflict = RouteFlag::conflicts();

        foreach ($selected as $flag) {
            foreach ($conflict[$flag] ?? [] as $opposite) {
                if (in_array($opposite, $selected, true)) {
                    $validator->errors()->add(
                        'flags',
                        "“{$flag}” ile “{$opposite}” birlikte kullanılamaz; cihaz bu ikisini reddediyor.",
                    );

                    return;
                }
            }
        }
    }

    /**
     * Sonraki atlamanın ailesi hedefin ailesiyle aynı olmalı: IPv6 hedefe
     * IPv4 atlama verilemez.
     */
    private function guardHopFamily(Validator $validator, bool $ipv6): void
    {
        $hops = array_merge(
            (array) $this->input('next_hops', []),
            array_column((array) $this->input('qualified_next_hops', []), 'name'),
        );

        foreach ($hops as $hop) {
            if (! is_string($hop) || trim($hop) === '') {
                continue;
            }

            if (str_contains($hop, ':') !== $ipv6) {
                $validator->errors()->add(
                    'next_hops',
                    "Sonraki atlama hedefle aynı aileden olmalı: {$hop}",
                );

                return;
            }
        }
    }

    public function destination(): string
    {
        return (string) $this->parsed()?->network;
    }

    public function prefixLength(): int
    {
        return (int) $this->parsed()?->prefixLength;
    }

    /** Cihaza yazılan biçim: adres/maske. */
    public function cidr(): string
    {
        return $this->destination().'/'.$this->prefixLength();
    }

    private function parsed(): ?Cidr
    {
        return Cidr::tryParse($this->string('cidr')->trim()->toString());
    }

    /** Kullanıcının yazdığı adres kısmı; hizalama karşılaştırması için. */
    private function address(): string
    {
        return trim(explode('/', $this->string('cidr')->trim()->toString())[0]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cidr' => 'hedef ağ',
            'next_hop' => 'sonraki atlama',
            'next_hops' => 'sonraki atlamalar',
            'qualified_next_hops' => 'yedek atlamalar',
            'flags' => 'seçenekler',
            'communities' => 'community',
            'next_table' => 'sonraki tablo',
        ];
    }
}
