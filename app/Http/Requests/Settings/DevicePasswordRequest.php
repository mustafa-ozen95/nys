<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\Credentials\PasswordGenerator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Elle verilen cihaz parolası.
 *
 * Parola HER ZAMAN cihaza yazılır. "Yalnızca panele kaydet" diye bir işlem
 * YOKTUR: panelin bildiği ile cihazdaki ayrı ayrı düzenlenebilseydi iki
 * doğruluk kaynağı olur ve hangisinin geçerli olduğu ancak bağlanmaya
 * çalışınca anlaşılırdı. Panelin kaydı, panelin cihaza YAZDIĞI şeydir.
 *
 * Elle verilen parola rastgele turla birebir aynı güvenli sıradan geçer;
 * elle seçilmiş olması onu daha az tehlikeli yapmaz.
 *
 * Sınırlar cihazın kendi cevaplarından alınmıştır (jn32-test, 12.3R12.4):
 * altı karakterden kısa parola "minimum password length is 6", yalnız küçük
 * harften oluşan parola "require change of case, digits or punctuation".
 * Panel sekiz karakterde durur — cihazın kabul ettiği her parolayı yine
 * kabul eder, bir tık yukarıda.
 */
class DevicePasswordRequest extends FormRequest
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
            /*
             * Uzunluk PANELİN kararıdır (cihaz 6'ya izin veriyor), sınıf
             * kuralı ise CİHAZIN — ikisi tek yerde durur, bkz.
             * PasswordGenerator. Eski desen "rakam ya da sembol var" demeye
             * yetiyordu: "12345678" panelden geçip cihazda ham bir hatayla
             * düşüyordu.
             */
            'password' => [
                'required', 'string', 'confirmed',
                'min:'.PasswordGenerator::MINIMUM_LENGTH, 'max:128',
                'regex:'.PasswordGenerator::DEVICE_CLASS_PATTERN,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['password' => 'parola'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.regex' => PasswordGenerator::deviceRuleMessage(),
        ];
    }
}
