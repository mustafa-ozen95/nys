<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActivityChannel;
use App\Enums\SwitchWriteStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use App\Services\ActivityRecorder;
use App\Services\Netconf\ConfigurationWriter;
use App\Services\Netconf\NetconfException;
use App\Support\Netconf\ConfigurationRedactor;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Cihaza yazmayı KUYRUKTA yapar.
 *
 * Bir yazma ELS switchte bir dakikaya yaklaşabilir (iki gerçek commit, iki
 * oturum); senkron yapılsaydı HTTP isteği o kadar tutulur, sayfa kilitlenirdi.
 * İstek işi kuyruğa atıp hemen döner; kullanıcı sonucu SwitchWriteJob
 * kaydından öğrenir.
 *
 * TEKRAR DENENMEZ ($tries = 1). Yarım uygulanmış bir cihaz yazmasını kör
 * tekrar etmek, panelin bilmediği bir duruma ikinci kez yazmaktır. Yazma
 * yolunun kendi geri alma güvencesi var (commit confirmed); onun üstüne
 * otomatik tekrar koymak güvenliği azaltır.
 *
 * ARGÜMANLAR YALNIZCA BURADA (kuyrukta) durur; parola ve community
 * taşıyabilirler ve durum kaydına yazılmazlar.
 *
 * KUYRUK YÜKÜ ŞİFRELİDİR (ShouldBeEncrypted). Argümanlar cihaz hesabı
 * parolası, SNMP community ve BGP anahtarı taşıyabiliyor; düz yazılsalardı
 * jobs/failed_jobs tablosunu ya da yedeğini okuyan, APP_KEY'i olmayan biri
 * bunları okurdu — oysa aynı sırlar veritabanında şifreli duruyor. Laravel
 * çalıştırırken "O:" ile başlayan DÜZ yükü de kabul eder; şifrelemeden önce
 * kuyruğa girmiş işler olduğu gibi çalışır.
 */
class ApplySwitchWriteJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Cihaz yazması kör tekrar EDİLMEZ.
     */
    public int $tries = 1;

    /**
     * Bir yazma iki gerçek commit içeriyor; ELS'te tek commit on saniyeyi
     * aşabiliyor. Sınır cömert tutulur, yoksa iş ortasında kesilir.
     */
    public int $timeout = 300;

    /**
     * @param  class-string  $configurator  cihaza yazan servis
     * @param  string  $method  o servisin metodu; ilk argümanı switch'tir
     * @param  array<int, mixed>  $arguments  switch'ten SONRAKİ argümanlar
     */
    /**
     * $record ADI BİLEREK böyle: InteractsWithQueue'nun kendi $job özelliği
     * var ve aynı adı kullanmak sınıfı derlenemez hâle getiriyor
     * ("define the same property ($job) ... considered incompatible").
     */
    public function __construct(
        public SwitchWriteJob $record,
        public NetworkSwitch $switch,
        public string $configurator,
        public string $method,
        public array $arguments = [],
    ) {
        $this->onQueue(config('netconf.write_queue'));
    }

    /**
     * Aynı switche iki yazma AYNI ANDA çalışmaz.
     *
     * SwitchWriteLock cihaz tarafını zaten koruyor ama kuyruk seviyesinde de
     * ayrılmaları gerekiyor: ikisi paralel başlarsa biri diğerinin kilidine
     * çarpıp gereksiz yere düşerdi.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->switch->getKey()))
                ->releaseAfter(5)
                ->expireAfter(600),
        ];
    }

    public function handle(): void
    {
        $this->record->forceFill([
            'status' => SwitchWriteStatus::Running,
            'started_at' => now(),
        ])->save();

        $result = app($this->configurator)->{$this->method}($this->switch, ...$this->arguments);

        $diff = ConfigurationRedactor::apply($result['diff']);

        // Commit kalıcı oldu ama geri okuma düştüyse iş yine "tamamlandı"dır;
        // kullanıcıya ekranın bayat olabileceği söylenir.
        $readWarning = collect($result['warnings'] ?? [])
            ->first(fn (string $warning): bool => str_starts_with($warning, ConfigurationWriter::READBACK_WARNING));

        $this->record->forceFill([
            'status' => $result['committed'] ? SwitchWriteStatus::Completed : SwitchWriteStatus::Unchanged,
            'diff' => $result['committed'] ? $diff : null,
            'message' => $result['committed'] ? $readWarning : 'Cihaz zaten bu durumda, değişiklik yapılmadı.',
            'finished_at' => now(),
        ])->save();

        if (! $result['committed']) {
            return;
        }

        /*
         * Etkinlik kaydına PANELİN NİYETİ değil, CİHAZIN KABUL ETTİĞİ
         * değişiklik geçer. Fark ancak burada biliniyor; isteğin kimliği ise
         * iş kaydında bekliyordu.
         */
        app(ActivityRecorder::class)->recordAs(
            $this->record->user_id,
            $this->record->reason,
            $this->record->ip_address,
            $this->switch,
            'configured',
            "{$this->switch->hostname} · {$this->record->summary}: ".str_replace("\n", ' ', $diff),
            ActivityChannel::Config,
        );
    }

    /**
     * İş düşerse kullanıcı SEBEBİNİ görmeli.
     *
     * Yazma kuyrukta koştuğu için kullanıcı o sırada sayfadan ayrılmış
     * olabilir; hata bir toast'a güvenilemez, iş kaydına yazılır. Sessiz bir başarısızlık,
     * cihazda yapıldığı sanılan bir değişiklik demektir.
     */
    public function failed(?Throwable $exception): void
    {
        $this->record->forceFill([
            'status' => SwitchWriteStatus::Failed,
            'message' => $exception instanceof NetconfException
                ? $exception->getMessage()
                : ($exception?->getMessage() ?? 'İşlem beklenmedik bir sebeple düştü.'),
            'finished_at' => now(),
        ])->save();
    }
}
