<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * TLS sertifikasının kalan süresi.
 *
 * Değer, sunucudaki sertifika bekçisinin haftalık yazdığı dosyadan okunur. Panel sertifikayı KENDİSİ sorgulasaydı her istekte
 * dışarı bir TLS el sıkışması gerekirdi; dosyayı okumak bedava.
 *
 * Dosya yoksa (bağlanmamış kurulum, eski sürüm) hiçbir uyarı basılmaz —
 * eksik veri yüzünden ekranda yanlış alarm çıkmasın.
 */
class CertificateStatus
{
    /** Bu günden azı kaldıysa uyarı basılır. */
    public const THRESHOLD = 21;

    private const CACHE = 'sertifika-durumu';

    /** @return array{veren: string, bitis: string, kalan_gun: int, ic_ca: bool}|null */
    public static function read(): ?array
    {
        return Cache::remember(self::CACHE, now()->addMinutes(30), static function (): ?array {
            /*
             * storage/app ALTINDA DEĞİL: açılış betiği o dizinde `chown -R`
             * yapıyor ve salt okunur bağlanmış bir dosya bunu reddediyor;
             * konteyner açılışta düşüp yeniden başlatma döngüsüne girer.
             */
            $path = '/etc/dc/sertifika-durum.json';

            if (! is_readable($path)) {
                return null;
            }

            $veri = json_decode((string) file_get_contents($path), true);

            return is_array($veri) && isset($veri['kalan_gun']) ? $veri : null;
        });
    }

    /**
     * Uyarı basılmalı mı?
     *
     * İç CA'da basılmaz: Caddy kendi sertifikasını kendi yeniliyor, kullanıcının
     * yapacağı bir şey yok ve her ay çıkan bir uyarı okunmaz hâle gelirdi.
     */
    public static function warn(): bool
    {
        $d = self::read();

        return $d !== null
            && ($d['ic_ca'] ?? false) === false
            && (int) $d['kalan_gun'] < self::THRESHOLD;
    }
}
