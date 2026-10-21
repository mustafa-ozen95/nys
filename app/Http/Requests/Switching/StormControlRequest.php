<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\SwitchPort;
use App\Models\SwitchStormControl;
use App\Services\Junos\JunosDialect;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fırtına denetimi ayarı.
 *
 * Cihazın kendi kuralları formda önceden söylenir:
 *
 * - Eşik ya YÜZDE ya KBPS'dir; ikisi birlikte verilmez.
 * - Arayüz ethernet-switching ailesinde olmalı; cihaz aksini
 *   "Interface ... not found" ile reddediyor (test1'de ölçüldü).
 *   "all" cihazın kabul ettiği sanal addır.
 */
class StormControlRequest extends FormRequest
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
        $mevcut = $this->route('stormControl');

        return [
            'interface_name' => [
                Rule::requiredIf($mevcut === null),
                'string', 'max:64',
                Rule::unique(SwitchStormControl::class, 'interface_name')
                    ->where('switch_id', $switchId)
                    ->ignore($mevcut),
            ],

            // Yüzde: cihaz 1-100 kabul ediyor.
            'level' => ['nullable', 'integer', 'between:1,100'],

            // Kbps: alt sınır cihazın kendi sınırı.
            'bandwidth' => ['nullable', 'integer', 'between:1,100000000'],

            'no_broadcast' => ['boolean'],
            'no_unknown_unicast' => ['boolean'],
            'no_multicast' => ['boolean'],
            'no_registered_multicast' => ['boolean'],
            'no_unregistered_multicast' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('level') && $this->filled('bandwidth')) {
                $validator->errors()->add(
                    'level',
                    'Eşik ya yüzde ya da kbps olarak verilir; ikisi birlikte gönderilemez.',
                );
            }

            $this->guardSwitchingPort($validator);
        });
    }

    /**
     * Cihaz, anahtarlama ailesinde olmayan porta fırtına denetimi vermiyor:
     * "Interface ge-0/0/20.0 not found" (test1'de ölçüldü).
     */
    private function guardSwitchingPort(Validator $validator): void
    {
        $ad = $this->string('interface_name')->toString();

        if ($ad === '') {
            return;
        }

        if ($ad === 'all') {
            if (! $this->dialect()->supportsAllKeyword()) {
                $validator->errors()->add(
                    'interface_name',
                    'Bu Junos ailesi "all" kısayolunu kabul etmiyor; portu tek tek seçin.',
                );
            }

            return;
        }

        $port = SwitchPort::query()
            ->where('switch_id', $this->route('switch')->id)
            ->firstWhere('name', $ad);

        if ($port === null) {
            $validator->errors()->add('interface_name', 'Bu switchte böyle bir port yok.');

            return;
        }

        if (! in_array($port->port_mode, [PortMode::Access, PortMode::Trunk], true)) {
            $validator->errors()->add(
                'interface_name',
                "{$ad} anahtarlama modunda değil; cihaz bu porta fırtına denetimi tanımlamayı reddediyor.",
            );
        }
    }

    private function dialect(): JunosDialect
    {
        return ($this->route('switch')->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();
    }

    /**
     * Cihaza yazılacak ayarlar. Korunan ham satırlar düzenlenen kayıttan
     * gelir; panelde gösterilmeyen satırlar kaybolmasın.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $mevcut = $this->route('stormControl');

        return [
            'level' => $this->filled('level') ? $this->integer('level') : null,
            'bandwidth' => $this->filled('bandwidth') ? $this->integer('bandwidth') : null,
            'no_broadcast' => $this->boolean('no_broadcast'),
            'no_unknown_unicast' => $this->boolean('no_unknown_unicast'),
            'no_multicast' => $this->boolean('no_multicast'),
            'no_registered_multicast' => $this->boolean('no_registered_multicast'),
            'no_unregistered_multicast' => $this->boolean('no_unregistered_multicast'),
            'residual_xml' => $mevcut instanceof SwitchStormControl ? $mevcut->residual_xml : null,
        ];
    }

    public function interfaceName(): string
    {
        $mevcut = $this->route('stormControl');

        return $mevcut instanceof SwitchStormControl
            ? (string) $mevcut->interface_name
            : $this->string('interface_name')->toString();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'interface_name' => 'arayüz',
            'level' => 'yüzde eşiği',
            'bandwidth' => 'kbps eşiği',
        ];
    }
}
