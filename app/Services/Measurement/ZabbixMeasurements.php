<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\Duplex;
use App\Enums\MeasurementSource;
use App\Enums\PortStatus;
use App\Exceptions\ZabbixApiException;
use App\Models\NetworkSwitch;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixHostMap;
use App\Services\Zabbix\ZabbixItemReader;
use App\Support\Measurement\DeviceMeasurement;
use App\Support\Measurement\PortMeasurement;
use App\Support\Zabbix\ZabbixItemBag;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Collection;

/**
 * İzleme sisteminden ölçüm.
 *
 * En zengin kaynaktır: geçmiş grafikleri yalnızca burada vardır. Buna karşılık
 * araya bir sistem daha girer; o sistem çöktüğünde panel kör kalmasın diye
 * cihaz kaynakları da vardır.
 *
 * Ağ ve API hataları MeasurementUnavailable'a çevrilir: çözümleyici bunu
 * görünce sıradaki kaynağa geçer. "Cihaz çevrimdışı" ile karıştırılmaz.
 */
class ZabbixMeasurements implements ReadsMeasurements
{
    public function __construct(
        private ZabbixClient $client,
        private ZabbixHostMap $hosts,
        private ZabbixItemReader $items,
    ) {}

    public function source(): MeasurementSource
    {
        return MeasurementSource::Zabbix;
    }

    public function supports(NetworkSwitch $switch): bool
    {
        return (bool) config('zabbix.enabled') && $this->client->isConfigured();
    }

    /**
     * Bütün hostlar tek item.get ile okunur ve okuyucuda hatırlanır; genel
     * bakış ekranı switch başına ayrı çağrı atmaz.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void
    {
        /*
         * Host eşlemesi de bir API çağrısıdır ve o da düşebilir. Hazırlık
         * yalnızca bir hızlandırmadır: başarısız olursa okumalar tek tek
         * denenir ve hata orada, kaynağa özgü biçimde ele alınır.
         */
        try {
            $hostIds = $switches
                ->map(fn (NetworkSwitch $switch): ?string => $this->hosts->hostIdFor($switch))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($hostIds !== []) {
                $this->items->forHosts($hostIds);
            }
        } catch (ZabbixApiException|HttpClientException) {
            // Sessizce geçilir; okuma yolunda yeniden denenecek.
        }
    }

    public function device(NetworkSwitch $switch): DeviceMeasurement
    {
        $items = $this->items($switch);
        $keys = config('zabbix.items');

        if ($items->isEmpty()) {
            /*
             * Hiç item yok: cihaz izleme sisteminde tanımsız ya da keşif
             * kuralı henüz çalışmamış. Bu "cihaz kapalı" DEĞİLDİR — izleme
             * sistemi bu cihaz hakkında bir şey söyleyemiyor demektir.
             * Kaynak arızası olarak bildirilir ki çözümleyici cihazın
             * kendisine sorabilsin; kimse cevap veremezse durum "bilinmiyor"
             * olur, uydurma bir "çevrimdışı" değil.
             */
            throw new MeasurementUnavailable("{$switch->hostname} için izleme sisteminde ölçüm yok.");
        }

        $reachable = $items->number($keys['ping']);

        return new DeviceMeasurement(
            reachable: $reachable === null || $reachable >= 1,
            uptimeSeconds: $this->rounded($items->number($keys['uptime'])),
            cpuUsage: $this->percent($items->max($keys['cpu'][0])),
            memoryUsage: $this->percent($items->max($keys['memory'][0])),
            temperature: $this->rounded($items->max($keys['temperature'][0])),
            model: $items->value($keys['model']),
            serialNumber: $items->value($keys['serial_number']),
            junosVersion: $items->value($keys['os_version']),
        );
    }

    public function ports(NetworkSwitch $switch): array
    {
        $items = $this->items($switch);
        $keys = config('zabbix.items');

        $status = $items->byIfIndex($keys['interface_status']);
        $speed = $items->byIfIndex($keys['interface_speed']);
        $duplex = $items->byIfIndex($keys['interface_duplex']);
        $in = $items->byIfIndex($keys['interface_in']);
        $out = $items->byIfIndex($keys['interface_out']);

        $measurements = [];

        foreach (array_unique([...array_keys($status), ...array_keys($in), ...array_keys($out)]) as $ifIndex) {
            $measurements[$ifIndex] = new PortMeasurement(
                ifIndex: $ifIndex,
                operStatus: $this->operStatus($status[$ifIndex] ?? null),
                speedMbps: $this->speedInMbps($speed[$ifIndex] ?? null),
                duplex: $this->duplex($duplex[$ifIndex] ?? null),
                inBps: isset($in[$ifIndex]) && is_numeric($in[$ifIndex]) ? (float) $in[$ifIndex] : null,
                outBps: isset($out[$ifIndex]) && is_numeric($out[$ifIndex]) ? (float) $out[$ifIndex] : null,
            );
        }

        return $measurements;
    }

    /**
     * Kayıtlı host kimliği boş sonuç veriyorsa bayatlamış demektir: eşleme bir
     * kez daha çözülür. Aksi hâlde switch "metriksiz ama çevrimiçi" görünürdü.
     *
     * Yakalama HttpClientException seviyesindedir: bağlantı hatası da (ağ
     * kopuk) HTTP hata kodu da (500, 401) aynı şeyi söyler — bu kaynak şu an
     * konuşamıyor.
     */
    private function items(NetworkSwitch $switch): ZabbixItemBag
    {
        try {
            $hostId = $this->hosts->hostIdFor($switch);
            $items = $hostId === null ? null : $this->items->forHost($hostId);

            if ($items !== null && ! $items->isEmpty()) {
                return $items;
            }

            $refreshed = $this->hosts->resolve($switch);

            if ($refreshed === null || $refreshed === $hostId) {
                return $items ?? ZabbixItemBag::fromApi([]);
            }

            return $this->items->forHost($refreshed);
        } catch (ZabbixApiException|HttpClientException $exception) {
            throw MeasurementUnavailable::from($exception);
        }
    }

    /**
     * Zabbix'in net.if.speed item'ı bit/sn birimindedir (units=bps);
     * veritabanında megabit tutulur. Doğrudan yazılırsa hız bir milyon kat
     * büyük görünür.
     */
    private function speedInMbps(?string $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $bitsPerSecond = (float) $value;

        return $bitsPerSecond <= 0 ? null : (int) round($bitsPerSecond / 1_000_000);
    }

    /**
     * ifOperStatus MIB kodları. Zabbix sayısal döndürür.
     */
    private function operStatus(?string $value): ?PortStatus
    {
        return match ($value) {
            '1' => PortStatus::Up,
            '2' => PortStatus::Down,
            '3' => PortStatus::Testing,
            '5' => PortStatus::Dormant,
            null, '' => null,
            default => PortStatus::Unknown,
        };
    }

    /**
     * dot3StatsDuplexStatus: 1 bilinmiyor, 2 yarım, 3 tam.
     */
    private function duplex(?string $value): ?Duplex
    {
        return match ($value) {
            '2' => Duplex::Half,
            '3' => Duplex::Full,
            default => null,
        };
    }

    private function percent(?float $value): ?int
    {
        return $value === null ? null : (int) round(max(0, min(100, $value)));
    }

    private function rounded(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
