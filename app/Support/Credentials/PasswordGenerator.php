<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use InvalidArgumentException;

/**
 * Cihaz parolası üretir.
 *
 * Uzunluk ve karakter sınıfları ayarlardan gelir (Ayarlar > Cihaz parolası).
 * Üretilen parola, AÇIK OLAN HER SINIFTAN en az bir karakter taşır — yoksa
 * "rakam açık" demek "rakam olabilir" anlamına gelirdi ve üretilen parolanın
 * cihaz kuralını geçip geçmeyeceği şansa kalırdı.
 *
 * CİHAZIN KURALI BURADA UYGULANIR ve kural jn32-test üzerinde DOKUZ ayrı
 * parolayla ölçüldü (12.3R12.4): Junos EN AZ İKİ FARKLI karakter sınıfı
 * ister. Tek sınıflı her parola — yalnız küçük, yalnız büyük, yalnız rakam,
 * yalnız noktalama — aynı hatayla reddedilir: "require change of case,
 * digits or punctuation". İki sınıfın her birleşimi kabul edilir.
 *
 * Bu kural önce yanlış anlaşılmıştı ("büyük+küçük ya da rakam ya da
 * noktalama"): tek bir ölçümden genellenmişti ve yalnız-rakam parolayı
 * geçerli sayıyordu. Cihaz onu da reddediyor.
 *
 * AYRICA BİR ENTROPİ TABANI vardır. Cihaz iki sınıfla sekiz karakteri kabul
 * eder ama böyle bir parola panelin sakladığı en zayıf sır olurdu: Junos onu
 * MD5-crypt ($1$) olarak tutuyor ve yapılandırma yedeği her commit'te
 * cihazdan ÇIKIYOR (bkz. ArchivalWriter). Çevrimdışı kırılabilecek bir özet
 * için uzunluk şart; taban MINIMUM_ENTROPY_BITS ile zorlanır.
 *
 * Ayar ekranı aynı iki kuralı doğrulamada uygular, ama son söz burasıdır:
 * config elle ya da .env üzerinden de bozulabilir.
 *
 * Rastgelelik random_int'tendir: kriptografik olarak güvenli üreteç, mt_rand
 * değil. Parola tahmin edilebilir olursa geri kalan her önlem boşa çıkar.
 */
final class PasswordGenerator
{
    /** Panelin ÜRETTİĞİ parola için en kısa uzunluk; ayar bunun altına inemez. */
    public const MINIMUM_LENGTH = 8;

    /**
     * Cihazın kendi alt sınırı. Panelin ürettiği parola daha uzundur
     * (MINIMUM_LENGTH), ama ELLE girilen bir parolayı cihazdan daha katı
     * reddetmek kullanıcıyı sebepsiz durdurur.
     *
     * Ölçüldü (test1, commit check): "Ab1!" → "minimum password length is 6",
     * "abc123" → kabul.
     */
    public const DEVICE_MINIMUM_LENGTH = 6;

    /**
     * Cihazın karakter sınıfı kuralını ifade eden doğrulama deseni.
     *
     * Junos EN AZ İKİ sınıf ister; kural "tek sınıftan oluşmasın" biçiminde
     * yazılır çünkü dört sınıfla "en az ikisi" tam olarak budur.
     *
     * Ölçüldü (test1, commit check): "12345678", "abcdefgh" ve "ABCDEFGH"
     * hepsi "require change of case, digits or punctuation" ile reddedildi;
     * "abcd1234", "AbcdEfgh" ve "abcdefg!" kabul edildi.
     *
     * Panelin eski deseni yalnızca "rakam/sembol VAR" ya da "hem küçük hem
     * büyük harf VAR" diyordu: "12345678" panelden geçiyor, cihazda ham bir
     * hatayla düşüyordu.
     */
    public const DEVICE_CLASS_PATTERN = '/^(?!\p{Ll}+$)(?!\p{Lu}+$)(?!\d+$)(?![^\p{L}\d]+$).+$/u';

