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
    public function engel(Server $server): ?string;

    /** Verilen kimlikle oturum açar. Açılamazsa RotationError atar. */
    public function open(Server $server, string $user, string $password): ServerSession;

    /** Tam yetkili bir kurtarma hesabı açar. */
    public function recoveryOpen(ServerSession $session, string $user, string $password): void;

    /** Bir hesabın parolasını değiştirir. */
    public function passwordChange(ServerSession $session, string $user, string $newPassword): void;

    /** Kurtarma hesabını siler. */
    public function recoveryDelete(ServerSession $session, string $user): void;

    /**
     * Verilen kimlikle GERÇEKTEN girilebiliyor mu.
     *
     * İstisna ATMAZ, false döner: döndürücü bu yanıta bakıp geri alma kararı
     * veriyor; istisna atsaydı kurtarma yolu hiç çalışmazdı.
     */
    public function verify(Server $server, string $user, string $password): bool;

    /**
     * Bu platformda kurtarma hesabı açılabilir mi?
     *
     * Linux'ta açılabilir. Windows'ta VARSAYILAN OLARAK KAPALIDIR; gerekçesi
     * WindowsDriver'nde ölçümüyle birlikte yazılı.
     */
    public function recoverySupports(): bool;
}
