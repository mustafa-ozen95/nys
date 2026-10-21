<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * ENVANTER VERİSİ DEPODA DURMAZ.
 *
 * Envanter gerçek adresler ve erişim parolaları taşır; depoya erişen herkesin
 * görebileceği bir yerde durmamalı.
 *
 * Gerçek değerler `database/seeders/yerel/envanter.php` içinde durur ve
 * o dosya .gitignore'dadır. DOSYA YOKSA SEEDER HİÇBİR ŞEY YAZMAZ — uydurma bir
 * envanter göndermek de doğru olmazdı, yeni bir kuruluma ait olmayan sunucular
 * ve switch'ler bırakırdı. Dosyanın şekli: yerel/OKUBENI.md
 */
final class YerelEnvanter
{
    /** @var array<string, mixed>|null */
    private static ?array $veri = null;

    /**
     * Dosyaların aranacağı dizin.
     *
     * YALNIZCA TESTLER değiştirir: seeder'ın davranışı "dosya var" ve "dosya
     * yok" hâllerinde ayrı ayrı sınanabilsin diye. Üretimde hep varsayılan
     * dizin kullanılır ve depodaki gerçek dosyaya dokunulmaz.
     */
    private static ?string $dizin = null;

    /**
     * @return array<int|string, mixed>
     */
    public static function al(string $anahtar): array
    {
        /*
         * Kapı ÖNBELLEĞİN ÖNÜNDE durur.
         *
         * Arkasında dursaydı, aynı süreçte daha önce bir kez okunmuş olması
         * korumayı atlatırdı — testler bunu yakaladı: senaryo tek başına
         * geçiyor, takımın içinde düşüyordu.
         */
        self::kapiyiKontrolEt();

        self::$veri ??= self::yukle();

        /*
         * Dosya yoksa BOŞ dönülür ve seeder hiçbir şey yazmaz. Uydurma bir
         * envanter göndermek de doğru olmazdı: yeni bir kuruluma ait olmayan
         * sunucular ve switch'ler bırakırdı.
         */
        if (self::$veri === []) {
            return [];
        }

        if (! array_key_exists($anahtar, self::$veri)) {
            throw new RuntimeException("Envanter dosyasında '{$anahtar}' anahtarı yok.");
        }

        /** @var array<int|string, mixed> */
        return self::$veri[$anahtar];
    }

    /** Gerçek veri var mı? Testler buna göre ne kadar iddialı olacağına karar verir. */
    public static function gercekMi(): bool
    {
        return is_file(self::yol('envanter.php'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function yukle(): array
    {
        if (! self::gercekMi()) {
            return [];
        }

        /** @var array<string, mixed> */
        return require self::yol('envanter.php');
    }

    /**
     * ÜRETİMDE ŞABLONA DÜŞMEK YASAK.
     *
     * Şablon uydurma adresler taşıyor (192.168.x). Gerçek dosya yokken
     * üretimde sessizce ona düşülseydi, `db:seed` canlı envanterin yanına var
     * olmayan sunucular ve switch'ler yazardı. Kurulumu yapan kişi dosyayı
     * unutmuşsa bunu ekranda görmeli.
     */
    private static function kapiyiKontrolEt(): void
    {
        if (! self::gercekMi() && app()->environment('production')) {
            throw new RuntimeException(
                'Envanter dosyası yok: database/seeders/yerel/envanter.php. '
                .'Şablonu (envanter.ornek.php) kopyalayıp doldurun; üretimde şablon verisi kullanılmaz.',
            );
        }
    }

    private static function yol(string $ad): string
    {
        return (self::$dizin ?? __DIR__.'/yerel').'/'.$ad;
    }

    /** Testler için: kaynağı başka bir dizine bağlar ve önbelleği düşürür. */
    public static function dizinAyarla(?string $dizin): void
    {
        self::$dizin = $dizin;
        self::$veri = null;
    }
}
