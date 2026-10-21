<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Services\Junos\JunosDialect;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * secure-access-port altındaki iki isteğin ortak kuralları.
 *
 * VLAN kuralı ile arayüz kuralı ayrı nesnelerdir ve alanları birbirine
 * benzemez; ortak olan yalnızca cihazın iki kuralıdır:
 *
 *  - Bu düğüm ELS'te YOKTUR (bkz. JunosSchemaProfile::usesSecureAccessPort).
 *  - Eylem tek başına yazılmaz; her zaman bir sınıra asılıdır.
 *
 * İkisi de iki ayrı istekte tekrarlanırsa biri değiştiğinde diğeri sessizce
 * ayrışır.
 */
abstract class SecureAccessRequest extends FormRequest
{
    /**
     * Yetkilendirme controller'daki "can:" ara katmanındadır; kaynak
     * switchtir ve kural her uçta aynıdır.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function switch(): NetworkSwitch
    {
        $switch = $this->route('switch');

        if (! $switch instanceof NetworkSwitch) {
            throw new LogicException('Rota switch taşımıyor.');
        }

        return $switch;
    }

    /**
     * Cihazın Junos ailesi. Profil boşsa ELS öncesi varsayılır: yanlış
     * tarafa düşmek yeni kısıt UYDURMAZ.
     */
    protected function dialect(): JunosDialect
    {
        return ($this->switch()->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();
    }

    /**
     * ELS'te tek bir "secure-access-port" düğümü yoktur; ayarlar üç ayrı
     * hiyerarşiye dağılmıştır ve panel ORAYA yazar. Ama hepsi
     * eşlenmiyor: DHCP güveni ve izinli MAC listesi ELS'te VLAN kapsamında
     * yaşar, panelin switch geneli modeline oturmaz.
     *
     * Eşlenmeyen alan UYDURULMAZ: gerekçesiyle reddedilir. Hangi alanın
     * neden desteklenmediğini lehçe söyler, bu istek değil — yeni bir Junos
     * ailesi eklendiğinde burası değişmesin diye.
     */
    protected function guardSchema(Validator $validator, string $field): void
    {
        $lehce = $this->switch()->schema_profile?->dialect();
        $kapsamli = $lehce?->vlanScopedSecureAccessFields() ?? [];

        if ($kapsamli === [] || $this->filled('vlan_name')) {
            return;
        }

        foreach ($kapsamli as $alan => $neden) {
            if ($this->filled($alan)) {
                $validator->errors()->add(
                    'vlan_name',
                    $neden.' Bu alanı kullanmak için önce VLAN seçin.',
                );

                return;
            }
        }
    }

    /**
     * Eylem sınıra asılıdır: sınır yoksa cihaza yazılacak bir satır da yoktur
     * ve seçilen eylem sessizce düşerdi.
     */
    protected function guardLimitAction(Validator $validator, string $limit, string $action): void
    {
        if ($this->filled($action) && ! $this->filled($limit)) {
            $validator->errors()->add($action, 'Eylem tek başına yazılmaz; önce bir sınır verin.');
        }
    }
}