    /**
     * Elle girilen bir cihaz parolası için doğrulama kuralları.
     *
     * Tek yerde durur: aynı cihaz kuralını üç ekranda üç farklı yazmak,
     * birinin yanlış olmasıyla sonuçlanmıştı.
     *
     * @return list<string>
     */
    public static function deviceRules(): array
    {
        return ['min:'.self::DEVICE_MINIMUM_LENGTH, 'max:128', 'regex:'.self::DEVICE_CLASS_PATTERN];
    }

    /**
     * Sınıf kuralı için kullanıcıya gösterilecek cümle.
     */
    public static function deviceRuleMessage(): string
    {
        return 'Cihaz tek çeşit karakterden oluşan parolayı kabul etmiyor: '
            .'küçük harf, büyük harf, rakam ve simgeden en az ikisi bulunmalı.';
    }

    /**
     * Parolanın taşıması gereken en az entropi (bit).
     *
     * Cihaz iki sınıfla sekiz karakteri kabul eder — "23456789" gibi sekiz
     * haneli bir dizi 24 bittir ve tek bir GPU'da saniyeler içinde kırılır.
     * Junos parolayı MD5-crypt olarak tutuyor ve yapılandırma yedeği her
     * commit'te cihazdan çıkıyor; özet çevrimdışı denenebilir. Uzunluk bu
     * yüzden kullanıcının kararına bırakılmaz.
     */
    public const MINIMUM_ENTROPY_BITS = 64;

    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const LOWER_AMBIGUOUS = 'l';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const UPPER_AMBIGUOUS = 'IO';

    private const DIGITS = '23456789';

    private const DIGITS_AMBIGUOUS = '01';

    /**
     * Noktalama havuzu. Kabuk ve URL'de sorun çıkaranlar (tırnak, ters
     * bölü, boşluk, $, `) bilerek YOK: parola saha araçlarına elle
     * yapıştırılıyor.
     */
    private const SYMBOLS = '!#%+,-.:=?@^_~';

    public function generate(?int $length = null): string
    {
        $length ??= (int) config('credentials.rotation.length');
        $classes = $this->classes();

        $this->guard($length, $classes);

        // Her açık sınıftan biri garanti; gerisi havuzun tamamından.
        $characters = array_map($this->pick(...), $classes);
        $pool = implode('', $classes);

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $this->pick($pool);
        }

