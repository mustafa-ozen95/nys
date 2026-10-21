<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Models\AnnouncementRecipient;
use App\Rules\Asn;
use App\Rules\Cidr;
use App\Support\Net\Asn as AsnValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
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
            'prefixes' => ['required', 'string', 'max:4000', new Cidr],
            'asn' => ['required', 'string', 'max:20', new Asn],
            'downstream' => ['nullable', 'string', 'max:20', new Asn],
            'recipients' => ['required', 'array', 'min:1'],
            // Alıcılar veritabanından yönetilir; pasif olan seçilemez.
            // Kayıt düzeyinde görme yasağı olan alıcı seçilemez (gönderim yolu da buradan geçer).
            'recipients.*' => [
                'uuid',
                Rule::exists(AnnouncementRecipient::class, 'uuid')
                    ->where('is_active', true)
                    ->whereIn('id', AnnouncementRecipient::query()->visibleTo($this->user())->select('id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prefixes' => 'prefix listesi',
            'asn' => 'AS numarası',
            'downstream' => 'downstream AS numarası',
            'recipients' => 'alıcılar',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.required' => 'En az bir alıcı seçin.',
        ];
    }

    /**
     * AS numaraları tek yazıma indirilir: "as64496" da "64496" da "AS64496"
     * olur. Böylece listede seçili görünür, önbellekte tek anahtar açar ve
     * mailde her seferinde aynı biçimde basılır.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'asn' => $this->tidyAsn('asn'),
            'downstream' => $this->tidyAsn('downstream'),
        ]);
    }

    /**
     * Geçersiz değer OLDUĞU GİBİ bırakılır: düzeltilemeyeni varsayılana
     * çevirmek, kullanıcının yazdığından başka bir ASN adına anons etmek
     * olurdu. Boş alan da doldurulmaz; "required" reddeder.
     */
    private function tidyAsn(string $key): ?string
    {
        $raw = $this->string($key)->trim()->toString();

        return $raw === '' ? null : (AsnValue::normalize($raw) ?? $raw);
    }
}
