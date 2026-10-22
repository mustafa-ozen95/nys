<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use App\Enums\JunosUserClass;
use App\Models\SwitchLoginUser;
use App\Support\Credentials\PasswordGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaz üzerindeki yerel giriş hesabı.
 *
 * Ad cihazda hesabın KİMLİĞİDİR ve sonradan değiştirilemez; düzenleme
 * yalnızca sınıfı, tam adı ve parolayı değiştirir.
 *
 * Sınırlar cihazın kendi cevaplarından alınmıştır (jn32-test, 12.3R12.4):
 * uid 100..64000 dışı "not within range", altı karakterden kısa parola
 * "minimum password length is 6", yalnız küçük harften oluşan parola
 * "require change of case, digits or punctuation".
 *
 * Panelin bağlandığı hesap burada korunur: silinemez, yapılandırma yetkisi
 * elinden alınamaz. Cihaz ikisine de izin verir — geri alınabilir commit
 * paneli kurtarır ama iki dakika boyunca switch yönetilemez.
 */
class LoginUserRequest extends FormRequest
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
        $new = $this->loginUser() === null;

        return [
            /*
             * Junos adı harf/rakamla başlar; nokta, tire ve alt çizgi kabul
             * eder. Yalnızca oluştururken sorulur.
             */
            'name' => [
                Rule::requiredIf($new),
                'prohibited_if:_duzenleme,1',
                'string', 'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
            ],

            'class' => ['required', Rule::enum(JunosUserClass::class)],

            'full_name' => ['nullable', 'string', 'max:64'],

            /*
             * Cihaz aynı uid'i iki hesaba vermeye izin verir; panel vermez.
             * Aynı uid'i taşıyan iki hesap dosya sisteminde TEK kimliktir:
             * birinin bıraktığı izi diğerininkinden ayırmak mümkün olmaz.
             * Boş bırakılırsa cihaz kendi seçer.
             */
            'uid' => [
                'nullable', 'integer', 'between:100,64000',
                'prohibited_if:_duzenleme,1',
            ],

            /*
             * Parola cihazda saklanır, panelde DEĞİL. Düzenlemede boş
             * bırakmak cihazdakini korur.
             */
            'password' => [
                'nullable', 'string', 'confirmed',
                'min:'.PasswordGenerator::MINIMUM_LENGTH, 'max:128',
                'regex:'.PasswordGenerator::DEVICE_CLASS_PATTERN,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->identityConflict($validator);
            $this->panelAccountProtection($validator);
        });
    }

    /**
     * Aynı ad ya da aynı uid cihazda ikinci kez yazılırsa ilkini ezer.
     */
    private function identityConflict(Validator $validator): void
    {
        if ($this->loginUser() !== null) {
            return;
        }

        $accounts = $this->route('switch')->loginUsers();

        if (! $validator->errors()->has('name')
            && (clone $accounts)->whereRaw('LOWER(name) = ?', [mb_strtolower($this->string('name')->toString())])->exists()) {
            $validator->errors()->add('name', 'Bu hesap cihazda zaten tanımlı.');
        }

        $uid = $this->input('uid');

        if (! $validator->errors()->has('uid') && filled($uid)
            && (clone $accounts)->where('uid', (int) $uid)->exists()) {
            $validator->errors()->add('uid', 'Bu uid cihazda başka bir hesapta kullanılıyor.');
        }
    }

    /**
     * Panelin cihaza bağlandığı hesap iki yönden korunur.
     *
     * YETKİ: sınıfı düşürülürse panel bağlanır ama yapılandıramaz.
     *
     * PAROLA: burada değiştirilirse cihazdaki parola değişir, PANELDEKİ kayıt
     * değişmez ve panel o switche bir daha bağlanamaz — kurtarma yalnızca
     * konsoldan olur. Aynı cihaz satırına iki ekrandan gidiliyor ve yalnızca
     * biri bunu biliyordu: parola turu (SwitchPasswordRotator) parolayı
     * kurtarma hesabı üzerinden değiştirip panele de KAYDEDİYOR ve onay
     * oturumunu parolası değişmeyen bir hesapla atıyor. Bu hesabın parolası
     * yalnızca oradan değişir.
     */
    private function panelAccountProtection(Validator $validator): void
    {
        $account = $this->loginUser();

        if ($account === null || ! $account->is_panel_account) {
            return;
        }

        if (filled($this->input('password'))) {
            $validator->errors()->add(
                'password',
                'Panel bu hesapla bağlanıyor. Parolası buradan değiştirilirse cihazdaki parola '
                .'değişir ama paneldeki kayıt eski kalır ve switch bir daha yönetilemez. '
                .'Bu hesabın parolası Ayarlar → Parolalar ekranından değiştirilir; orada parola '
                .'panele de kaydedilir.',
            );
        }

        $class = JunosUserClass::tryFrom($this->string('class')->toString());

        if ($class === null || $class->canConfigure()) {
            return;
        }

        $validator->errors()->add(
            'class',
            'Panel bu hesapla bağlanıyor; yetkisi düşürülürse switch yönetilemez hâle gelir.',
        );
    }

    private function loginUser(): ?SwitchLoginUser
    {
        $account = $this->route('loginUser');

        return $account instanceof SwitchLoginUser ? $account : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'hesap adı',
            'class' => 'yetki sınıfı',
            'full_name' => 'tam ad',
            'uid' => 'uid',
            'password' => 'parola',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Hesap adı harf ya da rakamla başlamalı; nokta, tire ve alt çizgi kullanılabilir.',
            'uid.between' => 'Cihaz yalnızca 100 ile 64000 arasındaki uid’leri kabul eder.',
            'password.regex' => PasswordGenerator::deviceRuleMessage(),
        ];
    }
}