        return $this->shuffle($characters);
    }

    /**
     * Verilen sınıf birleşimi cihazca kabul edilir mi?
     *
     * Junos EN AZ İKİ farklı karakter sınıfı ister; hangi ikisi olduğu fark
     * etmez (jn32-test üzerinde dokuz parolayla ölçüldü). Tek sınıflı her
     * parola reddedilir.
     */
    public static function satisfiesDevice(bool $lower, bool $upper, bool $digits, bool $symbols): bool
    {
        return count(array_filter([$lower, $upper, $digits, $symbols])) >= 2;
    }

    /**
     * Verilen ayarla üretilecek parolanın entropisi (bit).
     *
     * Havuz büyüklüğünün logaritması çarpı uzunluk. "Her sınıftan en az bir
     * karakter" garantisi entropiyi bir miktar düşürür; bu hesap onu göz ardı
     * eder, yani gerçek entropi buradan biraz DÜŞÜKTÜR. Taban da o yüzden
     * cömert tutulmuştur.
     */
    public static function entropyBits(int $length, int $poolSize): float
    {
        return $poolSize < 2 ? 0.0 : log($poolSize, 2) * $length;
    }

    /**
     * Ayar ekranının sorduğu: bu birleşim ve uzunluk yeterli mi?
     *
     * @return string|null Sorun varsa açıklaması, yoksa null.
     */
    public static function problemWith(int $length, bool $lower, bool $upper, bool $digits, bool $symbols): ?string
    {
        if (! array_filter([$lower, $upper, $digits, $symbols])) {
            return 'En az bir karakter sınıfı açık olmalı.';
        }

        if (! self::satisfiesDevice($lower, $upper, $digits, $symbols)) {
            return 'Cihaz tek karakter sınıfından oluşan parolayı reddeder; en az iki sınıf açık olmalı.';
        }

        if ($length < self::MINIMUM_LENGTH) {
            return 'Parola en az '.self::MINIMUM_LENGTH.' karakter olmalı.';
        }

        $havuz = self::poolSizeFor($lower, $upper, $digits, $symbols);

        if (self::entropyBits($length, $havuz) < self::MINIMUM_ENTROPY_BITS) {
            $gerekli = (int) ceil(self::MINIMUM_ENTROPY_BITS / log($havuz, 2));

            return "Bu karakter sınıflarıyla parola çok zayıf kalır; uzunluk en az {$gerekli} olmalı ya da bir sınıf daha açılmalı.";
        }

        return null;
    }

    /**
     * Seçilen sınıfların toplam havuz büyüklüğü.
     *
     * Karışan karakterler ayarı havuzu küçültür ve entropiyi DÜŞÜRÜR; hesap
     * yürürlükteki ayarı okur.
     */
    public static function poolSizeFor(bool $lower, bool $upper, bool $digits, bool $symbols): int
    {
        $ambiguous = (bool) config('credentials.rotation.avoid_ambiguous');

        $boyut = 0;
        $boyut += $lower ? strlen(self::LOWER) + ($ambiguous ? 0 : strlen(self::LOWER_AMBIGUOUS)) : 0;
        $boyut += $upper ? strlen(self::UPPER) + ($ambiguous ? 0 : strlen(self::UPPER_AMBIGUOUS)) : 0;
        $boyut += $digits ? strlen(self::DIGITS) + ($ambiguous ? 0 : strlen(self::DIGITS_AMBIGUOUS)) : 0;
        $boyut += $symbols ? strlen(self::SYMBOLS) : 0;

        return $boyut;
    }

    /**
     * Açık olan karakter sınıflarının havuzları.
     *
     * @return list<string>
     */
    private function classes(): array
    {
        $ambiguous = (bool) config('credentials.rotation.avoid_ambiguous');

        $classes = [];

        if (config('credentials.rotation.lowercase')) {
            $classes[] = self::LOWER.($ambiguous ? '' : self::LOWER_AMBIGUOUS);
        }

        if (config('credentials.rotation.uppercase')) {
            $classes[] = self::UPPER.($ambiguous ? '' : self::UPPER_AMBIGUOUS);
        }

        if (config('credentials.rotation.digits')) {
            $classes[] = self::DIGITS.($ambiguous ? '' : self::DIGITS_AMBIGUOUS);
        }

        if (config('credentials.rotation.symbols')) {
            $classes[] = self::SYMBOLS;
        }

        return $classes;
    }

    /**
     * Cihazın reddedeceği ya da çok zayıf kalacak bir parola ÜRETİLMEZ.
     *
     * Hata burada, kurtarma hesabı açılmadan ÖNCE atılır: tur hiç başlamaz
     * ve cihazda iz kalmaz.
     *
     * @param  list<string>  $classes
     */
    private function guard(int $length, array $classes): void
    {
        $sorun = self::problemWith(
            $length,
            (bool) config('credentials.rotation.lowercase'),
            (bool) config('credentials.rotation.uppercase'),
            (bool) config('credentials.rotation.digits'),
            (bool) config('credentials.rotation.symbols'),
        );

        if ($sorun !== null) {
            throw new InvalidArgumentException($sorun);
        }

        // Sınıf listesi ile config ayrışmışsa (elle çağrı) yine de durulur.
        if ($classes === []) {
            throw new InvalidArgumentException('En az bir karakter sınıfı açık olmalı.');
        }
    }

    private function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    /**
     * Fisher-Yates. shuffle() mt_rand kullanır; garanti edilen karakterlerin
     * yeri tahmin edilebilir kalmasın diye burada da random_int kullanılır.
     *
     * @param  list<string>  $characters
     */
    private function shuffle(array $characters): string
    {
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
