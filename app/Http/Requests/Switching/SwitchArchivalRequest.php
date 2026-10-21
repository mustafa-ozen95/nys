<?php

declare(strict_types=1);

namespace App\Http\Requests\Switching;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bir switchin yapılandırma yedeği ayarı.
 *
 * Adres kimlik içerir; parola gibi davranılır: ekranda gösterilmez, boş
 * bırakıldığında kayıtlı adres korunur. Silmek ayrı bir kutu ister —
 * "boş bıraktım, silinsin" ile "boş bıraktım, dokunma" karıştırılmasın.
 */
class SwitchArchivalRequest extends FormRequest
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
            'enabled' => ['boolean'],
            'reset_site' => ['boolean'],

            /*
             * Junos archive-sites bir URL bekler. Şema doğrulanır: elle
             * yazılan "192.0.2.51/sw1" gibi bir değer cihazda sessizce
             * çalışmaz, her commit'te hata basar.
             */
            'site' => [
                'nullable', 'string', 'max:255',
                'regex:/^(?:ftp|sftp|scp|http|https):\/\/\S+$/i',
                'prohibited_if:reset_site,1',
            ],
        ];
    }

    /**
     * Cihaz kaydına yazılacak alanlar.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $ayarlar = ['archival_enabled' => $this->boolean('enabled')];

        if ($this->boolean('reset_site')) {
            $ayarlar['archival_site'] = null;
        } elseif ($this->filled('site')) {
            $ayarlar['archival_site'] = $this->string('site')->trim()->toString();
        }

        return $ayarlar;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['site' => 'yedek adresi', 'enabled' => 'commit sonrası gönder'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'site.regex' => 'Adres bir şema ile başlamalı: ftp://, scp://, sftp:// veya http://.',
            'site.prohibited_if' => 'Kayıtlı adres silinecekse yeni adres yazılamaz.',
        ];
    }
}
