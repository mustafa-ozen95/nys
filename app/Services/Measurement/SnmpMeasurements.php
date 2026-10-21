<?php

declare(strict_types=1);

namespace App\Services\Measurement;

use App\Enums\Duplex;
use App\Enums\MeasurementSource;
use App\Enums\PortStatus;
use App\Models\NetworkSwitch;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpException;
use App\Support\Measurement\DeviceMeasurement;
use App\Support\Measurement\PortMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cihazın kendisinden ölçüm (SNMP).
 *
 * NETCONF kimliği olmayan cihazlarda da çalışır ve ölçüldüğü kadarıyla
 * NETCONF'tan hızlıdır (oturum kurulmaz). Yalnızca okuma yapılır; SET
 * hiçbir yerde yoktur.
 *
 * Trafik hızı cihazdan HAZIR GELMEZ: SNMP sayaç verir, hız iki ölçüm
 * arasındaki farktır. Önceki sayaç Redis'te tutulur; ilk okumada hız boş
 * kalır, ikinciden itibaren dolar. Sayaç taşması ya da cihazın yeniden
 * başlaması negatif fark üretir, o örnek atılır — uydurma bir değer
 * göstermektense boş bırakmak doğrudur.
 */
class SnmpMeasurements implements ReadsMeasurements
{
    /** Önceki sayaç örneğinin önbellekte kalma süresi (saniye). */
    private const COUNTER_TTL = 900;

    public function __construct(private SnmpClient $snmp) {}

    public function source(): MeasurementSource
    {
        return MeasurementSource::Snmp;
    }

    public function supports(NetworkSwitch $switch): bool
    {
        return (bool) config('snmp.enabled') && $switch->snmpCredential?->community !== null;
    }

    /**
     * Her cihaza ayrı UDP sorgusu gider; toplu okuma diye bir şey yoktur.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void {}

    public function device(NetworkSwitch $switch): DeviceMeasurement
    {
        $oids = config('snmp.oids');

        try {
            // sysUpTime timetick'tir: yüzde bir saniye.
            $uptime = $this->max($switch, $oids['sys_uptime']);

            return new DeviceMeasurement(
                reachable: true,
                uptimeSeconds: $uptime === null ? null : (int) round($uptime / 100),
                cpuUsage: $this->percent($this->max($switch, $oids['cpu'])),
                memoryUsage: $this->percent($this->max($switch, $oids['memory'])),
                temperature: $this->rounded($this->max($switch, $oids['temperature'])),
            );
        } catch (SnmpException $exception) {
            /*
             * SNMP zaman aşımı cihazın kapalı olduğunu düşündürür ama
             * kesin bilgi değildir: community yanlış olabilir, UDP paketi
             * yolda düşmüş olabilir. Kaynak arızası olarak bildirilir;
             * "çevrimdışı" kararını çözümleyici bütün kaynakları deneyip
             * verir.
             */
            throw MeasurementUnavailable::from($exception);
        }
    }

    public function ports(NetworkSwitch $switch): array
    {
        $oids = config('snmp.oids');

        try {
            $status = $this->snmp->walk($switch, $oids['if_oper_status']);
            $speed = $this->snmp->walk($switch, $oids['if_high_speed']);
            $duplex = $this->snmp->walk($switch, $oids['if_duplex']);
            $in = $this->rates($switch, $oids['if_in_octets'], 'in');
            $out = $this->rates($switch, $oids['if_out_octets'], 'out');
        } catch (SnmpException $exception) {
            throw MeasurementUnavailable::from($exception);
        }

        $measurements = [];

        foreach ($status as $index => $value) {
            $ifIndex = (int) $index;

            $measurements[$ifIndex] = new PortMeasurement(
                ifIndex: $ifIndex,
                operStatus: $this->operStatus($value),
                speedMbps: isset($speed[$index]) && (int) $speed[$index] > 0 ? (int) $speed[$index] : null,
                duplex: $this->duplex($duplex[$index] ?? null),
                inBps: $in[$ifIndex] ?? null,
                outBps: $out[$ifIndex] ?? null,
            );
        }

        return $measurements;
    }

    /**
     * Sayaç farkından bit/sn. Önceki örnek yoksa ya da fark negatifse boş
     * döner.
     *
     * @return array<int, float>
     *
     * @throws SnmpException
     */
    private function rates(NetworkSwitch $switch, string $oid, string $direction): array
    {
        $counters = $this->snmp->walk($switch, $oid);
        $now = microtime(true);
        $key = "snmp:counters:{$switch->id}:{$direction}";
        $previous = Cache::get($key);

        Cache::put($key, ['at' => $now, 'counters' => $counters], self::COUNTER_TTL);

        if (! is_array($previous) || ($elapsed = $now - $previous['at']) <= 0) {
            return [];
        }

        $rates = [];

        foreach ($counters as $index => $value) {
            $before = $previous['counters'][$index] ?? null;

            if ($before === null || ! is_numeric($value) || ! is_numeric($before)) {
                continue;
            }

            $delta = (float) $value - (float) $before;

            // Negatif fark: sayaç taştı ya da cihaz yeniden başladı.
            if ($delta < 0) {
                continue;
            }

            $rates[(int) $index] = $delta * 8 / $elapsed;
        }

        return $rates;
    }

    /**
     * @throws SnmpException
     */
    private function max(NetworkSwitch $switch, string $oid): ?float
    {
        $values = array_filter($this->snmp->walk($switch, $oid), 'is_numeric');

        return $values === [] ? null : max(array_map('floatval', $values));
    }

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
