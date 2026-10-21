<?php

declare(strict_types=1);

namespace App\Http\Requests\Policy;

use App\Models\SwitchCommunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BGP community tanımı.
 *
 * Üye biçimi Junos'un kabul ettiği hâlidir: "65000:666". Joker de geçerlidir
 * ("*:666") ve well-known adlar (no-export gibi) doğrudan yazılabilir.
 */
class CommunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Üyeler satır satır girilir; boş satırlar atılır.
        $members = preg_split('/[\r\n,]+/', (string) $this->input('members', '')) ?: [];

        $this->merge([
            'members' => array_values(array_filter(array_map('trim', $members), static fn (string $v): bool => $v !== '')),
        ]);
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
                Rule::unique(SwitchCommunity::class, 'name')
                    ->where('switch_id', $this->route('switch')->id)
                    ->ignore($this->route('community')),
            ],
            'members' => ['required', 'array', 'min:1'],
            // "65000:666", "*:666", "no-export" gibi.
            'members.*' => ['string', 'max:64', 'regex:/^([0-9*]+:[0-9*]+|[a-z][a-z\-]*)$/'],

            /*
             * invert-match tanımın anlamını TERSİNE çevirir: "üyelerden
             * hiçbirini taşımayan". Panel bu satırı okumadığı sürece
             * güncellemede siliniyor ve eşleşme sessizce tersine dönüyordu.
             */
            'invert_match' => ['boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    public function memberList(): array
    {
        return array_values((array) $this->input('members', []));
    }

    public function invertMatch(): bool
    {
        return $this->boolean('invert_match');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'community adı', 'members' => 'üyeler', 'invert_match' => 'ters eşleşme'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Bu switchte aynı adlı bir community zaten var.',
            'members.*.regex' => 'Üye “65000:666” biçiminde ya da bilinen bir ad olmalı.',
        ];
    }
}
