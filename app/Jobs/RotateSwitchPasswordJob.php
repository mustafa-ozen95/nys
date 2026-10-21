<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NetworkSwitch;
use App\Models\User;
use App\Services\Credentials\SwitchPasswordRotator;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Bir switchin panel parolasını değiştirir.
 *
 * TEKRAR DENENMEZ (tries = 1). Yarıda kalmış bir tur körlemesine yeniden
 * başlatılırsa cihazda duran kurtarma hesabının parolası ezilir ve cihaza
 * dönüş kapısı kaybolur. Takılan tur ekranda durur; kapatmak `repair()`
 * işidir ve kullanıcı başlatır.
 *
 * Switch başına tektir: aynı cihaza iki tur aynı anda girerse ikisi de
 * diğerinin yazdığı parolayı doğrulamaya çalışır.
 *
 * YÜKÜ ŞİFRELİDİR (ShouldBeEncrypted). Elle verilen parola işin yükünde
 * taşınıyor; kuyruk sürücüsü "database" olduğu için şifrelenmeseydi parola
 * `jobs` tablosunda düz metin dururdu ve iş çerçeve seviyesinde düşerse
 * `failed_jobs` satırında KALICI olurdu. Akıştaki diğer her sır özenle
 * korunuyor; burada bir delik bırakmanın anlamı yok.
 */
class RotateSwitchPasswordJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * İşçinin --timeout'u eşitleme işlerine göre ölçülmüştür (90 s) ve bu tur
     * için ÇOK KISADIR: tur cihazda üç geri alınabilir yazma yapar, yani altı
     * commit ve dokuz NETCONF oturumu.
     *
     * Tur EX4200'de 90 saniyenin altında biter; EX3200, EX2200-48T ve
     * EX2300'de bu sınırı aşar ve işçi tarafından KESİLİR. Kesilen süreç SSH
     * akışının ortasında öldüğü için ekranda "NETCONF bağlantısı koptu:
     * Invalid packet length" yazar ve cihazın suçu sanılır — kurtarma hesabı
     * da cihazda AÇIK kalır.
     *
     * Değer işin KENDİ özelliğidir; aynı kuyruktaki eşitleme işleri kısa
     * zamanaşımıyla kalmaya devam eder.
     */
    public int $timeout;

    /**
     * İş bir şekilde kaybolursa kilit sonsuza kadar kalmasın.
     *
     * Zamanaşımından KISA olamaz: kilit iş hâlâ koşarken düşerse aynı cihaza
     * ikinci bir tur girer ve ikisi de diğerinin yazdığı parolayı doğrulamaya
     * çalışır. Değer kurucuda zamanaşımına göre kurulur.
     */
    public int $uniqueFor = 900;

    /**
     * @param  ?string  $password  Elle verilen parola. Boş bırakılırsa
     *                             rastgele üretilir.
     */
    public function __construct(
        public int $switchId,
        public ?int $userId = null,
        public ?string $password = null,
    ) {
        $this->onQueue(config('credentials.rotation.queue'));
        $this->timeout = (int) config('credentials.rotation.job_timeout');
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return (string) $this->switchId;
    }

    /**
     * "Sıraya alındı ama işçi henüz başlamadı" izi.
     *
     * Tur kaydı SwitchPasswordRotator içinde, yani İŞÇİ çalışmaya başlayınca
     * açılıyor. Düğmeye basılan an ile o an arasında veritabanında hiçbir iz
     * yok: ekran "kuyruğa alındı" diyor, gösterilecek bir tur bulunmuyor ve
     * ilerleme çubuğu hiç çizilmiyor. Kullanıcı için bu, düğmenin işlemediği
     * anlamına gelir — üstelik toplu turda onlarca cihaz sırada bekliyor.
     *
     * İz kısa ömürlüdür ve YALNIZCA ekran içindir; turun kendisi için hiçbir
     * karar buna dayanmaz.
     */
    private const BEKLEYEN = 'nsm:parola-bekleyen:';

    /**
     * İzin ömrü: kuyruk tıkalıysa çubuk sonsuza dek dönmemeli.
     *
     * Toplu turda sıranın SONUNDAKİ cihaz kendinden öncekilerin tamamı kadar
     * bekler; sabit bir ömür orada çubuğu erkenden söndürüp "hiçbir şey
     * olmuyor" izlenimi verirdi. Sıralı kuyruklamada ömür sıranın uzunluğuna
     * göre hesaplanır.
     */
    private const BEKLEME_OMRU = 900;

    /**
     * Kuyruğa alan TEK yol.
     *
     * Üç ekran turu başlatıyor (toplu, tek cihaz, elle parola). Gönderme ile
     * iz bırakmayı çağıranlara bırakmak, birini eklerken diğerini unutmaya
     * açık olurdu: o cihazda çubuk hiç görünmez, sebebi de belli olmazdı.
     */
    public static function kuyrukla(int $switchId, ?int $userId = null, ?string $password = null): void
    {
        self::dispatch($switchId, $userId, $password);

        self::izBirak($switchId);
    }

    /**
     * TOPLU tur: cihazlar SIRAYLA, biri bitmeden diğeri başlamadan.
     *
     * Her cihaz ayrı ayrı kuyruğa atılırsa birden çok işçi turları aynı anda
     * alır. Tur, cihazda altı commit atan ağır bir iştir; aynı anda koşan
     * turlar hem işçilerin tamamını dakikalarca meşgul edip eşitlemeyi
     * durdurur hem de hepsi birden zamanaşımına girer: kesilen turlarda
     * kurtarma hesapları cihazlarda açık kalır.
     *
     * Zincir, bir tur BAŞARISIZ olsa bile devam eder: SwitchPasswordRotator
     * hatayı kendi içinde kayda geçirir ve iş fırlatmadan döner. Bir cihazın
     * ulaşılamaz olması diğerlerinin sırasını düşürmemeli.
     *
     * @param  iterable<int>  $switchIds
     */
    public static function sirayaAl(iterable $switchIds, ?int $userId = null): int
    {
        $isler = [];
        $bekleyenler = [];

        foreach ($switchIds as $switchId) {
            $isler[] = new self((int) $switchId, $userId);

            /*
             * İz HEPSİ için hemen bırakılır: zincirdeki iş kuyruğa ancak
             * sırası gelince girer, oysa ekran daha ilk saniyede "sıraya
             * alındı" demek zorunda — yoksa düğme işlememiş gibi görünür.
             */
            $bekleyenler[] = (int) $switchId;
        }

        if ($isler === []) {
            return 0;
        }

        $omur = count($isler) * (int) config('credentials.rotation.job_timeout') + 300;

        foreach ($bekleyenler as $switchId) {
            self::izBirak($switchId, $omur);
        }

        Bus::chain($isler)
            ->onQueue((string) config('credentials.rotation.queue'))
            ->dispatch();

        return count($isler);
    }

    /** "Sıraya alındı" izini bırakır. */
    private static function izBirak(int $switchId, ?int $omur = null): void
    {
        Cache::put(self::BEKLEYEN.$switchId, now()->timestamp, $omur ?? self::BEKLEME_OMRU);
    }

    /** Bu cihaz için kuyrukta bekleyen bir tur var mı? Varsa ne zaman kuyruğa girdi? */
    public static function bekleyen(int $switchId): ?int
    {
        $an = Cache::get(self::BEKLEYEN.$switchId);

        return $an === null ? null : (int) $an;
    }

    /** İzi siler — tur kaydı görünür olunca işi biter. */
    public static function iziSil(int $switchId): void
    {
        Cache::forget(self::BEKLEYEN.$switchId);
    }

    public function handle(SwitchPasswordRotator $rotator): void
    {
        $switch = NetworkSwitch::query()->find($this->switchId);

        if ($switch === null) {
            return;
        }

        // Hata kayda geçer ve ekranda görünür; kuyruk işi başarısız saymaz.
        $rotator->rotate(
            $switch,
            $this->userId === null ? null : User::query()->find($this->userId),
            $this->password,
        );
    }
}
