<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\MeasurementSource;
use App\Models\NetworkSwitch;
use App\Support\Measurement\DeviceReading;
use App\Support\Measurement\PortReading;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Ölçümü hangi kaynağın vereceğine karar verir ve gerekirse yedeğe düşer.
 *
 * Sıra: ayarlarda seçilen birincil kaynak, sonra — yedek açıksa — kalanlar.
 * Bir kaynak bu switch için kullanılamıyorsa (kimlik yok, ayarda kapalı) ya
 * da devre kesici yüzünden kapalıysa atlanır; hata verirse kesici açılır ve
 * sıradakine geçilir.
 *
 * Hiçbir kaynak cevap veremezse null döner. Bu "cihaz çevrimdışı" DEĞİLDİR;
 * "bilmiyoruz" demektir ve çağıran taraf durumu Unknown olarak yazar. İkisini
 * karıştırmak, izleme sistemi çöktüğünde bütün filoyu çevrimdışı göstermek
 * demek olurdu — o da gerçekten düşen bir cihazı görünmez kılardı.
 */
class MeasurementResolver
{
    public function __construct(
        private ZabbixMeasurements $zabbix,
        private NetconfMeasurements $netconf,
        private SnmpMeasurements $snmp,
        private MeasurementCircuit $circuit,
    ) {}

    /**
     * Toplu okuma yapabilen kaynağa hazırlan demesi.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void
    {
        if ($switches->isEmpty()) {
            return;
        }

        foreach ($this->candidates() as $source) {
            $hazir = $switches->filter(fn (NetworkSwitch $switch): bool => $source->supports($switch)
                && ! $this->circuit->isOpen($source->source(), $switch));

            if ($hazir->isNotEmpty()) {
                $source->prefetch($hazir);

                return;
            }
        }
    }

    public function device(NetworkSwitch $switch): ?DeviceReading
    {
        return $this->read(
            $switch,
            fn (ReadsMeasurements $source): DeviceReading => new DeviceReading(
                $source->source(),
                $source->device($switch),
            ),
        );
    }

    public function ports(NetworkSwitch $switch): ?PortReading
    {
        return $this->read(
            $switch,
            fn (ReadsMeasurements $source): PortReading => new PortReading(
                $source->source(),
                $source->ports($switch),
            ),
        );
    }

    /**
     * Bu switch için şu an kullanılabilecek kaynak. Ekranda "veri nereden
     * geliyor" bilgisini basmak için; okuma yapmaz.
     */
    public function activeSource(NetworkSwitch $switch): ?MeasurementSource
    {
        foreach ($this->candidates() as $source) {
            if ($source->supports($switch) && ! $this->circuit->isOpen($source->source(), $switch)) {
                return $source->source();
            }
        }

        return null;
    }

    /**
     * @template TReading
     *
     * @param  Closure(ReadsMeasurements): TReading  $callback
     * @return TReading|null
     */
    private function read(NetworkSwitch $switch, Closure $callback): mixed
    {
        foreach ($this->candidates() as $source) {
            if (! $source->supports($switch) || $this->circuit->isOpen($source->source(), $switch)) {
                continue;
            }

            try {
                $reading = $callback($source);
            } catch (MeasurementUnavailable $exception) {
                $this->circuit->recordFailure($source->source(), $switch, $exception->getMessage());

                /*
                 * Süren arıza saatte bir kez yazılır (bkz. shouldReport).
                 * Her turda yazılsaydı ulaşılamayan tek bir cihaz günlüğü
                 * doldururdu; ölçüldü.
                 */
                if ($this->circuit->shouldReport($source->source(), $switch)) {
                    Log::warning('Ölçüm kaynağı yanıt vermedi', [
                        'kaynak' => $source->source()->value,
                        'switch' => $switch->hostname,
                        'hata' => $exception->getMessage(),
                    ]);
                }

                continue;
            }

            $this->circuit->recordSuccess($source->source(), $switch);

            return $reading;
        }

        return null;
    }

    /**
     * Denenecek kaynaklar, sırayla.
     *
     * @return list<ReadsMeasurements>
     */
    private function candidates(): array
    {
        $primary = MeasurementSource::tryFrom((string) config('measurement.source')) ?? MeasurementSource::Zabbix;
        $sources = [$this->of($primary)];

        if (! config('measurement.fallback')) {
            return $sources;
        }

        foreach ([MeasurementSource::Netconf, MeasurementSource::Snmp, MeasurementSource::Zabbix] as $source) {
            if ($source !== $primary) {
                $sources[] = $this->of($source);
            }
        }

        return $sources;
    }

    private function of(MeasurementSource $source): ReadsMeasurements
    {
        return match ($source) {
            MeasurementSource::Zabbix => $this->zabbix,
            MeasurementSource::Netconf => $this->netconf,
            MeasurementSource::Snmp => $this->snmp,
        };
    }
}
