<?php

declare(strict_types=1);

namespace App\Http\Requests\Bgp;

use App\Models\BgpNeighbor;
use App\Models\SwitchPolicyStatement;
use App\Rules\HoldTime;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cihaza yazılacak BGP komşusu.
 *
 * Komşu adresi cihazda switch GENELİNDE benzersizdir: aynı adres iki farklı
 * grupta duramaz. Bu yüzden benzersizlik grup içinde değil switch üzerinde
 * aranır.
 *
 * Durum ve sayaç alanları burada YOKTUR; onları yalnızca cihazdan okuyan
 * eşitleme yazar.
 */
class BgpNeighborRequest extends FormRequest
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
             * Adres cihazda komşunun KİMLİĞİDİR: grup adı gibi
             * değiştirilemez. Değiştirilmiş bir adres gönderilirse
             * sessizce yok sayılmaz, açıkça reddedilir — cihaza giden
             * belge eski adresi taşır ve kullanıcı değiştirdiğini sanırdı.
             *
             * Adres gerçekten değişecekse komşu silinip yeniden eklenir.
             */
            'address' => array_values(array_filter([
                'required', 'ip',
                Rule::unique(BgpNeighbor::class, 'address')
                    ->where('switch_id', $this->route('switch')->id)
                    ->ignore($this->route('neighbor')),
                $this->route('neighbor') instanceof BgpNeighbor
                    ? Rule::in([$this->route('neighbor')->address])
                    : null,
            ])),
            'description' => ['nullable', 'string', 'max:255'],

            // Boş bırakılırsa grubun değeri geçerlidir.
            'peer_asn' => ['nullable', 'numeric', 'min:1', 'max:4294967295'],
            'local_address' => ['nullable', 'ip'],

            /*
             * Junos grup ayarlarının hemen hepsini komşu seviyesinde de
             * kabul eder ve komşudaki değer grubunkini EZER. Sağlayıcı
             * başına ayrı MD5 anahtarı en yaygın kullanımdır.
             *
             * Anahtar boş bırakılırsa kayıtlı değer korunur; silmek ayrı
             * bir kutu ister.
             */
            'authentication_key' => [
                'nullable', 'string', 'max:126',
                'prohibited_if:clear_authentication_key,1',
            ],
            'clear_authentication_key' => ['boolean'],

            // Junos: 0 (süre işletilmez) ya da 3-65535. 1 ve 2 reddedilir.
            'hold_time' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'passive' => ['boolean'],
            // Komşu seviyesinde de deactivate edilebilir (test1'de ölçüldü).
            'is_inactive' => ['boolean'],
            'multihop' => ['boolean'],
            'multihop_ttl' => [
                'nullable', 'integer', 'min:1', 'max:255',
                Rule::prohibitedIf(fn (): bool => ! $this->boolean('multihop')),
            ],

            'import_policies' => ['array'],
            'import_policies.*' => [$this->policyRule()],
            'export_policies' => ['array'],
            'export_policies.*' => [$this->policyRule()],
        ];
    }

    /**
     * BgpConfigurator'ın beklediği biçim.
     *
     * @return array<string, mixed>
     */
    public function deviceAttributes(): array
    {
        return [
            'address' => $this->string('address')->toString(),
            'description' => $this->string('description')->toString() ?: null,
            'peer_asn' => $this->string('peer_asn')->toString() ?: null,
            'local_address' => $this->string('local_address')->toString() ?: null,
            'authentication_key' => $this->authenticationKey(),
            'hold_time' => filled($this->input('hold_time')) ? $this->integer('hold_time') : null,
            'passive' => $this->boolean('passive'),
            /*
             * Alan GÖNDERİLMEDİYSE karar yok demektir, "etkin olsun" değil.
             * boolean() eksik girdiyi false'a çevirir; alanı taşımayan bir istek
             * cihazda devre dışı duran bir nesneyi sessizce AÇARDI. null geçilince
             * öznitelik hiç yazılmaz ve merge cihazdaki durumu korur.
             */
            'is_inactive' => $this->has('is_inactive') ? $this->boolean('is_inactive') : null,
            'multihop' => $this->boolean('multihop'),
            'multihop_ttl' => $this->boolean('multihop') && filled($this->input('multihop_ttl'))
                ? $this->integer('multihop_ttl')
                : null,
            'import_policies' => array_values((array) $this->input('import_policies', [])),
            'export_policies' => array_values((array) $this->input('export_policies', [])),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            HoldTime::validate($validator, $this->input('hold_time'));
        });
    }

    /**
     * Cihaza gidecek oturum anahtarı.
     *
     * Boş bırakmak "dokunma" demektir: kayıtlı değer geri yazılır. Kayıtlı
     * değer cihazdan okunmuş "$9$..." biçimidir ve Junos onu kabul eder.
     */
    private function authenticationKey(): ?string
    {
        if ($this->boolean('clear_authentication_key')) {
            return null;
        }

        $neighbor = $this->route('neighbor');

        return ($this->string('authentication_key')->toString() ?: null)
            ?? ($neighbor instanceof BgpNeighbor ? $neighbor->authentication_key : null);
    }

    /**
     * Politika adı cihazda tanımlı olmalı; tanımsız ada atıf commit'i
     * "referenced but not defined" ile düşürür.
     */
    private function policyRule(): mixed
    {
        return Rule::exists(SwitchPolicyStatement::class, 'name')
            ->where('switch_id', $this->route('switch')->id);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'address' => 'komşu adresi',
            'peer_asn' => 'karşı taraf AS',
            'local_address' => 'yerel adres',
            'authentication_key' => 'oturum anahtarı',
            'hold_time' => 'hold-time',
            'multihop_ttl' => 'multihop TTL',
            'import_policies.*' => 'içeri alma politikası',
            'export_policies.*' => 'dışarı verme politikası',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.unique' => 'Bu komşu adresi bu switchte zaten tanımlı.',
            'address.in' => 'Komşu adresi cihazda kimliktir ve değiştirilemez. '
                .'Adres değişecekse komşuyu silip yeniden ekleyin.',
            'authentication_key.prohibited_if' => 'Kayıtlı anahtar silinecekse yeni anahtar yazılamaz.',
            'import_policies.*.exists' => 'Bu politika cihazda tanımlı değil.',
            'export_policies.*.exists' => 'Bu politika cihazda tanımlı değil.',
        ];
    }
}
