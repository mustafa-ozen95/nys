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
 * Gerçek değerler `database/seeders/local/inventory.php` içinde durur ve
 * o dosya .gitignore'dadır. DOSYA YOKSA SEEDER HİÇBİR ŞEY YAZMAZ — uydurma bir
 * envanter göndermek de doğru olmazdı, yeni bir kuruluma ait olmayan sunucular
 * ve switch'ler bırakırdı. Dosyanın şekli: local/README.md
 */
final class LocalInventory
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
    private static ?string $directory = null;

    /**
     * @return array<int|string, mixed>
     */
    public static function take(string $key): array
    {
        /*
         * Kapı ÖNBELLEĞİN ÖNÜNDE durur.
         *
         * Arkasında dursaydı, aynı süreçte daha önce bir kez okunmuş olması
         * korumayı atlatırdı — testler bunu yakaladı: senaryo tek başına
         * geçiyor, takımın içinde düşüyordu.
         */
        self::gateCheckDo();

        self::$veri ??= self::load();

        /*
         * Dosya yoksa BOŞ dönülür ve seeder hiçbir şey yazmaz. Uydurma bir
         * envanter göndermek de doğru olmazdı: yeni bir kuruluma ait olmayan
         * sunucular ve switch'ler bırakırdı.
         */
        if (self::$veri === []) {
            return [];
        }

        if (! array_key_exists($key, self::$veri)) {
            throw new RuntimeException("Envanter dosyasında '{$key}' anahtarı yok.");
        }

        /** @var array<int|string, mixed> */
        return self::$veri[$key];
    }

    /** Gerçek veri var mı? Testler buna göre ne kadar iddialı olacağına karar verir. */
    public static function isReal(): bool
    {
        return is_file(self::path('inventory.php'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        if (! self::isReal()) {
            return [];
        }

        /** @var array<string, mixed> */
        return require self::path('inventory.php');
    }

    /**
     * ÜRETİMDE ŞABLONA DÜŞMEK YASAK.
     *
     * Şablon uydurma adresler taşıyor (192.168.x). Gerçek dosya yokken
     * üretimde sessizce ona düşülseydi, `db:seed` canlı envanterin yanına var
     * olmayan sunucular ve switch'ler yazardı. Kurulumu yapan kişi dosyayı
     * unutmuşsa bunu ekranda görmeli.
     */
    private static function gateCheckDo(): void
    {
        if (! self::isReal() && app()->environment('production')) {
            throw new RuntimeException(
                'Envanter dosyası yok: database/seeders/local/inventory.php. '
                .'Şablonu (inventory.sample.php) kopyalayıp doldurun; üretimde şablon verisi kullanılmaz.',
            );
        }
    }

    private static function path(string $name): string
    {
        return (self::$directory ?? __DIR__.'/local').'/'.$name;
    }

    /** Testler için: kaynağı başka bir dizine bağlar ve önbelleği düşürür. */
    public static function directoryConfigure(?string $directory): void
    {
        self::$directory = $directory;
        self::$veri = null;
    }
}
