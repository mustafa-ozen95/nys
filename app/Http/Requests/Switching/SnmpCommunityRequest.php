<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Models\SwitchSnmpCommunity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak SNMP community'si.
 *
 * Ad cihazda community'nin KİMLİĞİDİR ve sonradan değiştirilemez; düzenleme
 * yalnızca yetkiyi ve istemci listesini değiştirir. Ad değişecekse kayıt
 * silinip yeniden eklenir.
 *
 * Ad aynı zamanda bir SIRDIR: okuma parolası gibi çalışır. Bu yüzden ekranda
 * basılmaz ve doğrulama hatalarında da tekrarlanmaz.
 */
class SnmpCommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['clients' => $this->clientList()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $yeni = $this->route('snmpCommunity') === null;

        return [
            /*
             * Junos community adı: boşluk ve tırnak kabul etmez. Yalnızca
             * oluştururken sorulur.
             */
            'name' => [
                Rule::requiredIf($yeni),
                'prohibited_if:_duzenleme,1',
                'string', 'max:63',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
            ],

            'authorization' => ['required', Rule::in(['read-only', 'read-write'])],

            // Erişebilecek ağlar; boş bırakılırsa herkes.
            'clients' => ['array', 'max:16'],
            'clients.*' => ['string', 'max:45'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('name') || $this->route('snmpCommunity') !== null) {
                return;
            }

            /*
             * Ad şifreli saklandığı için doğrudan aranamaz; özeti üzerinden
             * bakılır. Aynı ad iki kez yazılırsa cihazda ikincisi birincinin
             * yetkisini ezerdi.
             */
            $var = $this->route('switch')->snmpCommunities()
                ->where('name_digest', SwitchSnmpCommunity::digest($this->string('name')->toString()))
                ->exists();

            if ($var) {
                $validator->errors()->add('name', 'Bu community bu switchte zaten tanımlı.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function clientList(): array
    {
        $ham = $this->input('clients');

        if (is_array($ham)) {
            return array_values(array_filter(array_map('trim', $ham), 'filled'));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) $ham) ?: []),
            'filled',
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'community',
            'authorization' => 'yetki',
            'clients.*' => 'istemci ağı',
        ];
    }
}
