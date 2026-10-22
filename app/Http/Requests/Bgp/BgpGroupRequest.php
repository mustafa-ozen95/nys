<?php

declare(strict_types=1);

namespace App\Http\Requests\Bgp;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\BgpNlri;
use App\Enums\IpFamily;
use App\Models\BgpGroup;
use App\Models\SwitchPolicyStatement;
use App\Rules\HoldTime;
use App\Support\Net\Cidr;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak BGP peer grubu.
 *
 * Buradaki kısıtların hepsi gerçek cihazda denenerek doğrulandı; arayüzde
 * gizlenen her alan burada da reddedilir (bkz. .ai/rules/junos.md,
 * "Arayüzdeki her kısıt sunucuda da vardır"):
 *
 * - AS numarası tanımlı değilken hiçbir grup commit edilemez: cihaz
 *   "must define local autonomous system when enabling BGP" der.
 * - import/export politikası cihazda ÖNCEDEN tanımlı olmalıdır; tanımsız ada
 *   atıf "Policy error: Policy X referenced but not defined" ile reddedilir.
 *   Bu yüzden liste serbest metin değil, cihazdan okunan politikalardır.
 * - peer-as yalnızca external grupta anlamlıdır; internal grupta karşı taraf
 *   zaten kendi AS'imizdir.
 * - teardown ve idle-timeout, prefix sınırı olmadan yazılamaz.
 * - idle-timeout "forever" ile dakikalı hâli aynı anda verilemez.
 */
class BgpGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Kapalı aileler hiç gönderilmemiş sayılır; formdan işaretsiz kutu
     * gelmediği için eksik anahtarlar normalleştirilir.
     */
    protected function prepareForValidation(): void
    {
        $families = [];

        foreach ((array) $this->input('families', []) as $key => $family) {
            if (! is_array($family) || (bool) ($family['enabled'] ?? false) === false) {
                continue;
            }

            $families[$key] = $family;
        }

        /*
         * Dinamik komşu ağları çok satırlı bir kutuya yazılır; doğrulama ve
         * cihaza yazma liste bekler.
         */
        $this->merge([
            'families' => $families,
            'allow_prefixes' => $this->allowPrefixInput(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowPrefixInput(): array
    {
        $raw = $this->input('allow_prefixes');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw), 'filled'));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) $raw) ?: []),
            'filled',
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switch = $this->route('switch');
        $external = $this->input('type') === BgpGroupType::External->value;

        return [
            /*
             * Junos tanımlayıcısı: harfle başlar, harf/rakam/nokta/tire/alt
             * çizgi ile devam eder. Ad cihazda grubun KİMLİĞİDİR ve bu yüzden
             * değiştirilemez; düzenlemede alan gönderilse bile yok sayılır.
             */
            'name' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(BgpGroup::class, 'name')
                    ->where('switch_id', $switch->id)
                    ->ignore($this->route('bgpGroup')),
            ],

            'type' => ['required', Rule::enum(BgpGroupType::class)],

            // eBGP karşı tarafın AS'i olmadan kurulamaz.
            'peer_asn' => [
                $external ? 'required' : 'nullable',
                'numeric', 'min:1', 'max:4294967295',
            ],
            'local_asn' => ['nullable', 'numeric', 'min:1', 'max:4294967295'],
            'local_address' => ['nullable', 'ip'],

            /*
             * MD5 oturum kimliği. Boş bırakılırsa kayıtlı anahtar KORUNUR;
             * silmek ayrı bir kutu ister — "boş bıraktım, dokunma" ile
             * "boş bıraktım, silinsin" karıştırılmamalı.
             */
            'authentication_key' => [
                'nullable', 'string', 'max:126',
                'prohibited_if:clear_authentication_key,1',
            ],
            'clear_authentication_key' => ['boolean'],

            // Junos: 0 (süre işletilmez) ya da 3-65535. 1 ve 2 reddedilir.
            'hold_time' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // Route reflector küme kimliği; iBGP grupta anlamlıdır.
            'cluster' => ['nullable', 'ipv4'],

            /*
             * Gönderilmezse "cihazın genel ayarı" varsayılır: o durumda
             * satır hiç yazılmaz. Zorunlu yapılsaydı, alanı taşımayan bir
             * istek grubu hiç kuramazdı.
             */
            'graceful_restart' => ['nullable', Rule::enum(BgpGracefulRestart::class)],

            'remove_private' => ['boolean'],
            'as_override' => ['boolean'],
            'advertise_inactive' => ['boolean'],

            /*
             * Cihazda "deactivate": yapılandırma durur ama çalışmaz. Alan
             * ayrı taşınmazsa devre dışı grup panelde etkin görünürdü.
             */
            'is_inactive' => ['boolean'],
            'multipath' => ['boolean'],
            'damping' => ['boolean'],

            'out_delay' => ['nullable', 'integer', 'min:1', 'max:65535'],

            // BFD en küçük aralık (ms). Junos alt sınırı 1 ms'tir.
            'bfd_minimum_interval' => ['nullable', 'integer', 'min:1', 'max:255000'],

            'allow_prefixes' => ['array', 'max:32'],
            'allow_prefixes.*' => ['string', 'max:45'],

            'multihop' => ['boolean'],
            'multihop_ttl' => [
                'nullable', 'integer', 'min:1', 'max:255',
                Rule::prohibitedIf(fn (): bool => ! $this->boolean('multihop')),
            ],

            'log_updown' => ['boolean'],
            'passive' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],

            'import_policies' => ['array'],
            'import_policies.*' => [$this->policyRule($switch->id)],
            'export_policies' => ['array'],
            'export_policies.*' => [$this->policyRule($switch->id)],

            'families' => ['array'],
            'families.*.nlri' => ['required', Rule::enum(BgpNlri::class)],
            'families.*.prefix_limit_maximum' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'families.*.teardown' => ['boolean'],
            'families.*.teardown_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'families.*.idle_timeout_forever' => ['boolean'],
            'families.*.idle_timeout_minutes' => ['nullable', 'integer', 'min:1', 'max:2400'],
        ];
    }

    /**
     * Tek alana bakarak ifade edilemeyen kurallar.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateAutonomousSystem($validator);
            $this->validateFamilies($validator);
            $this->validateAllowPrefixes($validator);
            $this->validateDynamicNeighborAuthentication($validator);
            HoldTime::validate($validator, $this->input('hold_time'));
        });
    }

    /**
     * Cihaz, AS numarası tanımlı değilken BGP'yi commit etmiyor. Grubu
     * yazmaya çalışmak yerine kullanıcıya ne yapması gerektiği söylenir.
     */
    private function validateAutonomousSystem(Validator $validator): void
    {
        if ($this->route('switch')->autonomous_system === null) {
            $validator->errors()->add(
                'name',
                'Önce switchin AS numarası tanımlanmalı; cihaz AS numarası olmadan BGP’yi kabul etmiyor.',
            );
        }
    }

    /**
     * Dinamik komşu ile MD5 anahtarı BİRLİKTE olmaz.
     *
     * Cihaz reddediyor: "May not be configured with authentication-key"
     * (jn32-test, 12.3R12.4, commit check ile doğrulandı). Mantığı da açık:
     * anahtar komşu başına paylaşılır, dinamik komşuda ise karşı tarafın kim
     * olacağı önceden belli değildir.
     *
     * Kural burada durmasaydı kullanıcı formu doldurup kaydeder, commit
     * check'te reddedilir ve neden reddedildiğini panelden anlayamazdı.
     */
    private function validateDynamicNeighborAuthentication(Validator $validator): void
    {
        if ($this->input('allow_prefixes', []) === [] || $this->authenticationKey() === null) {
            return;
        }

        $validator->errors()->add(
            'allow_prefixes',
            'Dinamik komşu ile MD5 oturum anahtarı birlikte kullanılamaz; cihaz reddediyor. '
            .'Anahtar gerekiyorsa komşular tek tek tanımlanmalı.',
        );
    }

    /**
     * Dinamik komşu ağı "adres/maske" olmalı ve maskeye hizalı durmalı;
     * hizasız değer cihazda sessizce başka bir ağa dönüşürdü.
     */
    private function validateAllowPrefixes(Validator $validator): void
    {
        foreach ((array) $this->input('allow_prefixes', []) as $index => $prefix) {
            $cidr = Cidr::tryParse((string) $prefix);

            if ($cidr === null) {
                $validator->errors()->add(
                    "allow_prefixes.{$index}",
                    "“{$prefix}” geçerli bir ağ değil. Örnek: 10.0.0.0/24",
                );

                continue;
            }

            if ($cidr->network !== trim(explode('/', (string) $prefix)[0])) {
                $validator->errors()->add(
                    "allow_prefixes.{$index}",
                    "“{$prefix}” maskeye hizalı değil; ağ adresi {$cidr->network} olmalı.",
                );
            }
        }
    }

    private function validateFamilies(Validator $validator): void
    {
        foreach ((array) $this->input('families', []) as $key => $family) {
            $alan = "families.{$key}";

            // Anahtar aile adıdır; tanınmayan bir aile sessizce atılmaz.
            if (IpFamily::tryFrom((string) $key) === null) {
                $validator->errors()->add($alan, 'Tanınmayan adres ailesi.');

                continue;
            }

            $limit = $family['prefix_limit_maximum'] ?? null;
            $teardown = (bool) ($family['teardown'] ?? false);

            // Sınır yoksa "sınırda düşür" diye bir şey de olamaz.
            if ($teardown && ($limit === null || $limit === '')) {
                $validator->errors()->add(
                    "{$alan}.teardown",
                    'Oturumu düşürmek için önce bir prefix sınırı verilmeli.',
                );
            }

            if (! $teardown) {
                foreach (['teardown_threshold', 'idle_timeout_forever', 'idle_timeout_minutes'] as $bound) {
                    if (filled($family[$bound] ?? null)) {
                        $validator->errors()->add("{$alan}.{$bound}", 'Bu alan yalnızca oturum düşürme açıkken verilebilir.');
                    }
                }
            }

            // Junos ikisini birden yazamaz: ya süresiz kapalı kalır ya da
            // verilen dakika sonunda yeniden dener.
            if (($family['idle_timeout_forever'] ?? false) && filled($family['idle_timeout_minutes'] ?? null)) {
                $validator->errors()->add(
                    "{$alan}.idle_timeout_minutes",
                    'Süresiz bekleme seçiliyken ayrıca dakika verilemez.',
                );
            }
        }
    }

    /**
     * Politika adı cihazda tanımlı olmalı. Serbest metin kabul edilseydi
     * commit "referenced but not defined" ile düşerdi.
     */
    private function policyRule(int $switchId): mixed
    {
        return Rule::exists(SwitchPolicyStatement::class, 'name')->where('switch_id', $switchId);
    }

    /**
     * BgpConfigurator'ın beklediği biçim.
     *
     * @return array<string, mixed>
     */
    public function deviceAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'type' => BgpGroupType::from($this->string('type')->toString()),
            'peer_asn' => $this->filledString('peer_asn'),
            'local_asn' => $this->filledString('local_asn'),
            'local_address' => $this->filledString('local_address'),
            'authentication_key' => $this->authenticationKey(),
            'hold_time' => $this->integerOrNull('hold_time'),
            'cluster' => $this->filledString('cluster'),
            'graceful_restart' => BgpGracefulRestart::tryFrom($this->string('graceful_restart')->toString())
                ?? BgpGracefulRestart::Inherit,
            'remove_private' => $this->boolean('remove_private'),
            'as_override' => $this->boolean('as_override'),
            'advertise_inactive' => $this->boolean('advertise_inactive'),
            /*
             * Alan GÖNDERİLMEDİYSE karar yok demektir, "etkin olsun" değil.
             * boolean() eksik girdiyi false'a çevirir; alanı taşımayan bir istek
             * cihazda devre dışı duran bir nesneyi sessizce AÇARDI. null geçilince
             * öznitelik hiç yazılmaz ve merge cihazdaki durumu korur.
             */
            'is_inactive' => $this->has('is_inactive') ? $this->boolean('is_inactive') : null,
            'multipath' => $this->boolean('multipath'),
            'damping' => $this->boolean('damping'),
            'out_delay' => $this->integerOrNull('out_delay'),
            'bfd_minimum_interval' => $this->integerOrNull('bfd_minimum_interval'),
            'allow_prefixes' => array_values((array) $this->input('allow_prefixes', [])),
            'multihop' => $this->boolean('multihop'),
            'multihop_ttl' => $this->boolean('multihop') ? $this->integerOrNull('multihop_ttl') : null,
            'log_updown' => $this->boolean('log_updown'),
            'passive' => $this->boolean('passive'),
            'import_policies' => array_values((array) $this->input('import_policies', [])),
            'export_policies' => array_values((array) $this->input('export_policies', [])),
            'description' => $this->filledString('description'),
            'families' => $this->familyAttributes(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function familyAttributes(): array
    {
        $families = [];

        foreach ((array) $this->input('families', []) as $key => $family) {
            $teardown = (bool) ($family['teardown'] ?? false);

            $families[] = [
                'family' => IpFamily::from((string) $key),
                'nlri' => BgpNlri::from((string) $family['nlri']),
                'prefix_limit_maximum' => filled($family['prefix_limit_maximum'] ?? null)
                    ? (int) $family['prefix_limit_maximum']
                    : null,
                'teardown' => $teardown,
                'teardown_threshold' => $teardown && filled($family['teardown_threshold'] ?? null)
                    ? (int) $family['teardown_threshold']
                    : null,
                'idle_timeout_forever' => $teardown && (bool) ($family['idle_timeout_forever'] ?? false),
                'idle_timeout_minutes' => $teardown && filled($family['idle_timeout_minutes'] ?? null)
                    ? (int) $family['idle_timeout_minutes']
                    : null,
            ];
        }

        return $families;
    }

    /**
     * Cihaza gidecek oturum anahtarı.
     *
     * Boş bırakmak "dokunma" demektir: kayıtlı değer geri yazılır. Kayıtlı
     * değer cihazdan okunmuş "$9$..." biçimidir ve Junos onu kabul eder.
     * Silmek ayrı kutuyla istenir.
     */
    private function authenticationKey(): ?string
    {
        if ($this->boolean('clear_authentication_key')) {
            return null;
        }

        $group = $this->route('bgpGroup');

        return $this->filledString('authentication_key')
            ?? ($group instanceof BgpGroup ? $group->authentication_key : null);
    }

    private function filledString(string $key): ?string
    {
        return $this->string($key)->toString() ?: null;
    }

    private function integerOrNull(string $key): ?int
    {
        return filled($this->input($key)) ? $this->integer($key) : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'grup adı',
            'type' => 'tür',
            'peer_asn' => 'karşı taraf AS',
            'local_asn' => 'yerel AS',
            'local_address' => 'yerel adres',
            'multihop_ttl' => 'multihop TTL',
            'authentication_key' => 'oturum anahtarı',
            'hold_time' => 'hold-time',
            'cluster' => 'route reflector kümesi',
            'graceful_restart' => 'graceful restart',
            'out_delay' => 'anons gecikmesi',
            'bfd_minimum_interval' => 'BFD aralığı',
            'allow_prefixes.*' => 'dinamik komşu ağı',
            'import_policies.*' => 'içeri alma politikası',
            'export_policies.*' => 'dışarı verme politikası',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Bu switchte aynı adlı bir grup zaten var.',
            'name.regex' => 'Grup adı harfle başlamalı; harf, rakam, nokta, tire ve alt çizgi kullanılabilir.',
            'import_policies.*.exists' => 'Bu politika cihazda tanımlı değil.',
            'export_policies.*.exists' => 'Bu politika cihazda tanımlı değil.',
            'authentication_key.prohibited_if' => 'Kayıtlı anahtar silinecekse yeni anahtar yazılamaz.',
            'cluster.ipv4' => 'Küme kimliği bir IPv4 adresi biçiminde olmalı (örnek: 10.0.0.1).',
        ];
    }
}
