<?php

declare(strict_types=1);

namespace App\Services\Server\Driver;

use App\Models\Server;
use App\Services\Server\Driver\Concerns\WindowsAccounts;
use App\Services\Server\Driver\Concerns\WinRmWith;

/**
 * Windows yerel hesapları — WinRM üzerinden.
 *
 * NEDEN WinRM, NEDEN RDP DEĞİL: RDP bir ekran protokolüdür, komut
 * çalıştırmaz. Sunucu kaydının bağlantı protokolü RDP olsa da parola değişimi
 * WinRM'den geçer ve WinRM Windows'ta zaten açık gelir — hedef makineye
 * hiçbir şey kurulmaz, hiçbir ayar değiştirilmez. Bu yüzden PORT DA KAYITTAN
 * ALINMAZ (bkz. WinRmWith).
 *
 * ÖLÇÜLDÜ (Windows Server 2019 build 17763, WinRM 5985,
 * AllowUnencrypted=false): NTLM ile kimlik doğrulanıp mesaj şifrelemesi
 * açıkken kabuk açılıyor, PowerShell çalışıyor, parola standart girdiden
 * geçiyor.
 */
class WindowsDriver implements ServerDriver
{
    use WindowsAccounts;
    use WinRmWith;

    /**
     * Kurtarma hesabı bu yolda VARSAYILAN OLARAK KAPALIDIR.
     *
     * ÖLÇÜLDÜ (Windows Server 2019): yeni açılan yerel yönetici
     * hesabı makinede oluşuyor ve Administrators grubuna giriyor, ama
     * UZAKTAN yönetici yetkisi alamıyor — Windows, yerleşik Administrator
     * dışındaki yerel hesapların uzak jetonunu kısıtlıyor
     * (LocalAccountTokenFilterPolicy tanımsızken). Kurtarma hesabı açılıp
     * kanıt aşamasında düşer ve tur boşuna iptal olur.
     *
     * O kaydı 1 yapan kurulumlarda ayar açılabilir: SERVER_WINRM_RECOVERY=true.
     */
    public function recoverySupports(): bool
    {
        return (bool) config('server.winrm.recovery', false);
    }

    public function engel(Server $server): ?string
    {
        return match (true) {
            blank($server->ip_address) => 'Adres tanımlı değil.',
            blank($server->connection_username) => 'Kullanıcı adı tanımlı değil.',
            blank($server->connection_password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            default => null,
        };
    }
}
