<?php

declare(strict_types=1);

namespace App\Services\Sunucu\Surucu\Concerns;

use App\Services\Sunucu\DondurmeHatasi;
use App\Services\Sunucu\SunucuOturumu;

/**
 * Windows yerel hesap komutları — taşımadan BAĞIMSIZ.
 *
 * Komutlar taşımadan ayrı durur: NYS bugün yalnızca WinRM kullanıyor ama
 * komutların kendisi taşımayı umursamıyor ve ayrı durmaları, ileride bir
 * OpenSSH yolu eklenirse komutların yeniden yazılmasını gerektirmez.
 *
 * İki ayrıntı komutların tamamını belirliyor:
 *
 *  1. Parola KOMUT SATIRINA YAZILMAZ. `net user kullanici parola` en bilinen
 *     yol ama parolayı hedef makinenin süreç listesine düşürür. PowerShell
 *     onu standart girdiden okur.
 *
 *  2. Yönetici grubunun adı TÜRKÇE Windows'ta "Administrators" DEĞİLDİR.
 *     Grup, yerelleştirmeden etkilenmeyen tanınmış kimliğiyle (S-1-5-32-544)
 *     bulunur; adına göre aramak Türkçe kurulumda sessizce başarısız olurdu.
 */
trait WindowsHesaplari
{
    /** Yerleşik Administrators grubunun tanınmış kimliği. */
    private const ADMIN_GROUP_SID = 'S-1-5-32-544';

    public function kurtarmaAc(SunucuOturumu $oturum, string $kullanici, string $parola): void
    {
        /* Bu adı BİZ ürettik; dar süzgeçten geçmeli. */
        $this->urettigimizAdiDenetle($kullanici);

        $ad = $this->adiKacir($kullanici);

        $this->run($oturum, <<<PS
            \$p = ConvertTo-SecureString ([Console]::In.ReadLine()) -AsPlainText -Force
            New-LocalUser -Name '{$ad}' -Password \$p -PasswordNeverExpires -AccountNeverExpires | Out-Null
            \$grup = (Get-LocalGroup -SID '{$this->adminGroupSid()}').Name
            Add-LocalGroupMember -Group \$grup -Member '{$ad}'
            PS, 'Kurtarma hesabı açılamadı.', $parola);
    }

    public function parolaDegistir(SunucuOturumu $oturum, string $kullanici, string $yeniParola): void
    {
        $ad = $this->adiKacir($kullanici);

        $this->run($oturum, <<<PS
            \$p = ConvertTo-SecureString ([Console]::In.ReadLine()) -AsPlainText -Force
            Set-LocalUser -Name '{$ad}' -Password \$p
            PS, 'Parola değiştirilemedi.', $yeniParola);
    }

    public function kurtarmaSil(SunucuOturumu $oturum, string $kullanici): void
    {
        $ad = $this->adiKacir($kullanici);

        $this->run($oturum, "Remove-LocalUser -Name '{$ad}'", 'Kurtarma hesabı silinemedi.');
    }

    /**
     * PowerShell betiğini çalıştırır.
     *
     * Betik base64 (UTF-16LE) olarak gönderilir: SSH yolunda Windows'un
     * varsayılan kabuğu cmd.exe ve tırnak kaçışı iki kabuktan geçerken
     * sessizce bozuluyor. -EncodedCommand bu sorunu tümüyle ortadan kaldırır
     * ve iki taşımada da aynı betiği kullanabilmemizi sağlar.
     *
     * Parola betiğe GİRMEZ; standart girdiden okunur.
     */
    private function run(SunucuOturumu $oturum, string $script, string $failure, ?string $stdin = null): void
    {
        $wrapped = "\$ErrorActionPreference = 'Stop'\ntry {\n{$script}\n exit 0\n} catch { exit 1 }";
        $encoded = base64_encode(mb_convert_encoding($wrapped, 'UTF-16LE', 'UTF-8'));

        $this->powerShellCalistir($oturum, $encoded, $failure, $stdin === null ? '' : $stdin."\n");
    }

    /** Kodlanmış betiği taşımanın kendi yoluyla çalıştırır. */
    abstract protected function powerShellCalistir(
        SunucuOturumu $oturum,
        string $encodedCommand,
        string $failure,
        string $stdin,
    ): void;

    private function adminGroupSid(): string
    {
        return self::ADMIN_GROUP_SID;
    }

    /**
     * KAYITTAKİ adı PowerShell'e güvenle gömer.
     *
     * VAR OLAN hesaplara dar bir süzgeç (`[A-Za-z0-9._-]`) UYGULANMAZ:
     * Windows kullanıcı adları Unicode'dur. `YÖNETİM` gibi Türkçe harfli bir
     * hesap (ör. `PC-01` makinesinde) böyle bir süzgece takılır ve tur,
     * makineye hiç bağlanmadan "hesap adı geçersiz" diyerek düşerdi. Adı biz
     * seçmedik; kayıttaki hesap neyse odur.
     *
     * Onun yerine YALNIZCA Windows'un kendi yasakladıkları reddedilir
     * (" / \ [ ] : ; | = , + * ? < > ve denetim karakterleri) ve ad tek
     * tırnaklı PowerShell dizgesine kaçırılarak konur — orada tek kaçış
     * kuralı tırnağı İKİLEMEKTİR. Betik base64 (UTF-16LE) gidiyor; araya
     * kabuk girmiyor, bu yüzden başka kaçış gerekmiyor.
     */
    private function adiKacir(string $kullanici): string
    {
        $ad = trim($kullanici);

        /*
         * Yasaklı küme DÜZ LİSTE, düzenli ifade değil: aynı karakterlerin
         * yarısı hem PCRE'de hem PHP dizgesinde kaçış istiyor ve o kaçışların
         * biri eksik yazıldığında ifade sessizce bozuluyor (denendi).
         */
        $yasak = '"/\\[]:;|=,+*?<>';

        if ($ad === ''
            || mb_strlen($ad) > 20
            || strpbrk($ad, $yasak) !== false
            || preg_match('/[\x00-\x1F\x7F]/u', $ad) === 1) {
            throw new DondurmeHatasi('Hesap adı Windows için geçersiz ya da gönderilemeyecek bir karakter içeriyor.');
        }

        return str_replace("'", "''", $ad);
    }

    /**
     * BİZİM ÜRETTİĞİMİZ ad — dar süzgeç burada yerinde.
     *
     * Kurtarma hesabının adını panel seçiyor; beklenmedik bir şey çıkarsa bu
     * bizim hatamızdır ve makinede tuhaf adlı bir hesap açmadan önce durmalıyız.
     */
    private function urettigimizAdiDenetle(string $kullanici): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,20}$/', $kullanici) !== 1) {
            throw new DondurmeHatasi('Üretilen kurtarma hesabı adı geçersiz.');
        }
    }
}
