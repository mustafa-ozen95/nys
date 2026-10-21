<?php

declare(strict_types=1);

namespace App\Services\Sunucu\Surucu\Concerns;

use App\Models\Server;
use App\Services\Sunucu\DondurmeHatasi;
use App\Services\Sunucu\SunucuOturumu;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH taşıması.
 *
 * İçindeki her uzun yorum ÖLÇÜLMÜŞ bir tuzağı anlatıyor; yorumu taşıyan
 * satır, gerekçesi okunmadan sadeleştirilmemeli.
 */
trait SshIle
{
    private int $timeout = 15;

    public function ac(Server $sunucu, string $kullanici, string $parola): SunucuOturumu
    {
        $ssh = $this->baglan($sunucu, $kullanici, $parola);

        return new SunucuOturumu($ssh, $kullanici, $parola, static fn () => $ssh->disconnect());
    }

    public function dogrula(Server $sunucu, string $kullanici, string $parola): bool
    {
        try {
            $this->baglan($sunucu, $kullanici, $parola)->disconnect();

            return true;
        } catch (DondurmeHatasi) {
            return false;
        }
    }

    private function baglan(Server $sunucu, string $kullanici, string $parola): SSH2
    {
        $ssh = new SSH2($sunucu->ip_address, $this->baglantiPortu($sunucu), $this->timeout);

        try {
            $ok = $ssh->login($kullanici, $parola);
        } catch (Throwable $e) {
            throw new DondurmeHatasi('Bağlantı kurulamadı: '.$e->getMessage());
        }

        if (! $ok) {
            throw new DondurmeHatasi("Kimlik doğrulaması reddedildi ({$kullanici}).");
        }

        return $ssh;
    }

    /**
     * Komutu çalıştırır ve gerekirse standart girdiden veri gönderir.
     *
     * Parolalar HER ZAMAN buradan geçer, komut satırından değil: komut satırı
     * hedef makinede süreç listesinde görünür.
     *
     * Çıktı hata mesajına KOYULMAZ — bazı komutlar girdiyi yankılıyor ve mesaj
     * kullanıcıya gösteriliyor.
     */
    /**
     * Yükseltme isteminin METNİ.
     *
     * Boş istem (`-p ""`) kullanılamaz: parolanın ne zaman istendiğini
     * anlamanın tek yolu istemi GÖRMEK ve boş bir istem görülemez. Metin
     * makinenin kendi çıktısına benzemeyecek kadar özeldir; yoksa lecture
     * ya da açılış iletisi içindeki bir kelime istem sanılırdı.
     */
    private const SUDO_ISTEM = 'NYS-SUDO-PAROLA:';

    /**
     * Yetki yükseltme ön sözü.
     *
     * Birden çok SSH sürücüsü aynı satırı kendi kopyasıyla taşırsa biri
     * düzeltildiğinde diğeri sessizce eski kalır; satır bu yüzden burada.
     */
    protected function sudoPrelude(): string
    {
        return 'sudo -S -p '.escapeshellarg(self::SUDO_ISTEM).' -v';
    }

