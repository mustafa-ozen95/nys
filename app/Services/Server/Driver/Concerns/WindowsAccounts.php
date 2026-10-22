<?php

declare(strict_types=1);

namespace App\Services\Server\Driver\Concerns;

use App\Services\Server\RotationError;
use App\Services\Server\ServerSession;

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
trait WindowsAccounts
{
    /** Yerleşik Administrators grubunun tanınmış kimliği. */
    private const ADMIN_GROUP_SID = 'S-1-5-32-544';

    public function recoveryOpen(ServerSession $session, string $user, string $password): void
    {
        /* Bu adı BİZ ürettik; dar süzgeçten geçmeli. */
        $this->generatedNameCheck($user);

        $name = $this->escapeName($user);

        $this->run($session, <<<PS
            \$p = ConvertTo-SecureString ([Console]::In.ReadLine()) -AsPlainText -Force
            New-LocalUser -Name '{$name}' -Password \$p -PasswordNeverExpires -AccountNeverExpires | Out-Null
            \$grup = (Get-LocalGroup -SID '{$this->adminGroupSid()}').Name
            Add-LocalGroupMember -Group \$grup -Member '{$name}'
            PS, 'Kurtarma hesabı açılamadı.', $password);
    }

    public function passwordChange(ServerSession $session, string $user, string $newPassword): void
    {
        $name = $this->escapeName($user);

        $this->run($session, <<<PS
            \$p = ConvertTo-SecureString ([Console]::In.ReadLine()) -AsPlainText -Force
            Set-LocalUser -Name '{$name}' -Password \$p
            PS, 'Parola değiştirilemedi.', $newPassword);
    }

    public function recoveryDelete(ServerSession $session, string $user): void
    {
        $name = $this->escapeName($user);

        $this->run($session, "Remove-LocalUser -Name '{$name}'", 'Kurtarma hesabı silinemedi.');
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
    private function run(ServerSession $session, string $script, string $failure, ?string $stdin = null): void
    {
        $wrapped = "\$ErrorActionPreference = 'Stop'\ntry {\n{$script}\n exit 0\n} catch { exit 1 }";
        $encoded = base64_encode(mb_convert_encoding($wrapped, 'UTF-16LE', 'UTF-8'));

        $this->powerShellRun($session, $encoded, $failure, $stdin === null ? '' : $stdin."\n");
    }

    /** Kodlanmış betiği taşımanın kendi yoluyla çalıştırır. */
    abstract protected function powerShellRun(
        ServerSession $session,
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
    private function escapeName(string $user): string
    {
        $name = trim($user);

        /*
         * Yasaklı küme DÜZ LİSTE, düzenli ifade değil: aynı karakterlerin
         * yarısı hem PCRE'de hem PHP dizgesinde kaçış istiyor ve o kaçışların
         * biri eksik yazıldığında ifade sessizce bozuluyor (denendi).
         */
        $forbidden = '"/\\[]:;|=,+*?<>';

        if ($name === ''
            || mb_strlen($name) > 20
            || strpbrk($name, $forbidden) !== false
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new RotationError('Hesap adı Windows için geçersiz ya da gönderilemeyecek bir karakter içeriyor.');
        }

        return str_replace("'", "''", $name);
    }

    /**
     * BİZİM ÜRETTİĞİMİZ ad — dar süzgeç burada yerinde.
     *
     * Kurtarma hesabının adını panel seçiyor; beklenmedik bir şey çıkarsa bu
     * bizim hatamızdır ve makinede tuhaf adlı bir hesap açmadan önce durmalıyız.
     */
    private function generatedNameCheck(string $user): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,20}$/', $user) !== 1) {
            throw new RotationError('Üretilen kurtarma hesabı adı geçersiz.');
        }
    }
}
