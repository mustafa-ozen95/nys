<?php

declare(strict_types=1);

namespace App\Http\Requests\Aggregation;

use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Enums\PortMode;
use App\Enums\PortType;
use App\Models\SwitchPort;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bir ae arayüzünün ayarları ve üyeleri.
 *
 * Cihazda doğrulanmış kısıtlar:
 *
 * | Kısıt | Cihazın söylediği |
 * | --- | --- |
 * | En fazla 8 üye | "group limit of 8 exceeded" |
 * | minimum-links 1..8 | "Value 9 is not within range (1..8)" |
 * | minimum-links ≤ üye sayısı | "minimum-links is greater than the num of links configured" |
 * | LACP periyodu tek başına olmaz | rol verilmeden anlamsızdır |
 *
 * Üye olan port kendi VLAN'ını ve birimini KAYBEDER; bu geri alınamaz bir yan
 * etkidir ve arayüzde de yazar.
 */
class LinkAggregationRequest extends FormRequest
{
    /**
     * Junos'un kabul ettiği bağlantı hızları.
     *
     * @var list<string>
     */
    public const LINK_SPEEDS = ['100m', '1g', '10g'];

    /**
     * Bir LAG'daki en fazla üye sayısı.
     */
    public const MAX_MEMBERS = 8;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['members', 'vlans'] as $alan) {
            $this->merge([$alan => array_values(array_filter(
                (array) $this->input($alan, []),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ))]);
        }
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

            // Boş = statik LAG; protokol konuşulmaz.
            'lacp_mode' => ['nullable', Rule::enum(LacpMode::class)],
            'lacp_periodic' => [
                'nullable',
                Rule::enum(LacpPeriodic::class),
                Rule::prohibitedIf(fn (): bool => ! $this->filled('lacp_mode')),
            ],

            'minimum_links' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_MEMBERS],
            'link_speed' => ['nullable', Rule::in(self::LINK_SPEEDS)],

            'members' => ['array', 'max:'.self::MAX_MEMBERS],
            'members.*' => [
                'uuid',
                // Yalnızca BU switchin FİZİKSEL portları üye olabilir.
                Rule::exists(SwitchPort::class, 'uuid')
                    ->where('switch_id', $switchId)
                    ->where('interface_kind', PortType::Physical->value),
            ],

            'port_mode' => ['required', Rule::in([PortMode::Access->value, PortMode::Trunk->value])],
            'vlans' => ['array', ...($this->isAccess() ? ['max:1'] : [])],
            'vlans.*' => ['uuid', Rule::exists(Vlan::class, 'uuid')->where('switch_id', $switchId)],
            'untagged_vlan' => [
                Rule::prohibitedIf(fn (): bool => $this->isAccess()),
                'nullable', 'uuid',
                Rule::exists(Vlan::class, 'uuid')->where('switch_id', $switchId),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateMinimumLinks($validator);
            $this->validateMembersAreFree($validator);
            $this->validateUntaggedIsMember($validator);
        });
    }

    /**
     * Cihaz "minimum-links is greater than the num of links configured for
     * this interface" diyerek reddediyor; üyesiz bir ae'de 1 bile fazladır.
     */
    private function validateMinimumLinks(Validator $validator): void
    {
        $minimum = $this->input('minimum_links');

        if (! filled($minimum)) {
            return;
        }

        $uyeSayisi = count((array) $this->input('members', []));

        if ((int) $minimum > $uyeSayisi) {
            $validator->errors()->add('minimum_links', $uyeSayisi === 0
                ? 'Önce üye port seçilmeli; üyesi olmayan bir LAG’da en az bağlantı sayısı verilemez.'
                : "En az bağlantı sayısı üye sayısını ({$uyeSayisi}) geçemez.");
        }
    }

    /**
     * Bir port yalnızca tek bir ae'ye üye olabilir.
     */
    private function validateMembersAreFree(Validator $validator): void
    {
        $aggregate = $this->route('aggregate');

        $baskasinda = SwitchPort::query()
            ->whereIn('uuid', (array) $this->input('members', []))
            ->whereNotNull('aggregate_port_id')
            ->where('aggregate_port_id', '!=', $aggregate?->id)
            ->with('aggregate')
            ->get();

        foreach ($baskasinda as $port) {
            $validator->errors()->add(
                'members',
                "{$port->name} zaten {$port->aggregate?->name} üyesi; önce oradan çıkarılmalı.",
            );
        }
    }

    /**
     * Etiketsiz VLAN üye listesinde de bulunmalı; yoksa cihazda etiketli
     * kalır ve trafiği beklenenden farklı taşır.
     */
    private function validateUntaggedIsMember(Validator $validator): void
    {
        $untagged = $this->string('untagged_vlan')->toString();

        if ($untagged !== '' && ! in_array($untagged, (array) $this->input('vlans', []), true)) {
            $validator->errors()->add('untagged_vlan', 'Etiketsiz VLAN seçili VLAN’lar arasında olmalı.');
        }
    }

    public function isAccess(): bool
    {
        return $this->input('port_mode') !== PortMode::Trunk->value;
    }

    /**
     * @return array{lacp_mode: ?LacpMode, lacp_periodic: ?LacpPeriodic, minimum_links: ?int, link_speed: ?string}
     */
    public function aggregationOptions(): array
    {
        $mode = $this->string('lacp_mode')->toString();
        $periodic = $this->string('lacp_periodic')->toString();

        return [
            'lacp_mode' => $mode === '' ? null : LacpMode::from($mode),
            'lacp_periodic' => $mode === '' || $periodic === '' ? null : LacpPeriodic::from($periodic),
            'minimum_links' => filled($this->input('minimum_links')) ? $this->integer('minimum_links') : null,
            'link_speed' => $this->string('link_speed')->toString() ?: null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lacp_mode' => 'LACP rolü',
            'lacp_periodic' => 'LACP periyodu',
            'minimum_links' => 'en az bağlantı sayısı',
            'link_speed' => 'bağlantı hızı',
            'members' => 'üye portlar',
            'port_mode' => 'port modu',
            'untagged_vlan' => 'etiketsiz VLAN',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'members.max' => 'Bir LAG en fazla :max üye taşıyabilir.',
            'lacp_periodic.prohibited' => 'Periyot yalnızca LACP rolü seçiliyken verilebilir.',
        ];
    }
}
