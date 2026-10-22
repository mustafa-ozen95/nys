<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\SwitchAnalyzer;
use App\Models\SwitchPort;
use App\Services\Switching\PortMirroringViewService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Port aynalama (analyzer) ayarı.
 *
 * Cihazın kendi kuralları formda ÖNCEDEN söylenir:
 *
 * - Çıkış hedefi ya ARAYÜZ ya VLAN'dır; ikisi birlikte verilemez.
 * - Çıkış portu anahtarlama ailesinde olmalı. Cihaz aksini reddediyor:
 *   "Analyzer output interface ge-0/0/21.0 should be configured with family
 *   ethernet-switching" (test1'de commit check ile ölçüldü).
 * - Kaynak port çıkış portuyla aynı olamaz: port kendi kopyasını almaya
 *   çalışırdı.
 * - Ad cihazda KİMLİKTİR ve değiştirilemez; firewall terimleri de aynalamaya
 *   bu adla atıf yapar ("then analyzer <ad>"). Yeniden adlandırma = sil +
 *   yeniden yarat.
 */
class AnalyzerRequest extends FormRequest
{
    /**
     * Junos'un kabul ettiği kayıp öncelikleri.
     *
     * Firewall terimindeki listeyle aynı değerlerdir ama oradan
     * DEVŞİRİLMEZ: ikisi ayrı Junos düğümleridir ve birinin listesi
     * değiştiğinde diğerinin sessizce değişmesi istenmez.
     *
     * @var list<string>
     */
    public const LOSS_PRIORITIES = ['low', 'medium-low', 'medium-high', 'high'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switch = $this->route('switch');
        $current = $this->analyzer();
        $options = app(PortMirroringViewService::class);

        $sources = array_keys($options->sourceInterfaceOptions($switch, $current));
        $exits = array_keys($options->outputInterfaceOptions($switch, $current));
        $vlans = array_keys($options->vlanOptions($switch, $current));

        return [
            /*
             * Ad harfle başlar; Junos "90aynalama" gibi bir adı "statement
             * creation failed" ile reddeder.
             */
            'name' => [
                Rule::requiredIf($current === null),
                'string', 'max:63', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(SwitchAnalyzer::class, 'name')
                    ->where('switch_id', $switch->id)
                    ->ignore($current),
            ],

            /*
             * Kaç pakette bir aynalansın. ÜST SINIR ÖLÇÜLMEDİ; kolon sınırı
             * kadarı kabul edilir ve cihaz kabul etmiyorsa commit check
             * reddeder — olmayan bir kısıt uydurulmaz.
             */
            /*
             * Üst sınır CİHAZDAN geliyor: "Value 2048 is not within range
             * (0..2047)" (test1, commit check). Panel bunu uydurmuyordu ve
             * kullanıcı ham cihaz hatasıyla karşılaşıyordu.
             */
            'ratio' => ['nullable', 'integer', 'between:0,2047'],

            'loss_priority' => ['nullable', Rule::in(self::LOSS_PRIORITIES)],

            'output_interface' => ['nullable', Rule::in($exits)],
            'output_vlan' => ['nullable', Rule::in($vlans)],

            'ingress_interfaces' => ['array'],
            'ingress_interfaces.*' => [Rule::in($sources)],
            'egress_interfaces' => ['array'],
            'egress_interfaces.*' => [Rule::in($sources)],
            'ingress_vlans' => ['array'],
            'ingress_vlans.*' => [Rule::in($vlans)],
            'egress_vlans' => ['array'],
            'egress_vlans.*' => [Rule::in($vlans)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardSingleOutput($validator);
            $this->guardSwitchingPort($validator);
            $this->guardSourceIsNotOutput($validator);
            $this->guardAggregatedOutput($validator);
            $this->guardOutputHasNoVlan($validator);
            $this->guardRename($validator);
        });
    }

