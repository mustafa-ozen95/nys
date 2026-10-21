<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Models\SwitchFirewallPolicer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Firewall policer'ı: terimin "then policer <ad>" ile çağırdığı hız sınırı.
 *
 * Cihazda ölçüldü (test1, commit check):
 *   firewall policer P { if-exceeding { bandwidth-limit 1m; burst-size-limit 15k; } then { discard; } }
 *
 * Hız birimli yazılır: 1m, 500k, 8g ya da düz bit/s. Yüzde ile mutlak hız
 * birbirini DIŞLAR; cihaz ikisini birlikte kabul etmez, panel önce söyler.
 */
class FirewallPolicerRequest extends FormRequest
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
        $policer = $this->route('firewallPolicer');

        return [
            'name' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(SwitchFirewallPolicer::class, 'name')
                    ->where('switch_id', $this->route('switch')->id)
                    ->when($policer !== null, fn ($rule) => $rule->ignore($policer)),
            ],

            'filter_specific' => ['boolean'],

            // 1m, 500k, 8g ya da düz sayı (bit/s).
            'bandwidth_limit' => ['nullable', 'string', 'max:32', 'regex:/^\d+(\.\d+)?[kmg]?$/i'],
            'bandwidth_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'burst_size_limit' => ['nullable', 'string', 'max:32', 'regex:/^\d+(\.\d+)?[kmg]?$/i'],

            'is_discard' => ['boolean'],
            'forwarding_class' => ['nullable', 'string', 'max:63'],
            'loss_priority' => ['nullable', Rule::in(FirewallTermRequest::LOSS_PRIORITIES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('bandwidth_limit') && $this->filled('bandwidth_percent')) {
                $validator->errors()->add(
                    'bandwidth_percent',
                    'Mutlak hız ile yüzde birlikte verilemez; cihaz ikisini birlikte kabul etmez.',
                );
            }

            if (! $this->filled('bandwidth_limit') && ! $this->filled('bandwidth_percent')) {
                $validator->errors()->add(
                    'bandwidth_limit',
                    'Bir hız sınırı verilmeli: mutlak hız ya da yüzde.',
                );
            }

            /*
             * Eylemsiz policer paketi sayar ama hiçbir şey yapmaz; kullanıcı
             * hız sınırladığını sanır. Cihaz kabul eder, panel sormadan
             * geçirmez.
             */
            if (! $this->boolean('is_discard') && ! $this->filled('forwarding_class') && ! $this->filled('loss_priority')) {
                $validator->errors()->add(
                    'is_discard',
                    'Sınırı aşan pakete ne yapılacağı seçilmeli: düşür, iletim sınıfı ya da kayıp önceliği.',
                );
            }
        });
    }

    public function policerName(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * FirewallConfigurator'ın beklediği biçim.
     *
     * "residual_xml" düzenlenen kaydın KENDİSİNDEN gelir: panelin göstermediği
     * satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    public function policerSettings(): array
    {
        $policer = $this->route('firewallPolicer');

        return [
            'filter_specific' => $this->boolean('filter_specific'),
            'bandwidth_limit' => $this->string('bandwidth_limit')->trim()->toString() ?: null,
            'bandwidth_percent' => $this->filled('bandwidth_percent') ? $this->integer('bandwidth_percent') : null,
            'burst_size_limit' => $this->string('burst_size_limit')->trim()->toString() ?: null,
            'is_discard' => $this->boolean('is_discard'),
            'forwarding_class' => $this->string('forwarding_class')->trim()->toString() ?: null,
            'loss_priority' => $this->string('loss_priority')->trim()->toString() ?: null,
            'residual_xml' => $policer instanceof SwitchFirewallPolicer ? $policer->residual_xml : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'policer adı',
            'filter_specific' => 'filtre başına sayaç',
            'bandwidth_limit' => 'hız sınırı',
            'bandwidth_percent' => 'hız yüzdesi',
            'burst_size_limit' => 'burst boyu',
            'is_discard' => 'aşanı düşür',
            'forwarding_class' => 'iletim sınıfı',
            'loss_priority' => 'kayıp önceliği',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Bu switchte aynı adlı bir policer zaten var.',
            'bandwidth_limit.regex' => 'Hız birimiyle yazılır: 1m, 500k, 8g ya da düz bit/s.',
            'burst_size_limit.regex' => 'Burst boyu birimiyle yazılır: 15k, 1m ya da düz bayt.',
        ];
    }
}
