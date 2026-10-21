<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Models\SwitchFirewallFilter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtrenin KENDİ altındaki ayarlar; terimleri değil kapsayıcıyı değiştirir.
 *
 * Bugün tek ayar var: "interface-specific". Küçük görünür ama filtre her
 * güncellemede silinip yeniden yazıldığı için panel onu okumadığı sürece
 * kullanıcı hiç dokunmadan kayboluyordu — ve o satır kaybolunca arayüz başına
 * tutulan sayaçlar tek bir sayaca düşer, sorun ararken yanlış yere bakılır.
 *
 * Ad ve aile burada DEĞİŞTİRİLEMEZ: cihazda filtrenin kimliği adıdır ve
 * arayüz bağlamaları adla tutulur. Yeniden adlandırma = sil + yarat + bütün
 * bağlamaları yeniden yaz; VLAN adındaki kuralın aynısı.
 */
class FirewallFilterSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Hem burada hem yeni filtre formunda geçerli kurallar.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(): array
    {
        return ['interface_specific' => ['boolean']];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::filterRules();
    }

    /**
     * Cihaza yazılacak filtre seviyesi ayarları.
     *
     * "residual_xml" düzenlenen kaydın KENDİSİNDEN gelir: panelin göstermediği
     * satırlar formdan geçmez ama kaybolmaları da gerekmez.
     *
     * @return array<string, mixed>
     */
    public function filterSettings(): array
    {
        $filter = $this->route('firewallFilter');

        return [
            'interface_specific' => $this->boolean('interface_specific'),
            'residual_xml' => $filter instanceof SwitchFirewallFilter ? $filter->residual_xml : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['interface_specific' => 'arayüz başına sayaç'];
    }
}
