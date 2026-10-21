<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;

class ServerPolicy extends BaseResourcePolicy
{
    protected function resource(): string
    {
        return 'servers';
    }

    /**
     * Sunucu parolasını DEĞİŞTİRME yetkisi.
     *
     * "Düzenle" bunu KAPSAMAZ: paneldeki kaydı değiştirmekle uzaktaki makinenin
     * parolasını değiştirmek aynı şey değildir ve ikincisi yanlış giderse
     * makine erişilemez hâle gelir.
     *
     * Çevrimdışı işaretli makineye tur başlatılmaz: turun ilk adımı zaten
     * bağlanmaktır ve kesin başarısız olacak bir işi başlatmak, ekranda
     * gereksiz bir kırmızı satır bırakmaktan ibaret olurdu.
     */
    public function rotate(User $user, Server $server): bool
    {
        return $this->permitsPrivileged($user, 'rotate', $server)
            && $server->status !== ServerStatus::Offline;
    }

    /**
     * Uzak oturum (SSH/RDP) açma yetkisi. Çevrimdışı sunucuya bağlanılmaz.
     */
    public function connect(User $user, Server $server): bool
    {
        return $this->permitsConnect($user, $server)
            && $server->status !== ServerStatus::Offline
            && $server->connection_password !== null;
    }
}
