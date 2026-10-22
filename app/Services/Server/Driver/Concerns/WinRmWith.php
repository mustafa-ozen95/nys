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
    public function open(Server $server, string $user, string $password): ServerSession
    {
        /* Kimlik burada sınanır: bağlanamıyorsak tur daha başlamadan durmalı. */
        $this->call($server, $user, $password, ['action' => 'verify'], 'Bağlantı kurulamadı.');

        return new ServerSession($server, $user, $password, static fn () => null);
    }

    public function verify(Server $server, string $user, string $password): bool
    {
        try {
            $this->open($server, $user, $password)->close();

            return true;
        } catch (RotationError) {
            return false;
        }
    }

    protected function powerShellRun(
        ServerSession $session,
        string $encodedCommand,
        string $failure,
        string $stdin,
    ): void {
        /** @var Server $sunucu */
        $server = $session->handle;

        $result = $this->call($server, $session->user, $session->password, [
            'action' => 'run',
            'program' => 'powershell.exe',
            'arguments' => ['-NonInteractive', '-NoProfile', '-EncodedCommand', $encodedCommand],
            'stdin' => $stdin,
        ], $failure);

        if ((int) ($result['status'] ?? 1) !== 0) {
            /*
             * Çıktı hata mesajına KOYULMAZ: komut girdiyi yankılamış olabilir
             * ve o yankıda parola vardır. Mesaj kullanıcıya gösteriliyor.
             */
            throw new RotationError($failure.' Hedef makine komutu reddetti (kod '.($result['status'] ?? '?').').');
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
        Server $server,
        string $user,
        string $password,
        array $request,
        string $failure,
    ): array {
        $setting = config('server.winrm');

        $result = Process::timeout((int) $setting['timeout'] + 15)
            ->env(['OPENSSL_CONF' => $setting['openssl_conf']])
            ->input(json_encode([
                'host' => $server->ip_address,
                /*
                 * PORT KAYITTAN ALINMAZ.
                 *
                 * Sunucu kaydındaki port RDP'nin portudur (3389) ve WinRM
                 * oraya bağlanmaz. Kayıttan okunsaydı her Windows sunucusunda
                 * tur "makineye ulaşılamadı" ile düşerdi.
                 */
                'port' => $setting['port'],
                'username' => $user,
                'password' => $password,
                'timeout' => (int) $setting['timeout'],
                ...$request,
            ], JSON_THROW_ON_ERROR))
            ->run([$setting['python'], $setting['runner']]);

        if (! $result->successful()) {
            throw new RotationError($failure.' WinRM yardımcısı çalıştırılamadı.');
        }

        $response = json_decode(trim($result->output()), true);

        if (! is_array($response)) {
            throw new RotationError($failure.' WinRM yardımcısı anlaşılmayan bir yanıt verdi.');
        }

        if (($response['ok'] ?? false) !== true) {
            throw new RotationError($failure.' '.$this->humanize((string) ($response['error'] ?? '')));
        }

        return $response;
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
