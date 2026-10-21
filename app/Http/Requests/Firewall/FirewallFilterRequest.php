<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Enums\FirewallFamily;
use App\Models\SwitchFirewallFilter;
use Illuminate\Validation\Rule;

/**
 * Yeni firewall filtresi: ad, aile, filtre seviyesi ayarları ve İLK TERİM.
 *
 * Terimsiz filtre bir şey yapmaz ve cihazda anlamsız bir kabuk bırakır;
 * politika tarafındaki kuralın aynısı geçerlidir.
 *
 * Aile üç değerden biridir. EX3200/EX4200'de filtre yalnızca IP üzerinde
 * değil, anahtarlama düzleminde de kurulabilir (ethernet-switching) ve o
 * ailenin koşulları farklıdır — bkz. FirewallTermRequest.
 */
class FirewallFilterRequest extends FirewallTermRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...FirewallFilterSettingsRequest::filterRules(),

            // Terim adı "name" olduğu için filtre adı ayrı alandır.
            'filter_name' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(SwitchFirewallFilter::class, 'name')
                    ->where('switch_id', $this->route('switch')->id)
                    ->where('family', $this->family()->value),
            ],
            'family' => ['required', Rule::enum(FirewallFamily::class)],
        ];
    }

    public function filterName(): string
    {
        return $this->string('filter_name')->toString();
    }

    /**
     * Yeni filtrede aile FORMDAN gelir; terim uçlarında rotadaki filtreden.
     */
    public function family(): FirewallFamily
    {
        return FirewallFamily::tryFrom($this->string('family')->toString()) ?? FirewallFamily::Inet;
    }

    /**
     * Filtre seviyesindeki ayarlar (terimlerin değil, kapsayıcının kendisi).
     *
     * @return array<string, mixed>
     */
    public function filterSettings(): array
    {
        return [
            'interface_specific' => $this->boolean('interface_specific'),
            'residual_xml' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'filter_name' => 'filtre adı',
            'family' => 'adres ailesi',
            'interface_specific' => 'arayüz başına sayaç',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'filter_name.unique' => 'Bu switchte aynı adlı bir filtre zaten var.',
        ];
    }
}
