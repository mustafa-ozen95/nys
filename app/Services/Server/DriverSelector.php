<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\ServerOs;
use App\Models\Server;
use App\Services\Server\Driver\LinuxDriver;
use App\Services\Server\Driver\ServerDriver;
use App\Services\Server\Driver\WindowsDriver;

/**
 * Sunucunun işletim sistemine göre sürücüyü seçer.
 *
 * SEÇİM İŞLETİM SİSTEMİNDEN YAPILIR, BAĞLANTI PROTOKOLÜNDEN DEĞİL. Kayıttaki
 * protokol "kullanıcı buraya nasıl bağlanıyor" sorusunun cevabı (SSH ya da
 * RDP); parolayı değiştirmek başka bir iştir ve Windows'ta RDP üzerinden
 * yapılamaz — komut çalıştırmayan bir ekran protokolüdür. Protokole bakan bir
 * seçim, bütün Windows sunucularını "sürücü yok" diyerek dışarıda bırakırdı.
 */
class DriverSelector
{
    public function icin(Server $sunucu): ServerDriver
    {
        return match ($sunucu->os_type) {
            ServerOs::Windows => app(WindowsDriver::class),
            default => app(LinuxDriver::class),
        };
    }

    /**
     * Bu sunucunun parolası değiştirilebilir mi? Değiştirilemezse NEDENİ.
     *
     * Neden ekranda yazar: "düğme yok" demek, kullanıcının eksik olanı
     * bulmak için kaydı tek tek açması demekti.
     */
    public function engel(Server $sunucu): ?string
    {
        return $this->icin($sunucu)->engel($sunucu);
    }
}