    /**
     * Çıkış hedefi tektir: ya arayüz ya VLAN.
     */
    private function guardSingleOutput(Validator $validator): void
    {
        if ($this->filled('output_interface') && $this->filled('output_vlan')) {
            $validator->errors()->add(
                'output_interface',
                'Çıkış hedefi ya arayüz ya VLAN’dır; ikisi birlikte gönderilemez.',
            );
        }
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta aynalama çıkışı vermiyor:
     * "Analyzer output interface ge-0/0/21.0 should be configured with family
     * ethernet-switching" (test1'de ölçüldü).
     */
    private function guardSwitchingPort(Validator $validator): void
    {
        $name = $this->string('output_interface')->toString();

        if ($name === '') {
            return;
        }

        $port = SwitchPort::query()
            ->where('switch_id', $this->route('switch')->id)
            ->firstWhere('name', $this->physical($name));

        if ($port === null) {
            $validator->errors()->add('output_interface', 'Bu switchte böyle bir port yok.');

            return;
        }

        if (! app(PortMirroringViewService::class)->isSwitching($port->port_mode)) {
            $validator->errors()->add(
                'output_interface',
                "{$name} anahtarlama ailesinde değil; cihaz aynalama çıkışını bu porta yazmayı reddediyor.",
            );
        }
    }

    /**
     * ae ARAYÜZÜ aynalama portu olamaz.
     *
     * Cihaz açıkça söylüyor: "Aggregated interface cannot be configured as
     * Analyzer monitor interface" (test1, commit check). Kural hem kaynak
     * hem çıkış için geçerlidir.
     */
    private function guardAggregatedOutput(Validator $validator): void
    {
        $candidates = array_filter(array_merge(
            [$this->string('output_interface')->toString()],
            (array) $this->input('ingress_interfaces', []),
            (array) $this->input('egress_interfaces', []),
        ));

        foreach ($candidates as $name) {
            if (preg_match('/^ae\d+/', (string) $name) === 1) {
                $validator->errors()->add(
                    'output_interface',
                    "{$name} bir toplama (ae) arayüzü; cihaz aynalamada ae arayüzünü kabul etmiyor.",
                );

                return;
            }
        }
    }

    /**
     * Çıkış portu HİÇBİR VLAN'a üye olmamalı.
     *
     * Cihaz: "Analyzer output interface ge-0/0/1.0 should not be added to any
     * vlan" (test1, commit check). Aynalama çıkışı normal anahtarlamaya
     * katılırsa kopyalanan trafik ağa geri karışır.
     */
    private function guardOutputHasNoVlan(Validator $validator): void
    {
        $name = $this->string('output_interface')->toString();

        if ($name === '' || $validator->errors()->has('output_interface')) {
            return;
        }

        $port = SwitchPort::query()
            ->where('switch_id', $this->route('switch')->id)
            ->with('vlans')
            ->firstWhere('name', $this->physical($name));

        if ($port !== null && $port->vlans->isNotEmpty()) {
            $membership = $port->vlans->pluck('name')->join(', ');

            $validator->errors()->add(
                'output_interface',
                "{$name} şu VLAN'lara üye: {$membership}. Cihaz, aynalama çıkışının hiçbir VLAN'a üye olmamasını istiyor; "
                .'önce portun VLAN üyeliğini kaldırın.',
            );
        }
    }

    /**
     * Kaynak port çıkış portu olamaz: port kendi kopyasını almaya çalışırdı.
     */
    private function guardSourceIsNotOutput(Validator $validator): void
    {
        $exit = $this->string('output_interface')->toString();

        if ($exit === '') {
            return;
        }

        foreach (['ingress_interfaces', 'egress_interfaces'] as $alan) {
            if (in_array($exit, (array) $this->input($alan, []), true)) {
                $validator->errors()->add(
                    $alan,
                    "{$exit} hem kaynak hem çıkış olamaz; aynalanan trafik kendi portuna geri gönderilemez.",
                );
            }
        }
    }

    /**
     * Ad cihazda kimliktir; düzenlemede değiştirilemez.
     *
     * Sessizce yok saymak yerine açıkça reddedilir: kullanıcı yazdığı değerin
     * kaydedildiğini sanmamalı.
     */
    private function guardRename(Validator $validator): void
    {
        $current = $this->analyzer();

        if ($current === null || ! $this->filled('name')) {
            return;
        }

        if ($this->string('name')->toString() !== (string) $current->name) {
            $validator->errors()->add(
                'name',
                'Aynalama adı cihazda kimliktir ve değiştirilemez; yeni ad için kayıt silinip yeniden eklenir.',
            );
        }
    }

    /**
     * Cihaza yazılacak ayarlar.
     *
     * Korunan ham satırlar düzenlenen kayıttan gelir: panelde gösterilmeyen
     * satırlar geri yazılırken kaybolmasın.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $current = $this->analyzer();

        return [
            'ratio' => $this->filled('ratio') ? $this->integer('ratio') : null,
            'loss_priority' => $this->string('loss_priority')->toString() ?: null,
            'output_interface' => $this->string('output_interface')->toString() ?: null,
            'output_vlan' => $this->string('output_vlan')->toString() ?: null,
            'ingress_interfaces' => $this->names('ingress_interfaces'),
            'ingress_vlans' => $this->names('ingress_vlans'),
            'egress_interfaces' => $this->names('egress_interfaces'),
            'egress_vlans' => $this->names('egress_vlans'),
            'residual_xml' => $current?->residual_xml,
        ];
    }

    public function analyzerName(): string
    {
        return $this->analyzer()?->name ?? $this->string('name')->toString();
    }

    /**
     * @return list<string>
     */
    private function names(string $field): array
    {
        return array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) $this->input($field, [])),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Mantıksal arayüzün fiziksel karşılığı: ge-0/0/21.0 → ge-0/0/21.
     */
    private function physical(string $name): string
    {
        return (string) preg_replace('/\.\d+$/', '', $name);
    }

    private function analyzer(): ?SwitchAnalyzer
    {
        $current = $this->route('analyzer');

        return $current instanceof SwitchAnalyzer ? $current : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'aynalama adı',
            'ratio' => 'oran',
            'loss_priority' => 'kayıp önceliği',
            'output_interface' => 'çıkış arayüzü',
            'output_vlan' => 'çıkış VLAN’ı',
            'ingress_interfaces' => 'giriş kaynakları',
            'egress_interfaces' => 'çıkış kaynakları',
            'ingress_vlans' => 'giriş VLAN’ları',
            'egress_vlans' => 'çıkış VLAN’ları',
        ];
    }
}
