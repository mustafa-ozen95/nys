<?php

declare(strict_types=1);

namespace App\Services\Sunucu\Surucu;

use App\Models\Server;
use App\Services\Sunucu\Surucu\Concerns\WindowsHesaplari;
use App\Services\Sunucu\Surucu\Concerns\WinRmIle;

/**
 * Windows yerel hesapları — WinRM üzerinden.
 *
 * NEDEN WinRM, NEDEN RDP DEĞİL: RDP bir ekran protokolüdür, komut
 * çalıştırmaz. Sunucu kaydının bağlantı protokolü RDP olsa da parola değişimi
 * WinRM'den geçer ve WinRM Windows'ta zaten açık gelir — hedef makineye
 * hiçbir şey kurulmaz, hiçbir ayar değiştirilmez. Bu yüzden PORT DA KAYITTAN
 * ALINMAZ (bkz. WinRmIle).
 *
 * ÖLÇÜLDÜ (Windows Server 2019 build 17763, WinRM 5985,
 * AllowUnencrypted=false): NTLM ile kimlik doğrulanıp mesaj şifrelemesi
 * açıkken kabuk açılıyor, PowerShell çalışıyor, parola standart girdiden
 * geçiyor.
 */
class WindowsSurucusu implements SunucuSurucusu
{
    use WindowsHesaplari;
    use WinRmIle;

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
     * O kaydı 1 yapan kurulumlarda ayar açılabilir: SUNUCU_WINRM_KURTARMA=true.
     */
    public function kurtarmaDestekler(): bool
    {
        return (bool) config('sunucu.winrm.kurtarma', false);
    }

    public function engel(Server $sunucu): ?string
    {
        return match (true) {
            blank($sunucu->ip_address) => 'Adres tanımlı değil.',
            blank($sunucu->connection_username) => 'Kullanıcı adı tanımlı değil.',
            blank($sunucu->connection_password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            default => null,
        };
    }
}
