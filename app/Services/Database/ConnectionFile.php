<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

/**
 * Panelin kendi veritabanı ayarı: uzak bağlantı ve taşımanın durumu.
 *
 * İKİ DOSYA, depolama hacminde (storage/app/database):
 *
 *   connection.json  APP_KEY ile ŞİFRELİ: konak, port, veritabanı, kullanıcı, parola
 *   state.json     düz: taşımanın aşaması, zamanı, sebebi; sır içermez
 *
 * Bağlantıyı panel YAZAR, başlatıcı (dc-database) OKUR. Laravel ayarları
 * ortamdan okuduğu için bağlantı açılışta ortama geçer ve conf'taki ya da
 * dağıtım aracındaki DB_* değerlerini ezer; çalışan bir panelin bağlantısı
 * yeniden başlatmadan değişmez.
 *
 * AŞAMALAR (state.json → durum):
 *
 *   tasinacak  panel istedi; başlatıcı bir sonraki açılışta kopyalar
 *   baglanacak panel istedi; başlatıcı KOPYALAMADAN dolu hedefe geçer (yeni
 *              kurulumu eski kurulumun veritabanına bağlamak için)
 *   tasiniyor  başlatıcı kopyalıyor
 *   deneniyor  kopya doğrulandı, uzak veritabanıyla ilk açılış sürüyor
 *   etkin      panel uzak veritabanında; GERİ DÜŞÜLMEZ
 *   basarisiz  taşınmadı, kurulumun veritabanıyla devam; bağlantı dosyası silinir
 *
 * "etkin"den sonra uzak veritabanı açılmazsa panel BEKLER, içeridekine
 * dönmez: iç kopya taşıma anında donmuştur ve oraya dönmek, o andan beri
 * yazılan her şeyi sessizce yok saymak olurdu.
 *
 * Başlatıcı bu sınıfı uygulamayı ayağa kaldırmadan kullanır (vendor
 * autoload + Encrypter); bu yüzden Laravel yardımcılarına yalnızca dizin
 * verilmediğinde başvurur.
 */
class ConnectionFile
{
    public const STATUSES = ['tasinacak', 'baglanacak', 'tasiniyor', 'deneniyor', 'etkin', 'basarisiz'];

    /** Yeni bir istek başlatan aşamalar: önceki isteğin alanları taşınmaz. */
    public const REQUESTS = ['tasinacak', 'baglanacak'];

    /** Bağlantının alanları; başka alan okunmaz, yazılmaz. */
    public const FIELDS = ['host', 'port', 'database', 'username', 'password'];

    private string $directory;

    public function __construct(private Encrypter $password, string $directory = '')
    {
        $this->directory = $directory !== '' ? rtrim($directory, '/') : storage_path('app/database');
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}|null
     *
     * @throws RuntimeException dosya var ama çözülemiyorsa (APP_KEY değişmiş olabilir)
     */
    public function connection(): ?array
    {
        $path = $this->directory.'/connection.json';

        if (! is_file($path)) {
            return null;
        }

        try {
            $veri = json_decode((string) $this->password->decryptString((string) file_get_contents($path)), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Bağlantı dosyası çözülemedi (APP_KEY değişmiş olabilir): '.$path);
        }

        return self::clean((array) $veri);
    }

    /** @param  array<string, mixed>  $baglanti */
    public function connectionWrite(array $connection): void
    {
        $this->write('connection.json', $this->password->encryptString(json_encode(self::clean($connection), JSON_THROW_ON_ERROR)));
    }

    public function connectionDelete(): void
    {
        @unlink($this->directory.'/connection.json');
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $path = $this->directory.'/state.json';
        $veri = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($veri) && in_array($veri['durum'] ?? null, self::STATUSES, true) ? $veri : null;
    }

    public function stage(): ?string
    {
        return $this->status()['durum'] ?? null;
    }

    /**
     * Aşamayı ilerletir; isteyen, hedef gibi önceki alanlar korunur. Açıklama
     * verilmezse öncekisi kalır: "etkin" kopyanın özetini taşımaya devam eder.
     *
     * "basarisiz"da bağlantı dosyası SİLİNİR: içinde parola var ve bir daha
     * okunmayacak; kalırsa yalnızca sızabilir.
     *
     * @param  array<string, mixed>  $ek
     */
    public function stageWrite(string $status, ?string $description = null, array $extra = []): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Bilinmeyen aşama: '.$status);
        }

