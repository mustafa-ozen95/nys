<?php

declare(strict_types=1);

namespace App\Support\Netconf;

/**
 * Cihaz yapılandırma metnindeki sırları maskeler.
 *
 * Cihazın döndürdüğü FARK etkinlik kaydına yazılır: ne yapıldığını panelin
 * niyetiyle değil cihazın kabul ettiği değişiklikle anlatmak için. Ama fark
 * bir sır taşıyabilir — BGP oturum anahtarı, SNMP community, parola. Kayıt
 * silinemez ve dışa aktarılabilir olduğu için sır oraya HİÇ girmemeli.
 *
 * Junos sırları yapılandırmada "$9$..." (reversible) biçiminde tutar; bu
 * biçim çözülebilir olduğu için düz metin kadar hassastır.
 *
 * Maskeleme metnin geri kalanını korur: hangi satırın değiştiği görünür,
 * değerin kendisi görünmez.
 */
final class ConfigurationRedactor
{
    /**
     * Değeri gizlenecek Junos anahtar kelimeleri.
     *
     * Liste eksik kalırsa sır kayda düşer; yeni bir sır alanı eklendiğinde
     * buraya da eklenir. Testler bunu kilitler.
     *
     * @var list<string>
     */
    private const SECRET_STATEMENTS = [
        'authentication-key',
        'authentication-password',
        'community',
        'encrypted-password',
        'key',
        'plain-text-password',
        'plain-text-password-value',
        'pre-shared-key',
        'secret',
        'snmp-community-string',
    ];

    public const MASK = '••••';

    public static function apply(string $configuration): string
    {
        // "authentication-key "$9$abc";" → "authentication-key ••••;"
        $anahtarlar = implode('|', array_map(
            static fn (string $statement): string => preg_quote($statement, '/'),
            self::SECRET_STATEMENTS,
        ));

        /*
         * Değer ";" ile bitebilir, bir blok açabilir ("{") ya da satır
         * sonunda durabilir. Yalnızca ";" beklenirse Junos'un blok biçimi
         * kaçar — SNMP community tam olarak öyle basılıyor:
         *   + community "gizli" { authorization read-only; }
         */
        $sonuc = preg_replace(
            '/\b('.$anahtarlar.')\s+("[^"]*"|\S+?)(?=\s*[;{]|\s*$)/mi',
            '$1 '.self::MASK,
            $configuration,
        ) ?? $configuration;

        /*
         * Kelime eşleşmesinden kaçan "$9$..." / "$1$..." değerleri de
         * maskelenir: Junos onları satır başında ya da beklenmedik bir
         * bağlamda da basabilir.
         */
        return preg_replace('/"?\$[19]\$[^\s";]+"?/', self::MASK, $sonuc) ?? $sonuc;
    }
}
