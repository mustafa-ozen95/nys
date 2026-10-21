<?php

declare(strict_types=1);

namespace App\Http\Requests\Forwarding;

use App\Models\SwitchRibGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RIB grubu: bir tablodaki yolların BAŞKA tablolara kopyalanması.
 *
 * FBF'nin üçüncü parçası. Firewall terimi trafiği tabloya atar, tablo kendi
 * yolunu taşır — ama arayüz yolları (connected) yalnızca inet.0'dadır ve
 * kopyalanmazsa hedef tablo o arayüzlere ulaşamaz.
 *
 * SIRA ANLAMLIDIR: Junos import-rib listesinin İLK öğesini birincil tablo
 * sayar ve o, grubun bağlandığı tablo olmalıdır.
 */
class RibGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Satır satır girilen tablolar listeye çevrilir; boş satırlar atılır.
     */
    protected function prepareForValidation(): void
    {
        $satirlar = preg_split('/\r\n|\r|\n/', (string) $this->input('import_ribs', '')) ?: [];

        $this->merge([
            'import_ribs' => array_values(array_filter(array_map(trim(...), $satirlar), 'strlen')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $switchId = $this->route('switch')->id;
        $mevcut = $this->route('ribGroup');

        return [
            'name' => [
                Rule::requiredIf($mevcut === null),
                'string', 'max:63', 'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique(SwitchRibGroup::class, 'name')
                    ->where('switch_id', $switchId)
                    ->ignore($mevcut),
            ],

            /*
             * En az bir tablo gerekir: boş bir grup cihazda kabul edilse de
             * hiçbir işe yaramaz ve FBF sessizce çalışmaz.
             */
            'import_ribs' => ['required', 'array', 'min:1', 'max:8'],

            // "inet.0", "musteri.inet.0" gibi. Nokta zorunlu değil.
            'import_ribs.*' => ['string', 'max:63', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'import_ribs' => array_values((array) $this->input('import_ribs', [])),
            'residual_xml' => $this->route('ribGroup')?->residual_xml,
        ];
    }

    public function groupName(): string
    {
        return $this->route('ribGroup')?->name
            ?? $this->string('name')->trim()->toString();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'grup adı',
            'import_ribs' => 'tablolar',
            'import_ribs.*' => 'tablo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Grup adı yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'import_ribs.required' => 'En az bir tablo gerekir; boş bir grup hiçbir işe yaramaz.',
            'import_ribs.*.regex' => 'Tablo adı yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
        ];
    }
}
