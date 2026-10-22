<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Services\Server\ServerPasswordRotator;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Bir sunucunun parolasını değiştirir.
 *
 * Switch turuyla AYNI kurallar geçerli (bkz. RotateSwitchPasswordJob):
 *
 * TEKRAR DENENMEZ (tries = 1). Yarıda kalmış bir tur körlemesine yeniden
 * başlatılırsa makinede duran kurtarma hesabının parolası ezilir ve makineye
 * dönüş kapısı kaybolur. Takılan tur ekranda durur; kapatmak onarım işidir ve
 * kullanıcı başlatır.
 *
 * Sunucu başına tektir: aynı makineye iki tur aynı anda girerse ikisi de
 * diğerinin yazdığı parolayı doğrulamaya çalışır.
 *
 * YÜKÜ ŞİFRELİDİR (ShouldBeEncrypted): kuyruk sürücüsü "database" ve iş
 * çerçeve seviyesinde düşerse yükü `failed_jobs` satırında KALICI olur.
 */
class ServerPasswordRotateJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * İşçinin varsayılan zaman aşımı bu tur için kısadır: makineye birkaç kez
     * bağlanılıyor ve Windows tarafında her çağrı kendi kabuğunu açıyor.
     * Değer işin KENDİ özelliğidir; aynı kuyruktaki eşitleme işleri kısa
     * zaman aşımıyla kalmaya devam eder.
     */
    public int $timeout;

    /**
     * İş bir şekilde kaybolursa kilit sonsuza kadar kalmasın.
     *
     * Zaman aşımından KISA olamaz: kilit iş hâlâ koşarken düşerse aynı
     * makineye ikinci bir tur girer.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public int $serverId,
        public ?int $userId = null,
        public ?string $reason = null,
    ) {
        $this->onQueue(config('credentials.rotation.queue'));
        $this->timeout = (int) config('credentials.rotation.job_timeout');
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return 'sunucu:'.$this->serverId;
    }

    /**
     * "Sıraya alındı ama işçi henüz başlamadı" izi.
     *
     * Tur kaydı döndürücünün içinde, yani İŞÇİ çalışmaya başlayınca açılıyor.
     * Düğmeye basılan an ile o an arasında veritabanında hiçbir iz yok ve
     * kullanıcı için bu, düğmenin işlemediği anlamına gelir.
     */
    private const PENDING = 'nsm:sunucu-parola-bekleyen:';

    private const WAIT_LIFETIME = 900;

    /** Kuyruğa alan TEK yol: gönderme ve iz bırakma birlikte yapılır. */
    public static function enqueue(int $serverId, ?int $userId = null, ?string $reason = null): void
    {
        self::dispatch($serverId, $userId, $reason);

        self::traceLeave($serverId);
    }

    /**
     * TOPLU tur: sunucular SIRAYLA, biri bitmeden diğeri başlamadan.
     *
     * Switch turlarındaki tuzağın aynısı: aynı anda koşan turlar hem
     * işçilerin tamamını meşgul eder hem de hepsi birden zaman aşımına girer
     * ve kurtarma hesapları makinelerde açık kalır.
     *
     * Zincir, bir tur BAŞARISIZ olsa bile devam eder: döndürücü hatayı kendi
     * içinde kayda geçirir ve iş fırlatmadan döner.
     *
     * @param  iterable<int>  $serverIds
     */
    public static function enqueueChain(iterable $serverIds, ?int $userId = null, ?string $reason = null): int
    {
        $jobs = [];
        $pending = [];

        foreach ($serverIds as $serverId) {
            $jobs[] = new self((int) $serverId, $userId, $reason);
            $pending[] = (int) $serverId;
        }

        if ($jobs === []) {
            return 0;
        }

        $lifetime = count($jobs) * (int) config('credentials.rotation.job_timeout') + 300;

        foreach ($pending as $serverId) {
            self::traceLeave($serverId, $lifetime);
        }

        Bus::chain($jobs)
            ->onQueue((string) config('credentials.rotation.queue'))
            ->dispatch();

        return count($jobs);
    }

    private static function traceLeave(int $serverId, ?int $lifetime = null): void
    {
        Cache::put(self::PENDING.$serverId, now()->timestamp, $lifetime ?? self::WAIT_LIFETIME);
    }

    /** Bu sunucu için kuyrukta bekleyen bir tur var mı? */
    public static function pending(int $serverId): ?int
    {
        $moment = Cache::get(self::PENDING.$serverId);

        return $moment === null ? null : (int) $moment;
    }

    /** İzi siler — tur kaydı görünür olunca işi biter. */
    public static function traceDelete(int $serverId): void
    {
        Cache::forget(self::PENDING.$serverId);
    }

    public function handle(ServerPasswordRotator $rotator): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null) {
            return;
        }

        // Hata kayda geçer ve ekranda görünür; kuyruk işi başarısız saymaz.
        $rotator->rotate(
            $server,
            $this->userId === null ? null : User::query()->find($this->userId),
            $this->reason,
        );
    }
}
