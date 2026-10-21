<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cihaza yazılacak SNMP adı.
 *
 * Değer doğrudan Junos yapılandırmasına gireceği için dar tutulur: harf ya da
 * rakamla başlar, içinde yalnızca harf, rakam, nokta, tire ve alt çizgi
 * bulunur. Boşluk ve tırnak kabul edilmez.
 */
class SwitchSnmpIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function location(): ?string
    {
        return $this->string('location')->trim()->toString() ?: null;
    }

    public function contact(): ?string
    {
        return $this->string('contact')->trim()->toString() ?: null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],

            /*
             * Konum ve iletişim izleme sisteminde görünür ve sahada cihazı
             * arayan kişiye yol gösterir. Serbest metindir; XML kaçışı
             * yazıcıda yapılır.
             */
            'location' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'SNMP adı',
            'location' => 'konum',
            'contact' => 'iletişim',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'SNMP adı harf ya da rakamla başlamalı; yalnızca harf, rakam, nokta, tire ve alt çizgi içerebilir.',
        ];
    }
}
