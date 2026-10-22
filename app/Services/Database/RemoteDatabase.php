<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Panelin veritabanı: şu an nerede, ve uzak bir hedef taşımaya uygun mu?
 *
 * Taşımanın kendisi başlatıcıda yapılır (bkz. <panel>-entrypoint, "Paneldeki
 * veritabanı ayarı" bölümü); bu sınıf yalnızca ÖNCESİNİ sınar. Aynı
 * sınamaları başlatıcı kopyadan önce bir kez daha yapar: sınama ile yeniden
 * başlatma arasında hedef değişebilir.
 *
 * Yetki SORULMAZ, DENENİR: GRANT çıktısını okumak MariaDB ile MySQL arasında,
 * rol ve joker veritabanı adlarıyla ayrışıyor. Göçlerin yaptığı işi (tablo,
 * dizin, yabancı anahtar, değişiklik, silme) küçük bir tabloda yapıp
 * kaldırmak, "yetecek mi" sorusunun tek kesin cevabı.
 */
class RemoteDatabase
{
    /** Laravel'in desteklediği en eski sürümler. */
    private const OLDEST = ['MariaDB' => '10.3', 'MySQL' => '5.7'];

    private const TEST_TABLES = ['dc_baglanti_sinamasi_b', 'dc_baglanti_sinamasi_a'];

    public function __construct(private ConnectionFile $file) {}

    /**
     * Şu anki veritabanı.
     *
     * @return array{kaynak: string, host: string, port: string, database: string, username: string,
     *               surum: ?string, boyut: ?int, tablo: ?int, ozel: ?int}
     */
    public function info(): array
    {
        $setting = (array) config('database.connections.'.config('database.default'));
        $host = (string) ($setting['host'] ?? '');

        // ortam: kurulumun verdiği (DB_*; varsayılan compose'taki db servisi).
        // panel: bu sekmeden taşınmış, bağlantı dosyası ortamı eziyor.
        $source = $this->file->stage() === 'etkin' ? 'panel' : 'ortam';

        $info = [
            'kaynak' => $source,
            'host' => $host,
            'port' => (string) ($setting['port'] ?? ''),
            'database' => (string) ($setting['database'] ?? ''),
            'username' => (string) ($setting['username'] ?? ''),
            'surum' => null,
            'boyut' => null,
            'tablo' => null,
            'ozel' => null,
        ];

        try {
            $info['surum'] = (string) DB::scalar('SELECT VERSION()');
            $row = DB::selectOne('SELECT COUNT(*) AS tablo, COALESCE(SUM(data_length + index_length), 0) AS boyut
                FROM information_schema.tables WHERE table_schema = DATABASE()');
            $info['tablo'] = (int) $row->table;
            $info['boyut'] = (int) $row->size;
            $info['ozel'] = $this->customObjects();
        } catch (Throwable) {
            // Ekran yine açılır; değerler "bilinmiyor" görünür.
        }

        return $info;
    }

    /**
     * Taşımadan önceki sınamalar. Sıra önemli: bağlanamayan hedefte
     * sonrakiler denenmez.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $hedef
     * @return list<array{ad: string, tamam: bool, ayrinti: string}>
     */
    public function sina(array $target): array
    {
        $result = [];
        $source = $this->info();

        $address = $source['host'].':'.$source['port'].'/'.$source['database'];
        $result[] = $this->item(
            'Panel kurulumun veritabanında',
            $source['kaynak'] === 'ortam',
            $source['kaynak'] === 'ortam'
                ? 'Kopya şu anki veritabanından ('.$address.') alınacak.'
                : 'Panel zaten bu sekmeden taşınmış bir veritabanında ('.$address.'); ikinci kez taşınmaz.',
        );

        $same = strtolower($target['host']) === strtolower($source['host'])
            && (string) $target['port'] === $source['port'] && $target['database'] === $source['database'];
        $result[] = $this->item(
            'Hedef başka bir veritabanı',
            ! $same,
            $same ? 'Hedef, panelin şu anki veritabanının kendisi.' : 'Şu anki veritabanından farklı.',
        );

        $result[] = $this->item(
            'Şu anki veritabanında yalnızca tablo var',
            $source['ozel'] === 0,
            match ($source['ozel']) {
                0 => 'Görünüm, yordam, tetikleyici ya da zamanlanmış olay yok.',
                null => 'Şu anki veritabanı okunamadı.',
                default => $source['ozel'].' görünüm/yordam/tetikleyici/olay var; taşıma bunları kopyalamaz.',
            },
        );

        if ($same) {
            return $result;
        }

        try {
            $pdo = $this->connect($target);
        } catch (Throwable $e) {
            $result[] = $this->item('Bağlantı', false, self::cleanMessage($e));

            return $result;
        }

        $result[] = $this->item('Bağlantı', true, $target['username'].'@'.$target['host'].':'.$target['port'].'/'.$target['database']);

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $result[] = $this->versionItem($version);

        $table = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $result[] = $this->item(
            'Hedef veritabanı boş',
            $table === 0,
            $table === 0 ? 'Tablo yok.' : $table.' tablo var; dolu bir veritabanının üzerine yazılmaz.',
        );

        if ($table === 0) {
            $result[] = $this->permissionItem($pdo);
        }

        return $result;
    }

    /** @param  list<array{tamam: bool}>  $sonuc */
    public static function allDone(array $result): bool
    {
        return $result !== [] && collect($result)->every(fn (array $m): bool => $m['tamam']);
    }

    public static function isInner(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['', '127.0.0.1', 'localhost', '::1'], true);
    }

    /** @param  array{host: string, port: int, database: string, username: string, password: string}  $h */
    protected function connect(array $h): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $h['host'], $h['port'], $h['database']),
            $h['username'],
            $h['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    protected function customObjects(): int
    {
        return (int) DB::scalar('SELECT
            (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE())');
    }

    /** @return array{ad: string, tamam: bool, ayrinti: string} */
    private function versionItem(string $version): array
    {
        $round = stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        preg_match('/^\d+\.\d+(\.\d+)?/', $version, $m);
        $sufficient = isset($m[0]) && version_compare($m[0], self::OLDEST[$round], '>=');

        return $this->item(
            'Sürüm',
            $sufficient,
            $round.' '.$version.($sufficient ? '' : ' — en az '.$round.' '.self::OLDEST[$round].' gerekir.'),
        );
    }

    /**
     * Göçlerin ihtiyaç duyduğu işler küçük bir tabloda denenir ve tablolar
     * HER DURUMDA kaldırılır: hedef "boş" sınamasından geçmişti, öyle kalmalı.
     *
     * @return array{ad: string, tamam: bool, ayrinti: string}
     */
    private function permissionItem(PDO $pdo): array
    {
        [$b, $a] = self::TEST_TABLES;
        $step = 'tablo açma';

        try {
            $pdo->exec("CREATE TABLE `$a` (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $step = 'yabancı anahtar';
            $pdo->exec("CREATE TABLE `$b` (id INT UNSIGNED NOT NULL PRIMARY KEY, a_id INT UNSIGNED NOT NULL,
                CONSTRAINT `{$b}_fk` FOREIGN KEY (a_id) REFERENCES `$a` (id) ON DELETE CASCADE) ENGINE=InnoDB");
            $step = 'dizin';
            $pdo->exec("CREATE INDEX `{$a}_ad` ON `$a` (ad)");
            $step = 'tablo değiştirme';
            $pdo->exec("ALTER TABLE `$a` ADD COLUMN ek INT NULL");
            $step = 'yazma';
            $pdo->exec("INSERT INTO `$a` (id, ad) VALUES (1, 'sınama')");
            $pdo->exec("INSERT INTO `$b` (id, a_id) VALUES (1, 1)");
            $step = 'güncelleme';
            $pdo->exec("UPDATE `$a` SET ek = 1 WHERE id = 1");
            $step = 'silme';
            $pdo->exec("DELETE FROM `$a` WHERE id = 1");
            $step = 'tablo kaldırma';
            $pdo->exec("DROP TABLE `$b`, `$a`");
        } catch (Throwable $e) {
            foreach (self::TEST_TABLES as $t) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `$t`");
                } catch (Throwable) {
                }
            }

            return $this->item('Yetki', false, 'Göçlerin ihtiyacı olan "'.$step.'" yapılamadı: '.self::cleanMessage($e));
        }

        return $this->item('Yetki', true, 'Tablo, dizin, yabancı anahtar, değişiklik, yazma ve silme denendi; sınama tabloları kaldırıldı.');
    }

    /** @return array{ad: string, tamam: bool, ayrinti: string} */
    private function item(string $name, bool $done, string $detail): array
    {
        return ['ad' => $name, 'tamam' => $done, 'ayrinti' => $detail];
    }

    /** PDO iletisinin başındaki "SQLSTATE[...]" gürültüsü atılır; parola iletide geçmez. */
    private static function cleanMessage(Throwable $e): string
    {
        return mb_substr(trim((string) preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $e->getMessage())), 0, 300);
    }
}
