<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SwitchStatus;
use App\Models\NetworkSwitch;
use App\Services\Measurement\MeasurementResolver;
use App\Support\Measurement\DeviceMeasurement;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Switchin anlık durumunu okur.
 *
 * Kaynağı çözümleyici seçer: ayarlarda birincil olarak ne seçildiyse o, o
 * yanıt vermezse cihazın kendisi. Bu iş hangisiyle konuşulduğunu bilmez.
 *
 * Hiçbir kaynak cevap veremezse durum "bilinmiyor" olur — ÇEVRİMDIŞI DEĞİL.
 * İkisini ayırmak zorunludur: izleme sistemi çöktüğünde bütün filoyu
 * çevrimdışı göstermek yanlış alarmdır ve o sırada gerçekten düşen bir cihazı
 * görünmez kılar.
 *
 * Model yerine kimlik taşır: kuyrukta bekleyen iş bayat veriyle çalışmaz ve
 * Redis payload'ı küçük kalır. ShouldBeUnique aynı switch için ikinci bir işin
 * kuyruğa girmesini engeller.
 */
class SyncSwitchStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public int $switchId)
    {
        $this->onQueue(config('zabbix.queue'));
    }

    public function uniqueId(): string
    {
        return 'switch-status:'.$this->switchId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(MeasurementResolver $resolver): void
    {
        $switch = NetworkSwitch::query()->find($this->switchId);

        if ($switch === null) {
            return;
        }

        $reading = $resolver->device($switch);

        /*
         * Kaynak yok ya da hiçbiri cevap veremedi. Kayıtlı ölçümlere
         * dokunulmaz — son bilinen CPU değeri, sıcaklık ve uptime yerinde
         * kalır; yalnızca "bu bilgi artık doğrulanmıyor" denir.
         */
        /*
         * GÖZLEM yazılıyor, düzenleme değil: sürüm damgasına dokunulmaz.
         * Bu tur 30 saniyede bir çalışıyor ve damgayı ilerletince cihaz
         * ayarları formu sayfa açıldıktan yarım dakika sonra çalışmaz hâle
         * geliyordu (bkz. RecordsObservations).
         */
        if ($reading === null) {
            $switch->saveObservation(['status' => SwitchStatus::Unknown]);

            return;
        }

        $switch->saveObservation([
            'status' => $this->statusFor($reading->measurement),
            'measurement_source' => $reading->source,
            'last_sync_at' => now(),
            ...$reading->measurement->attributes(),
        ]);
    }

    /**
     * Eşiği aşan CPU ya da bellek uyarı üretir; erişilemeyen cihaz doğrudan
     * çevrimdışıdır.
     */
    private function statusFor(DeviceMeasurement $measurement): SwitchStatus
    {
        if (! $measurement->reachable) {
            return SwitchStatus::Offline;
        }

        $thresholds = config('ui.thresholds');

        $breached = ($measurement->cpuUsage !== null && $measurement->cpuUsage >= $thresholds['cpu']['danger'])
            || ($measurement->memoryUsage !== null && $measurement->memoryUsage >= $thresholds['memory']['danger']);

        return $breached ? SwitchStatus::Warning : SwitchStatus::Online;
    }

    /**
     * İş tümden başarısız olduğunda da "çevrimdışı" YAZILMAZ: okuyamamak,
     * cihazın kapalı olduğu bilgisi değildir.
     */
    public function failed(): void
    {
        NetworkSwitch::query()->where('id', $this->switchId)->update(['status' => SwitchStatus::Unknown]);
    }
}
