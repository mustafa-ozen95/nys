<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Database\ConnectionFile;
use App\Services\Database\RemoteDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Veritabanı sekmesi: sına, sonra taşı.
 *
 * Asıl kurallar: sınanmamış bir hedefe taşınmaz, taşı düğmesi sınamaları
 * yeniden yapar, parola ne sayfada ne denetim kaydında görünür. Kopyanın
 * kendisi başlatıcıda yapılır; burada yalnızca isteğin doğru bırakıldığı
 * sınanır.
 */
class DatabaseScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = [
        'host' => 'db.ornek.local',
        'port' => 3306,
        'database' => 'nys_uzak',
        'username' => 'nys',
        'password' => 'Gizli-Parola-123',
    ];

    private string $directory;

    private ConnectionFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/veritabani-'.bin2hex(random_bytes(4));
        $this->file = new ConnectionFile(app('encrypter'), $this->directory);
        $this->instance(ConnectionFile::class, $this->file);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * Ağa çıkmayan sahte: sınamaların sonucu sırayla verilir.
     *
     * @param  list<bool>  $sirayla  her sina() çağrısında hepsi geçiyor mu
     */
    private function fake(array $inOrder = [true], string $source = 'ortam'): RemoteDatabase
    {
        $fake = new class($this->file, $inOrder, $source) extends RemoteDatabase
        {
            public int $test = 0;

            /** @var list<string> */
            public array $operations = [];

            public function __construct(ConnectionFile $file, private array $inOrder, private string $source)
            {
                parent::__construct($file);
            }

            public function info(): array
            {
                return ['kaynak' => $this->source, 'host' => 'db', 'port' => '3306', 'database' => 'nys',
                    'username' => 'nys', 'surum' => '10.11.14-MariaDB', 'boyut' => 2_500_000, 'tablo' => 48, 'ozel' => 0];
            }

            public function sina(array $target, string $operation = 'tasi'): array
            {
                $this->operations[] = $operation;
                $passed = $this->inOrder[min($this->test++, count($this->inOrder) - 1)];

                return [
                    ['ad' => 'Bağlantı', 'tamam' => true, 'ayrinti' => $target['username'].'@'.$target['host']],
                    ['ad' => 'Hedef veritabanı boş', 'tamam' => $passed, 'ayrinti' => $passed ? 'Tablo yok.' : '3 tablo var; dolu bir veritabanının üzerine yazılmaz.'],
                ];
            }
        };

        $this->instance(RemoteDatabase::class, $fake);

        return $fake;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function ekran_yalnizca_yoneticide_acilir(): void
    {
        $this->fake();

        $this->actingAs(User::factory()->create())
            ->get(route('settings.database'))
            ->assertForbidden();

        // Ayar bölümleri devredilebilir; veritabanı taşıması devredilmez.
        $this->actingAs(User::factory()->network()->create())
            ->get(route('settings.database'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('settings.database'))
            ->assertOk()
            ->assertSee('Şu anki veritabanı')
            ->assertSee('Kurulum ayarı')
            ->assertSee('10.11.14-MariaDB')
            ->assertSee('Bağlantıyı sına');
    }

    #[Test]
    public function sinama_gecerse_tasi_dugmesi_gorunur_parola_gorunmez(): void
    {
        $this->fake([true]);

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->followingRedirects()
            ->post(route('settings.database.test'), self::TARGET)
            ->assertOk()
            ->assertSee('Sınamalar geçti')
            ->assertSee('nys@db.ornek.local:3306/nys_uzak hedefine taşı')
            ->assertDontSee('Gizli-Parola-123');
    }

    #[Test]
    public function sinama_gecmezse_sebep_gorunur_tasi_dugmesi_gorunmez(): void
    {
        $this->fake([false]);

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->followingRedirects()
            ->post(route('settings.database.test'), self::TARGET)
            ->assertOk()
            ->assertSee('3 tablo var')
            ->assertSee('geçmedi')
            ->assertDontSee('hedefine taşı');
    }

    #[Test]
    public function konteynerin_kendi_adresi_reddedilir(): void
    {
        $fake = $this->fake();

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->post(route('settings.database.test'), [...self::TARGET, 'host' => 'localhost'])
            ->assertSessionHasErrors('host');

        $this->assertSame(0, $fake->test, 'Sınama koşmamalıydı.');
    }

    #[Test]
    public function untested_not_migrated(): void
    {
        $this->fake();

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->post(route('settings.database.migrate'))
            ->assertSessionHasErrors('veritabani');

        $this->assertNull($this->file->status());
        $this->assertNull($this->file->connection());
    }

    /** NYS'nin flash'ı hata listesini kendisi basıyor; ekranda ikinci bir hata bloğu olmamalı. */
    #[Test]
    public function hata_ekranda_bir_kez_gorunur(): void
    {
        $this->fake();

        $page = $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->followingRedirects()
            ->post(route('settings.database.migrate'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($page, 'Önce bağlantıyı sınayın'));
    }

    #[Test]
    public function tasi_istegi_birakir_ve_yeniden_baslatma_ister(): void
    {
        $this->fake([true]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('settings.database'))
            ->post(route('settings.database.test'), self::TARGET);

        $this->actingAs($admin)
            ->from(route('settings.database'))
            ->post(route('settings.database.migrate'), ['reason' => 'uzak sunucuya geçiş'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(self::TARGET, $this->file->connection());
        $this->assertSame('tasinacak', $this->file->stage());
        $this->assertSame('nys@db.ornek.local:3306/nys_uzak', $this->file->status()['hedef']);
        $this->assertSame($admin->name, $this->file->status()['isteyen']);
        $this->assertFileExists($this->directory.'/restart');

        $record = ActivityLog::query()->where('action', 'veritabani_tasi')->sole();
        $this->assertStringContainsString('nys@db.ornek.local:3306/nys_uzak', $record->description);
        $this->assertSame('uzak sunucuya geçiş', $record->reason);
        $this->assertStringNotContainsString('Gizli-Parola-123', $record->description);

        $this->actingAs($admin)
            ->get(route('settings.database'))
            ->assertSee('istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Sınama ile taşı arasında hedef dolmuş olabilir: taşı sınamaları baştan yapar. */
    #[Test]
    public function tasi_sinamalari_yeniden_yapar(): void
    {
        $fake = $this->fake([true, false]);
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('settings.database'))
            ->post(route('settings.database.test'), self::TARGET);

        $this->actingAs($admin)->from(route('settings.database'))
            ->post(route('settings.database.migrate'))
            ->assertSessionHasErrors('veritabani');

        $this->assertSame(2, $fake->test);
        $this->assertNull($this->file->status());
        $this->assertNull($this->file->connection());
        $this->assertFileDoesNotExist($this->directory.'/restart');
        $this->assertSame(0, ActivityLog::query()->where('action', 'veritabani_tasi')->count());
    }

    #[Test]
    public function tamamlanmis_tasimadan_sonra_form_yok_sonuc_gorunur(): void
    {
        $this->fake(source: 'panel');
        $this->file->stageWrite('tasinacak', null, ['hedef' => 'nys@db.ornek.local:3306/nys_uzak']);
        $this->file->stageWrite('deneniyor', '48 tablo, 1200 satır kopyalandı; iki tarafta sayılar aynı.');
        $this->file->stageWrite('etkin');

        $this->actingAs($this->admin())
            ->get(route('settings.database'))
            ->assertOk()
            ->assertSee('Uzak (panel ayarı)')
            ->assertSee('tamamlandı')
            ->assertSee('48 tablo, 1200 satır kopyalandı')
            ->assertDontSee('Bağlantıyı sına');
    }

    #[Test]
    public function basarisiz_tasimanin_sebebi_gorunur_ve_yeniden_denenebilir(): void
    {
        $this->fake();
        $this->file->stageWrite('tasinacak', null, ['hedef' => 'nys@db.ornek.local:3306/nys_uzak']);
        $this->file->stageWrite('basarisiz', 'Uzak veritabanı boş değil (3 tablo); üzerine yazılmaz.');

        $this->actingAs($this->admin())
            ->get(route('settings.database'))
            ->assertOk()
            ->assertSee('yapılmadı')
            ->assertSee('Uzak veritabanı boş değil (3 tablo)')
            ->assertSee('Bağlantıyı sına');
    }

    #[Test]
    public function sekme_ayarlar_seridinde_gorunur(): void
    {
        $this->fake();

        $this->actingAs($this->admin())
            ->get(route('settings.certificate'))
            ->assertSee(route('settings.database'));
    }

    #[Test]
    public function baglan_anahtar_onayi_ister(): void
    {
        $fake = $this->fake([true]);

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->post(route('settings.database.test'), [...self::TARGET, 'islem' => 'baglan'])
            ->assertSessionHasErrors('anahtar_onay');

        $this->assertSame(0, $fake->test, 'Onaysız sınama koşmamalıydı.');
    }

    /** Yeni kurulum eski kurulumun dolu veritabanına: kopya yok, aşama "baglanacak"; onay penceresi veritabanı adını ister. */
    #[Test]
    public function baglan_istegi_birakir_kopyalamaz(): void
    {
        $fake = $this->fake([true]);
        $yonetici = $this->admin();
        $adres = self::TARGET['username'].'@'.self::TARGET['host'].':'.self::TARGET['port'].'/'.self::TARGET['database'];

        $this->actingAs($yonetici)
            ->from(route('settings.database'))
            ->followingRedirects()
            ->post(route('settings.database.test'), [...self::TARGET, 'islem' => 'baglan', 'anahtar_onay' => '1'])
            ->assertOk()
            ->assertSee('Bağlanmak için aşağıdaki düğmeyi kullanın')
            ->assertSee($adres.' veritabanına bağlan')
            ->assertSee('data-confirm-title="Mevcut veritabanına bağlan"', false)
            ->assertSee('data-confirm-verify="'.self::TARGET['database'].'"', false)
            ->assertDontSee('hedefine taşı')
            ->assertDontSee(self::TARGET['password']);

        $this->actingAs($yonetici)
            ->from(route('settings.database'))
            ->post(route('settings.database.migrate'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'Bağlanma istendi'));

        $this->assertSame(['baglan', 'baglan'], $fake->operations, 'Bağlan düğmesi de sınamaları bağlan olarak yeniden yapmalı.');
        $this->assertSame(self::TARGET, $this->file->connection());
        $this->assertSame('baglanacak', $this->file->stage());
        $this->assertSame('baglan', $this->file->status()['islem']);
        $this->assertFileExists($this->directory.'/restart');
        $kayit = ActivityLog::query()->where('action', 'veritabani_baglan')->sole();
        $this->assertStringContainsString($adres, $kayit->description);
        $this->assertStringNotContainsString(self::TARGET['password'], $kayit->description);

        $this->actingAs($yonetici)
            ->get(route('settings.database'))
            ->assertSee('Son bağlanma')
            ->assertSee('bağlanma istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Taşı da onay penceresiyle: veritabanı adı yazılmadan gönderilmez. */
    #[Test]
    public function tasi_varsayilan_islem_ve_onay_penceresi(): void
    {
        $fake = $this->fake([true]);

        $this->actingAs($this->admin())
            ->from(route('settings.database'))
            ->followingRedirects()
            ->post(route('settings.database.test'), self::TARGET)
            ->assertSee('data-confirm-title="Veritabanını taşı"', false)
            ->assertSee('data-confirm-verify="'.self::TARGET['database'].'"', false);

        $this->assertSame(['tasi'], $fake->operations);
    }

    /** Bağlanılacak hedef bu panelin olmalı ve bu sürümden yeni olmamalı. */
    #[Test]
    public function goc_karsilastirmasi(): void
    {
        $yerel = ['2026_01_01_a', '2026_01_02_b', '2026_01_03_c'];

        $this->assertSame(['ortak' => 3, 'bilinmeyen' => 0, 'bekleyen' => 0], RemoteDatabase::compareMigrations($yerel, $yerel));
        $this->assertSame(['ortak' => 2, 'bilinmeyen' => 0, 'bekleyen' => 1], RemoteDatabase::compareMigrations(['2026_01_01_a', '2026_01_02_b'], $yerel));
        $this->assertSame(['ortak' => 3, 'bilinmeyen' => 1, 'bekleyen' => 0], RemoteDatabase::compareMigrations([...$yerel, '2026_02_01_yeni'], $yerel));
        $this->assertSame(['ortak' => 0, 'bilinmeyen' => 2, 'bekleyen' => 3], RemoteDatabase::compareMigrations(['baska_a', 'baska_b'], $yerel));
    }

    /** Yerel liste "migrate"in gördüğüyle aynı: çalışan şema bu sürümden yeni sayılmamalı. */
    #[Test]
    public function yerel_goc_listesi_calisan_semayla_ortusur(): void
    {
        $yerel = (fn (): array => $this->localMigrations())->call(app(RemoteDatabase::class));
        $calisan = DB::table('migrations')->pluck('migration')->all();

        $this->assertSame(['ortak' => count($calisan), 'bilinmeyen' => 0, 'bekleyen' => 0], RemoteDatabase::compareMigrations($calisan, $yerel));
    }
}
