<?php

declare(strict_types=1);

namespace App\Http\Requests\Bgp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cihazın kendi AS numarası (routing-options autonomous-system).
 *
 * Cihaz bu tanımlı değilken BGP'yi commit etmiyor: "must define local
 * autonomous system when enabling BGP". Bu yüzden BGP ekranının ilk adımıdır.
 */
class AutonomousSystemRequest extends FormRequest
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
        // 32 bit AS numarası. 4200000000–4294967294 özel kullanım aralığıdır
        // ama cihaz kabul ettiği için ayrıca kısıtlanmaz.
        return [
            'autonomous_system' => ['required', 'numeric', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['autonomous_system' => 'AS numarası'];
    }
}
