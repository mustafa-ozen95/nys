<?php

declare(strict_types=1);

namespace App\Services\Server\Driver\Concerns;

use App\Models\Server;
use App\Services\Server\RotationError;
use App\Services\Server\ServerSession;
use Illuminate\Support\Facades\Process;

/**
 * WinRM taşıması — Windows sunucular için.
 *
 * Sunucu `AllowUnencrypted=false` olduğunda kimlik doğrulandıktan SONRA
 * mesajın da NTLM oturum anahtarıyla mühürlenmesini ister. Bunu PHP'de elle
 * yazmak parola taşıyan bir yola el yapımı kripto koymak olurdu; iş, olgun bir
 * istemciyi (pywinrm) çağıran küçük bir yardımcıya verilir.
 *
 * SSH taşımasıyla AYNI yüzeyi sunar (ac/dogrula/powerShellCalistir), böylece
 * Windows komutları tek yerde kalır (bkz. WindowsAccounts).
 *
 * SSH'ten bir farkı var: kalıcı bir oturum yoktur. Her çağrı kendi kabuğunu
 * açıp kapatır; oturum nesnesi yalnızca kimliği taşır.
 */
trait WinRmWith
{
    public function ac(Server $sunucu, string $kullanici, string $parola): ServerSession
    {
        /* Kimlik burada sınanır: bağlanamıyorsak tur daha başlamadan durmalı. */
        $this->call($sunucu, $kullanici, $parola, ['action' => 'verify'], 'Bağlantı kurulamadı.');

        return new ServerSession($sunucu, $kullanici, $parola, static fn () => null);
    }

    public function dogrula(Server $sunucu, string $kullanici, string $parola): bool
    {
        try {
            $this->ac($sunucu, $kullanici, $parola)->kapat();

            return true;
        } catch (RotationError) {
            return false;
        }
    }

    protected function powerShellCalistir(
        ServerSession $oturum,
        string $encodedCommand,
        string $failure,
        string $stdin,
    ): void {
        /** @var Server $sunucu */
        $sunucu = $oturum->tutamac;

        $sonuc = $this->call($sunucu, $oturum->kullanici, $oturum->parola, [
            'action' => 'run',
            'program' => 'powershell.exe',
            'arguments' => ['-NonInteractive', '-NoProfile', '-EncodedCommand', $encodedCommand],
            'stdin' => $stdin,
        ], $failure);

        if ((int) ($sonuc['status'] ?? 1) !== 0) {
            /*
             * Çıktı hata mesajına KOYULMAZ: komut girdiyi yankılamış olabilir
             * ve o yankıda parola vardır. Mesaj kullanıcıya gösteriliyor.
             */
            throw new RotationError($failure.' Hedef makine komutu reddetti (kod '.($sonuc['status'] ?? '?').').');
        }
    }

    /**
     * Yardımcıyı çağırır.
     *
     * İstek STANDART GİRDİDEN gider: parola komut satırına yazılsaydı bu
     * sunucunun süreç listesinde görünürdü.
     *
     * @param  array<string, mixed>  $istek
     * @return array<string, mixed>
     */
    private function call(
        Server $sunucu,
        string $kullanici,
        string $parola,
        array $istek,
        string $failure,
    ): array {
        $ayar = config('server.winrm');

        $sonuc = Process::timeout((int) $ayar['timeout'] + 15)
            ->env(['OPENSSL_CONF' => $ayar['openssl_conf']])
            ->input(json_encode([
                'host' => $sunucu->ip_address,
                /*
                 * PORT KAYITTAN ALINMAZ.
                 *
                 * Sunucu kaydındaki port RDP'nin portudur (3389) ve WinRM
                 * oraya bağlanmaz. Kayıttan okunsaydı her Windows sunucusunda
                 * tur "makineye ulaşılamadı" ile düşerdi.
                 */
                'port' => $ayar['port'],
                'username' => $kullanici,
                'password' => $parola,
                'timeout' => (int) $ayar['timeout'],
                ...$istek,
            ], JSON_THROW_ON_ERROR))
            ->run([$ayar['python'], $ayar['runner']]);

        if (! $sonuc->successful()) {
            throw new RotationError($failure.' WinRM yardımcısı çalıştırılamadı.');
        }

        $yanit = json_decode(trim($sonuc->output()), true);

        if (! is_array($yanit)) {
            throw new RotationError($failure.' WinRM yardımcısı anlaşılmayan bir yanıt verdi.');
        }

        if (($yanit['ok'] ?? false) !== true) {
            throw new RotationError($failure.' '.$this->humanize((string) ($yanit['error'] ?? '')));
        }

        return $yanit;
    }

    /**
     * Kütüphane hatasını insanca bir cümleye çevirir.
     *
     * Ham Python istisnası kullanıcıya gösterilecek bir metin değil; sık
     * görülen üç durum kendi cümlesini alır, gerisi kısaltılır.
     */
    private function humanize(string $error): string
    {
        return match (true) {
            str_contains($error, '401') => 'Kimlik doğrulaması reddedildi.',
            str_contains($error, 'Max retries') || str_contains($error, 'ConnectionError') => 'Makineye ulaşılamadı.',
            str_contains($error, 'pywinrm yüklü değil') => $error,
            default => str($error)->limit(120)->value(),
        };
    }
}
