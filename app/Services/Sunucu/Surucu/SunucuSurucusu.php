<?php

declare(strict_types=1);

namespace App\Services\Sunucu\Surucu;

use App\Models\Server;
use App\Services\Sunucu\SunucuOturumu;

/**
 * Bir sunucuda hesap açıp parola değiştirebilen sürücü.
 *
 * Sürücünün işi YALNIZCA makineyle konuşmaktır: sırayı, doğrulamayı, geri
 * almayı ve kayda yazmayı SunucuParolaDondurucu yönetir. Böylece yeni bir
 * işletim sistemi eklemek güvenlik sırasını yeniden düşünmeyi gerektirmez.
 */
interface SunucuSurucusu
{
    /** Bu sürücü sunucuyu işleyebilir mi? Eksik alan varsa nedeni döner. */
    public function engel(Server $sunucu): ?string;

    /** Verilen kimlikle oturum açar. Açılamazsa DondurmeHatasi atar. */
    public function ac(Server $sunucu, string $kullanici, string $parola): SunucuOturumu;

    /** Tam yetkili bir kurtarma hesabı açar. */
    public function kurtarmaAc(SunucuOturumu $oturum, string $kullanici, string $parola): void;

    /** Bir hesabın parolasını değiştirir. */
    public function parolaDegistir(SunucuOturumu $oturum, string $kullanici, string $yeniParola): void;

    /** Kurtarma hesabını siler. */
    public function kurtarmaSil(SunucuOturumu $oturum, string $kullanici): void;

    /**
     * Verilen kimlikle GERÇEKTEN girilebiliyor mu.
     *
     * İstisna ATMAZ, false döner: döndürücü bu yanıta bakıp geri alma kararı
     * veriyor; istisna atsaydı kurtarma yolu hiç çalışmazdı.
     */
    public function dogrula(Server $sunucu, string $kullanici, string $parola): bool;

    /**
     * Bu platformda kurtarma hesabı açılabilir mi?
     *
     * Linux'ta açılabilir. Windows'ta VARSAYILAN OLARAK KAPALIDIR; gerekçesi
     * WindowsSurucusu'nde ölçümüyle birlikte yazılı.
     */
    public function kurtarmaDestekler(): bool;
}
