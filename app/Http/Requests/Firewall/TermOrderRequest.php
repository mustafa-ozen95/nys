<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Terimlerin yeni sırası.
 *
 * Sıra bir görsel tercih değil, trafiğin kendisidir: Junos'ta ilk eşleşen
 * terim kazanır ve arkasındakiler hiç çalışmaz. Bu yüzden gönderilen liste
 * TAM olmalıdır — eksik bir liste, adı geçmeyen terimi sessizce silerdi.
 */
class TermOrderRequest extends FormRequest
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
            'terms' => ['required', 'array', 'min:1'],
            'terms.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $gonderilen = (array) $this->input('terms', []);

            if (count($gonderilen) !== count(array_unique($gonderilen))) {
                $validator->errors()->add('terms', 'Aynı terim listede iki kez geçiyor.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function orderedUuids(): array
    {
        return array_values((array) $this->input('terms', []));
    }
}
