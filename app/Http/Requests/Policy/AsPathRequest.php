<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy;

use App\Models\SwitchAsPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * policy-options as-path tanımı.
 *
 * path bir DÜZENLİ İFADEDİR ve Junos'un kendi as-path söz dizimini kullanır:
 *
 *   ".* 65000"     yolun sonu 65000 olan her duyuru
 *   "65000 .*"     yolun başı 65000 olan her duyuru
 *   "65000+"       yalnızca 65000'den gelen, tekrarlı da olabilir
 *
 * Cihazda ölçüldü (test1, commit check): `as-path DENEME { path ".* 65000"; }`
 * kabul edildi.
 *
 * Politika terimi "from as-path <ad>" ile buna atıfta bulunur; tanımsız ada
 * atıf commit'i düşürür — bu yüzden terim formundaki liste buradan doldurulur.
 */
class AsPathRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:63',
                'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/',
                Rule::unique(SwitchAsPath::class, 'name')
                    ->where('switch_id', $this->route('switch')->id)
                    ->ignore($this->route('asPath')),
            ],

            /*
             * Junos ifadeyi tırnak içinde saklar; tırnak karakterinin kendisi
             * ifadeyi bozar ve panelden hiç gönderilmemeli.
             */
            'path' => ['required', 'string', 'max:255', 'regex:/^[0-9\s.*?+\[\]{}()|^$,\-]+$/'],
        ];
    }

    public function path(): string
    {
        return $this->string('path')->trim()->toString();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'as-path adı', 'path' => 'ifade'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Bu switchte aynı adlı bir as-path zaten var.',
            'path.regex' => 'İfade yalnızca AS numaraları ve düzenli ifade işaretleri içerebilir; tırnak kullanılmaz.',
        ];
    }
}
