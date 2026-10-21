<?php

declare(strict_types=1);

namespace App\Services\Server\Driver;

use App\Models\Server;
use App\Services\Server\ServerSession;

/**
 * Bir sunucuda hesap açıp parola değiştirebilen sürücü.
 *
 * Sürücünün işi YALNIZCA makineyle konuşmaktır: sırayı, doğrulamayı, geri
 * almayı ve kayda yazmayı ServerPasswordRotator yönetir. Böylece yeni bir
 * işletim sistemi eklemek güvenlik sırasını yeniden düşünmeyi gerektirmez.
 */
interface ServerDriver
{
    /** Bu sürücü sunucuyu işleyebilir mi? Eksik alan varsa nedeni döner. */
    public function engel(Server $sunucu): ?string;

    /** Verilen kimlikle oturum açar. Açılamazsa RotationError atar. */
    public function ac(Server $sunucu, string $kullanici, string $parola): ServerSession;

    /** Tam yetkili bir kurtarma hesabı açar. */
    public function kurtarmaAc(ServerSession $oturum, string $kullanici, string $parola): void;

    /** Bir hesabın parolasını değiştirir. */
    public function parolaDegistir(ServerSession $oturum, string $kullanici, string $yeniParola): void;

    /** Kurtarma hesabını siler. */
    public function kurtarmaSil(ServerSession $oturum, string $kullanici): void;

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
     * WindowsDriver'nde ölçümüyle birlikte yazılı.
     */
    public function kurtarmaDestekler(): bool;
}