    private function execute(
        SunucuOturumu $oturum,
        string $command,
        string $failure,
        string $stdin = '',
        string $prelude = '',
        string $preludeInput = '',
    ): string {
        /** @var SSH2 $ssh */
        $ssh = $oturum->tutamac;

        /*
         * Bağlantı KOPMUŞSA burada durulur.
         *
         * phpseclib kopmuş bir oturumda exec() çağrılınca kendi içinde
         * "Undefined array key 1" PHP uyarısı üretiyor (SSH2.php:2925,
         * exec kanalı artık yok). Kayıtta bu satırı gören insan hatayı
         * phpseclib'de arıyor; oysa neden basit: karşı taraf oturumu
         * düşürmüş.
         */
        if (! $ssh->isConnected()) {
            throw new DondurmeHatasi($failure.': makineyle bağlantı koptu.');
        }

        /*
         * ÇIKIŞ KODU KOMUTUN KENDİSİNDEN OKUNUR, getExitStatus()'tan DEĞİL.
         *
         * phpseclib PTY istendiğinde exec()'i komut bitmeden döndürüyor
         * (SSH2.php: request_pty dalı hemen `return true`). O anda çıkış
         * kodu paketi henüz gelmemişse getExitStatus() null görüp `false`
         * döndürüyor — ve "false" ile "0" ayırt edilmezse BAŞARISIZ BİR
         * KOMUT SESSİZCE BAŞARILI SAYILIR.
         *
         * Komutun sonuna rastgele bir işaret basılır ve çıktı o işaret
         * görünene kadar okunur. Böylece hem komutun bittiğinden hem de
         * gerçek çıkış kodundan emin oluruz. İşaret hiç gelmezse komut
         * tamamlanmamıştır ve bu da hatadır.
         */
        $marker = 'NYSRC'.bin2hex(random_bytes(6));

        /*
         * ÖNCE ÇALIŞAN KOMUT ve GİRDİSİ AYRI AŞAMADA yazılır.
         *
         * ÖLÇÜLDÜ (AlmaLinux 9 / Ubuntu 26.04): `sudo -S` parolayı
         * okuduktan sonra uçbirim girdi tamponunu TEMİZLİYOR (tcflush). İki
         * satır peş peşe yazıldığında ikincisi — komutun kendi girdisi —
         * siliniyor ve komut girdiyi sonsuza kadar bekliyor. Tur "komut
         * tamamlanmadı" diyerek kapanır, oysa bazı makinelerde parola
         * değişmiş olur: panel ile makine ayrışır.
         *
         * Çözüm zamanlamaya değil, İŞARETE dayanır: yükseltme bitip HAZIR
         * satırı görülene kadar beklenir, komutun girdisi ondan sonra yazılır.
         */
        $gate = 'NYSGT'.bin2hex(random_bytes(4));

        $ssh->enablePTY();

        $ssh->exec($prelude === ''
            ? '{ '.$command.' ; }; printf "\n'.$marker.':%s\n" "$?"'
            : '{ '.$prelude.' ; }; printf "\n'.$gate.'\n"; { '.$command.' ; }; printf "\n'.$marker.':%s\n" "$?"');

        $onSoz = '';

        if ($prelude !== '') {
            if ($preludeInput !== '') {
                /*
                 * PAROLA, İSTEM GÖRÜLENE KADAR YAZILMAZ.
                 *
                 * ÖLÇÜLDÜ (AlmaLinux 9.4, sudo 1.9.5): sudo
                 * parolayı okumadan hemen önce uçbirim girdi tamponunu
                 * temizliyor (tcflush). Erken yazılan parola siliniyor, sudo
                 * yeniden soruyor ve elimizde yazacak bir şey kalmadığı için
                 * "Sorry, try again" ile reddediyor; ardından asıl komut
                 * girdisini sonsuza kadar bekliyor ve tur "komut
                 * tamamlanmadı" diyerek 60 saniyede düşüyor.
                 *
                 * Yarış YENİ AÇILAN kurtarma hesaplarında kaçınılmazdır: o
                 * hesap sudo'yu ilk kez kullandığı için sudo önce uzun
                 * "lecture" metnini basıyor, temizleme de ondan sonra
                 * geliyor. Aynı makinede sudo'yu önceden kullanmış bir hesapla
                 * sorun hiç görünmez — bu yüzden akış olağan hesaplarla
                 * çalışıp tam da kurtarma hesabıyla düşebilir.
                 *
                 * Ya istem görülür ya da ön söz parola sormadan biter
                 * (NOPASSWD): ikisinden hangisi önce gelirse ona göre
                 * davranılır, yoksa parolasız makinede boşuna beklenirdi.
                 */
                $onSoz = (string) $ssh->read(
                    '/'.preg_quote(self::SUDO_ISTEM, '/').'|'.$gate.'/',
                    SSH2::READ_REGEX,
                );

                if (! str_contains($onSoz, $gate)) {
                    $ssh->write($preludeInput);
                    $onSoz .= (string) $ssh->read('/'.$gate.'/', SSH2::READ_REGEX);
                }
            } else {
                $onSoz = (string) $ssh->read('/'.$gate.'/', SSH2::READ_REGEX);
            }
        }

        if ($stdin !== '') {
            $ssh->write($stdin);
        }

        $ssh->write("\x04");

        $output = $onSoz.(string) $ssh->read('/'.$marker.':\d+/', SSH2::READ_REGEX);
        $ssh->disablePTY();

        if (preg_match('/'.$marker.':(\d+)/', $output, $m) !== 1) {
            throw new DondurmeHatasi($failure.' Komut tamamlanmadı ya da zaman aşımına uğradı.');
        }

        if ((int) $m[1] !== 0) {
            /*
             * Çıktı hata mesajına KOYULMAZ: PTY açıkken yazdığımız her şey
             * geri yankılanıyor ve o yankıda parola var.
             */
            throw new DondurmeHatasi($failure.' Hedef makine komutu reddetti (kod '.$m[1].').');
        }

        return $output;
    }

    /**
     * Bağlanılacak port.
     *
     * Linux sunucularda kaydın portu SSH portudur. Windows'ta DEĞİLDİR:
     * orada port RDP'nin portudur (3389) ve WinRM oraya bağlanmaz — bu yüzden
     * sürücü kendi portunu söyleyebilir (bkz. WindowsSurucusu).
     */
    protected function baglantiPortu(Server $sunucu): int
    {
        return $sunucu->connection_port ?? $this->varsayilanPort();
    }

    abstract protected function varsayilanPort(): int;
}