        $previous = in_array($status, self::REQUESTS, true) ? [] : ($this->status() ?? []);

        $this->write('state.json', json_encode(array_filter([
            ...$previous,
            ...$extra,
            'durum' => $status,
            'aciklama' => $description ?? ($previous['aciklama'] ?? null),
            'zaman' => date('c'),
        ], fn ($v) => $v !== null), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($status === 'basarisiz') {
            $this->connectionDelete();
        }
    }

    /**
     * Konteynerin yeniden başlatılmasını ister.
     *
     * Panel (uygulama kullanıcısı) süreç yöneticisine erişemez; dosyayı root
     * olarak koşan dc-restart görür ve yalnızca bu tek işi yapar.
     */
    public function reStartRequest(): bool
    {
        return $this->write('restart', date('c'), false);
    }

    /**
     * Başlatıcının ortama alacağı satırlar: AD=base64(değer).
     *
     * Değer base64'tür: parolada boşluk, tırnak ya da satır sonu olabilir ve
     * kabuk onu ne eval ile ne de düz okuma ile güvenle taşır.
     */
    public function environmentRows(): string
    {
        $b = $this->connection() ?? throw new RuntimeException('Bağlantı dosyası yok.');

        return implode("\n", [
            'DB_HOST='.base64_encode($b['host']),
            'DB_PORT='.base64_encode((string) $b['port']),
            'DB_DATABASE='.base64_encode($b['database']),
            'DB_USERNAME='.base64_encode($b['username']),
            'DB_PASSWORD='.base64_encode($b['password']),
        ])."\n";
    }

    /**
     * mariadb istemcisinin seçenek dosyası (--defaults-extra-file).
     *
     * Parola komut satırına GİRMEZ: orada süreç listesinden okunur.
     */
    public function clientSetting(): string
    {
        return self::clientText($this->connection() ?? throw new RuntimeException('Bağlantı dosyası yok.'));
    }

    /**
     * mariadb istemci seçenek dosyası: parola komut satırına (ve süreç
     * listesine) hiç girmez. Başlatıcı ortamın verdiği bağlantı için de
     * bunu kullanır (dc-database ortam-istemci).
     *
     * @param  array{host: string, port: int, username: string, password: string}  $b
     */
    public static function clientText(array $b): string
    {
        $quote = fn (string $s): string => '"'.addcslashes($s, "\\\"\n\r\t").'"';

        return "[client]\n"
            .'host='.$quote($b['host'])."\n"
            .'port='.$b['port']."\n"
            .'user='.$quote($b['username'])."\n"
            .'password='.$quote($b['password'])."\n";
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function clean(array $b): array
    {
        return [
            'host' => trim((string) ($b['host'] ?? '')),
            'port' => (int) ($b['port'] ?? 3306) ?: 3306,
            'database' => trim((string) ($b['database'] ?? '')),
            'username' => trim((string) ($b['username'] ?? '')),
            'password' => (string) ($b['password'] ?? ''),
        ];
    }

    /** Geçici dosyaya yazıp yerine taşır: yarım yazılmış bir dosya okunmasın. */
    private function write(string $name, string $content, bool $throw = true): bool
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            return $throw ? throw new RuntimeException('Dizin açılamadı: '.$this->directory) : false;
        }

        $temporary = $this->directory.'/.'.$name.'.'.bin2hex(random_bytes(4));
        $old = umask(0077);

        try {
            $done = @file_put_contents($temporary, $content) !== false && @rename($temporary, $this->directory.'/'.$name);
        } finally {
            umask($old);
        }

        if (! $done) {
            @unlink($temporary);

            return $throw ? throw new RuntimeException('Yazılamadı: '.$this->directory.'/'.$name) : false;
        }

        return true;
    }
}
