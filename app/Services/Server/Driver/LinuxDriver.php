<?php

declare(strict_types=1);

namespace App\Services\Server\Driver;

use App\Models\Server;
use App\Services\Server\Driver\Concerns\SshWith;
use App\Services\Server\RotationError;
use App\Services\Server\ServerSession;

/**
 * Linux sunucu hesaplarını SSH üzerinden yönetir.
 *
 * Ayrıcalık: bağlanan hesap root ise komutlar doğrudan çalışır. Değilse
 * `sudo -S` kullanılır ve parola standart girdiden verilir — bu yüzden
 * döndürme hesabının sudo yetkisi olmalıdır.
 */
class LinuxDriver implements ServerDriver
{
    use SshWith;

    /**
     * Zaman aşımı AYARDAN gelir.
     *
     * Sabit bir süre yazılmaz: yükü yüksek bir makinede `chpasswd` kısa bir
     * süreyi aşabilir; tur "değiştiremedim" diyerek kapanır ama parola
     * makinede DEĞİŞMİŞ olur. Ayardaki değer buraya ulaşmazsa ayar yazılı
     * olduğu hâlde etkisiz kalır.
     */
    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? (int) config('server.ssh_timeout', 30);
    }

    protected function defaultPort(): int
    {
        return 22;
    }

    public function recoverySupports(): bool
    {
        return true;
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

    /**
     * Kurtarma hesabı: kabuğu olan, sudo/wheel grubuna ekli bir kullanıcı.
     *
     * Grup adı dağıtıma göre değişiyor; ikisi de denenir ve biri tutarsa
     * yeter. Hesabın gerçekten işe yaradığı zaten ayrıca KANITLANIYOR
     * (bkz. ServerPasswordRotator), bu yüzden burada iki ihtimali denemek güvenli.
     */
    public function recoveryOpen(ServerSession $session, string $user, string $password): void
    {
        $this->guardName($user);

        $this->run(
            $session,
            "useradd -m -s /bin/sh {$user} && (usermod -aG sudo {$user} || usermod -aG wheel {$user})",
            'Kurtarma hesabı açılamadı.',
        );

        $this->passwordChange($session, $user, $password);
    }

    public function passwordChange(ServerSession $session, string $user, string $newPassword): void
    {
        $this->guardName($user);

        if (preg_match('/[\r\n:]/', $newPassword) === 1) {
            throw new RotationError('Parola bu yöntemle gönderilemeyecek bir karakter içeriyor.');
        }

        /* chpasswd "kullanıcı:parola" satırını standart girdiden okur. */
        $this->run($session, 'chpasswd', 'Parola değiştirilemedi.', "{$user}:{$newPassword}\n");
    }

    public function recoveryDelete(ServerSession $session, string $user): void
    {
        $this->guardName($user);

        /* -f: hesap açık oturumdaysa da silinsin; -r: ev dizini de gitsin. */
        $this->run($session, "userdel -r -f {$user}", 'Kurtarma hesabı silinemedi.');
    }

    /**
     * Komutu gerekiyorsa sudo ile çalıştırır.
     *
     * sudo parolayı standart girdinin İLK satırından okur; komutun kendi
     * girdisi ondan sonra gelir.
     *
     * ÖLÇÜLDÜ (AlmaLinux 9 ve Ubuntu 26.04): girdi tüketen bir
     * komut (chpasswd) `sudo -S` ile çalıştırıldığında SONSUZA KADAR
     * BEKLİYOR. Sudo parolayı okuduktan sonra uçbirim tamponunu temizliyor
     * ve komutun kendi girdisi onunla birlikte siliniyor. Tur
     * "değiştiremedim" diyerek kapanır, oysa bazı makinelerde parola
     * değişmiş olur — panel ile makine ayrışır.
     *
     * Bu yüzden İKİ AŞAMA: önce `sudo -v` ile kimlik doğrulanır (girdide
     * yalnızca parola vardır), sonra komut `sudo -n` ile çalışır ve girdiyi
     * yalnızca o okur. Parola yine komut satırına değil, standart girdiye
     * yazılır.
     *
     * Girdi tüketen komutlar ayrıca boru sarmalıyla beslenir; sebebi
     * aşağıda yazılı (sudo-rs dosya-sonunu iletmiyor).
     */
    private function run(ServerSession $session, string $command, string $failure, string $stdin = ''): void
    {
        $root = $session->user === 'root';

        /*
         * Girdi tüketen komut BORUYLA beslenir.
         *
         * ÖLÇÜLDÜ (Ubuntu 26.04 — sudo-rs 0.2.13): sudo-rs
         * çocuğunu kendi uçbiriminde çalıştırıyor ve dosya-sonunu ona
         * İLETMİYOR; chpasswd girdiyi sonsuza kadar bekliyor. Klasik sudo'da
         * (AlmaLinux 9, sudo 1.9.5) bu sorun yok, ama iki dağıtım için iki
         * ayrı yol tutmak yerine tek yol: kabuk TEK SATIR okur ve komuta
         * boruyla verir; borunun kapanması girdinin bittiğini söyler.
         */
        if ($stdin !== '') {
            $command = 'sh -c '.escapeshellarg('read -r eys_satir; printf "%s\n" "$eys_satir" | '.$command);
        }

        /*
         * ZİNCİRİN TAMAMI sudo ALTINDA.
         *
         * "sudo -n useradd … && (usermod …)" yazıldığında sudo yalnızca İLK
         * komutu yetkili çalıştırır; && sonrası kullanıcının kendi
         * yetkisiyle koşup reddedilir. Root olmayan hesapla kurtarma hesabı
         * açılır ama gruba eklenemez, tur "kod 1" ile düşer ve makinede
         * yarım hesap kalır. Root yolunda sudo eklenmediği için bu
         * görünmez. Girdi alan komut zaten tek kabukta (yukarıda); diğerleri
         * burada sarılır.
         */
        $escalated = $stdin !== '' ? $command : 'sh -c '.escapeshellarg($command);

        $output = $root
            ? $this->execute($session, $command, $failure, $stdin)
            : $this->execute(
                $session,
                'sudo -n '.$escalated,
                $failure,
                $stdin,
                /* Önce kimlik doğrulanır; sudo girdiye o aşamada dokunur. */
                $this->sudoPrelude(),
                $session->password."\n",
            );

        /*
         * Asıl karar çıkış kodundadır (bkz. SshWith::execute).
         * Buradaki metin kontrolü İKİNCİ bir ağdır: sudo bazı hâllerde
         * parolayı reddedip yine de sıfır dönebiliyor.
         */
        $lower = strtolower($output);

        if (str_contains($lower, 'sorry, try again')
            || str_contains($lower, 'a password is required')) {
            throw new RotationError($failure.' Yükseltme parolası kabul edilmedi.');
        }
    }

    /**
     * Kabuğa geçen ad yalnızca güvenli karakterler taşır.
     *
     * Üç genel metot da bunu ÇAĞIRMALI: ad komut satırına doğrudan gömülüyor
     * (useradd/userdel). Sahte sürücüyle yapılan sınamalar bu çağrının
     * gerçekten var olduğunu göremez; gerçek sürücüyle koşan sınama
     * LinuxDriverRealTest'tedir.
     */
    private function guardName(string $user): void
    {
        if (preg_match('/^[a-z_][a-z0-9_-]{0,30}$/', $user) !== 1) {
            throw new RotationError('Hesap adı kabuğa gönderilemeyecek bir karakter içeriyor.');
        }
    }
}
