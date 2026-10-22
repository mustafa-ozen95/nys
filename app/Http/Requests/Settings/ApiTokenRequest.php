<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Authorization\PermissionRegistry;
use App\Support\Net\Cidr;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API jetonu.
 *
 * Jeton sahibi adına konuşur; sahibi seçilmek zorunda ve sonradan
 * DEĞİŞTİRİLEMEZ — sahibi değişen bir jeton, aynı sırla başka birinin
 * yetkilerine açılan bir kapı olurdu.
 *
 * Yetkiler paneldeki yetki sözlüğünün aynısıdır; jeton yeni bir yetki türü
 * icat etmez ve tanımsız satırlar atılır.
 */
class ApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['allowed_ips' => $this->ipList()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $new = $this->route('apiToken') === null;

        return [
            'name' => ['required', 'string', 'max:80'],

            // Sahibi sonradan değişmez: aynı sır başka bir yetkiye açılırdı.
            'user' => [
                Rule::requiredIf($new),
                Rule::prohibitedIf(! $new),
                Rule::exists('users', 'uuid'),
            ],

            'abilities' => ['array'],
            'abilities.*' => ['array'],
            'abilities.*.*' => ['string'],

            /*
             * İzinli adresler. Boş bırakmak "her yerden" demektir ve ekran
             * bunu uyarı olarak söyler; sessizce kısıtlamak da yanlış olurdu.
             */
            'allowed_ips' => ['array', 'max:32'],
            'allowed_ips.*' => ['string', 'max:49'],

            /*
             * Gün. SIFIR ya da boş "süresiz" demektir: alan önceden dolu
             * geldiği için temizlemek yerine 0 yazmak daha doğal ve 0'ı
             * reddetmek "süresiz yapamıyorum" gibi görünürdü.
             */
            'lifetime_days' => ['nullable', 'integer', 'between:0,3650'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->ipList() as $index => $rule) {
                if ($this->validRule($rule)) {
                    continue;
                }

                $validator->errors()->add(
                    "allowed_ips.{$index}",
                    "Geçersiz adres ya da ağ: {$rule}",
                );
            }
        });
    }

    /**
     * Yalnızca TANIMLI kaynak/aksiyon çiftleri.
     *
     * @return array<string, list<string>>
     */
    public function abilities(PermissionRegistry $registry): array
    {
        $abilities = [];

        foreach ((array) $this->input('abilities', []) as $resource => $actions) {
            if (! is_string($resource)) {
                continue;
            }

            $accept = array_values(array_filter(
                (array) $actions,
                fn (mixed $action): bool => is_string($action) && $registry->supports($resource, $action),
            ));

            if ($accept !== []) {
                $abilities[$resource] = $accept;
            }
        }

        return $abilities;
    }

    /**
     * @return list<string>
     */
    public function ipList(): array
    {
        $raw = $this->input('allowed_ips');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw), 'filled'));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string) $raw) ?: []),
            'filled',
        ));
    }

    /**
     * Jetonun bitiş zamanı.
     *
     * Boş ya da SIFIR süresiz demektir; ikisi de aynı sonucu verir. Süresiz
     * jeton unutulur ve unutulan jeton sızar — ekran bunu uyarı olarak
     * söyler ama karar kullanıcınındır.
     */
    public function expiresAt(): ?string
    {
        $day = $this->input('lifetime_days');

        if (blank($day) || (int) $day === 0) {
            return null;
        }

        return now()->addDays((int) $day)->toDateTimeString();
    }

    private function validRule(string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        return Cidr::tryParse($rule) !== null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'jeton adı',
            'user' => 'sahibi',
            'allowed_ips' => 'izinli adresler',
            'lifetime_days' => 'ömür',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['user.prohibited' => 'Jetonun sahibi sonradan değiştirilemez; yenisi çıkarılmalı.'];
    }
}
