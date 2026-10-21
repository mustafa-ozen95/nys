<?php

declare(strict_types=1);

namespace App\View\Components\Form;

/**
 * Panelde bir IP adresi seçilen TEK alan.
 *
 * Adresler Adresleme'den gelir ve listeden seçilir; serbest yazım kapalıdır.
 * Bir ekranın gerçekten elle girişe ihtiyacı varsa (sağlayıcının kendi
 * adresi, envanterde olmayan cihaz) $manual açılır ve listenin sonuna
 * "Listede yok — elle gir" seçeneği eklenir. Hangi alanda neden açık
 * olduğunun listesi: .ai/rules/adresleme.md.
 *
 * Mekanizmanın kendisi Picker'dadır; burada yalnızca ADRESE ÖZGÜ metinler
 * durur. Boş liste metni Adresleme'ye yönlendirir: kullanıcıyı "seçilecek
 * bir şey yok" ile bırakmak, nereye gideceğini söylememektir.
 */
class IpPicker extends Picker
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        string $name,
        array $options = [],
        ?string $id = null,
        ?string $value = null,
        bool $manual = false,
        string $placeholder = '',
        string $empty = 'Seçiniz',
        ?string $emptyText = null,
    ) {
        parent::__construct(
            name: $name,
            options: $options,
            id: $id,
            value: $value,
            manual: $manual,
            placeholder: $placeholder,
            empty: $empty,
            emptyText: $emptyText ?? 'Adresleme’de uygun blok yok. Önce Adresleme’den blok tanımlayın.',
            manualAria: 'Adresi elle girin',
        );
    }
}
