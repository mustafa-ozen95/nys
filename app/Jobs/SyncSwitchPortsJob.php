<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Duplex;
use App\Enums\PortStatus;
use App\Enums\PortType;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchPortSample;
use App\Services\Measurement\MeasurementResolver;
use App\Services\Zabbix\ZabbixHostMap;
use App\Services\Zabbix\ZabbixItemReader;
use App\Support\Measurement\PortReading;
use App\Support\Zabbix\ItemKey;
use App\Support\Zabbix\ZabbixItemBag;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arayüz ölçümlerini okur: oper durumu, hız, dupleks, anlık trafik.
 *
 * İki ayrı yol vardır ve ayrımın sebebi arayüz listesinin SAHİBİDİR:
 *
 *   NETCONF kimliği VARSA — liste cihazdan gelir (SyncSwitchNetconfJob). Bu
 *     iş yalnızca ölçüm yazar, satır yaratmaz ve silmez. Ölçümün kaynağını
 *     çözümleyici seçer; izleme sistemi erişilemezse cihazın kendisine
 *     sorulur.
 *   NETCONF kimliği YOKSA — tek kaynak izleme sistemidir; liste de ondan
 *     kurulur, orada görünmeyen arayüz envanterden düşer.
 *
 * Trafik sayaçları normalde veritabanında tutulmaz; grafik izleme sisteminden
 * okunur. Ölçüm cihazdan geliyorsa geçmiş yoktur, o yüzden anlık değer
 * örnek olarak saklanır ve grafik ondan çizilir.
 */
class SyncSwitchPortsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $switchId)
    {
        $this->onQueue(config('zabbix.queue'));
    }

    public function uniqueId(): string
    {
        return 'switch-interfaces:'.$this->switchId;
    }

    public function handle(
        MeasurementResolver $resolver,
        ZabbixHostMap $hosts,
        ZabbixItemReader $reader,
    ): void {
        $switch = NetworkSwitch::query()->find($this->switchId);

        if ($switch === null) {
            return;
        }

        // Listenin sahibi cihaz: yalnızca ölçüm yazılır.
        if ($switch->netconfCredential !== null) {
            $this->storeMeasurements($switch, $resolver->ports($switch));

            return;
        }

        $hostId = $hosts->hostIdFor($switch);

        if ($hostId === null) {
            return;
        }

        $this->store($switch, $this->collect($reader->forHost($hostId)));
    }

    /**
     * Item listesini ifIndex başına tek arayüz kaydına dönüştürür.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(ZabbixItemBag $items): array
    {
        $keys = config('zabbix.items');
        $ignored = config('zabbix.ignored_interface_types');
        $patterns = [
            config('zabbix.physical_interface_pattern'),
            // Uplink genellikle bir LAG'dır (ae0); erişim switch'inin en önemli portu.
            config('zabbix.aggregate_interface_pattern'),
        ];

        $names = $this->namesByIfIndex($items, $keys['interface_status']);
        $aliases = $this->aliasesByIfIndex($items, $keys['interface_status']);
        $statuses = $items->byIfIndex($keys['interface_status']);
        $speeds = $items->byIfIndex($keys['interface_speed']);
        $types = $items->byIfIndex($keys['interface_type']);
        $duplexes = $items->byIfIndex($keys['interface_duplex']);

        $ports = [];

        foreach ($names as $ifIndex => $name) {
            /*
             * ifType yalnızca ELEMEK için okunur, saklanmaz: panelde hiçbir
             * yerde gösterilmez ve arayüz türü ayrımı interface_kind'da
             * durur.
             */
            $type = isset($types[$ifIndex]) ? (int) $types[$ifIndex] : null;

            if ($type !== null && in_array($type, $ignored, true)) {
                continue;
            }

            if (! $this->isTracked($name, $patterns)) {
                continue;
            }

            $ports[] = [
                'name' => $name,
                'if_index' => $ifIndex,
                'description' => $aliases[$ifIndex] ?? null,
                'oper_status' => $this->operStatus($statuses[$ifIndex] ?? null),
                // admin_status BURADA YAZILMAZ: Zabbix şablonu ifAdminStatus
                // toplamıyor ve sabit "up" yazmak, panelden kapatılmış portu
                // açık gösterirdi. Bu alanın sahibi NETCONF'tur.
                'speed_mbps' => $this->speedInMbps($speeds[$ifIndex] ?? null),
                'duplex' => $this->duplex($duplexes[$ifIndex] ?? null),
                'last_polled_at' => now(),

                /*
                 * Arayüz türü ADDAN çıkarılır. Zabbix bir ae'yi bizden önce
                 * keşfederse varsayılan "physical" ile yazılır ve toplama
                 * ekranında hiç görünmezdi.
                 */
                'interface_kind' => $this->interfaceKind($names[$ifIndex]),
            ];
        }

        return $ports;
    }

    /**
     * Ölçümleri mevcut port kayıtlarına yazar. Satır YARATILMAZ: cihazda
     * olmayan bir arayüz envantere ölçüm yoluyla girmemeli.
     *
     * Eşleşme ifIndex üzerindendir — ad değişebilir, ifIndex değişmez — ve
     * değişmeyen alan yazılmaz: bütün portların her turda güncellenmesi,
     * çoğunda hiçbir şey değişmemişken boşuna yazma demektir.
     */
    private function storeMeasurements(NetworkSwitch $switch, ?PortReading $reading): void
    {
        if ($reading === null) {
            return;
        }

        $ports = $switch->ports()->whereNotNull('if_index')->get();
        $samples = [];
        $now = now();

        foreach ($ports as $port) {
            $measurement = $reading->measurements[$port->if_index] ?? null;

            if ($measurement === null) {
                continue;
            }

            $changed = array_filter(
                $measurement->attributes(),
                fn (mixed $value, string $field): bool => $port->getAttribute($field) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            // Gözlem: sürüm damgasına dokunmaz (bkz. RecordsObservations).
            $port->saveObservation([...$changed, 'last_polled_at' => $now]);

            if ($reading->needsSampling()) {
                $samples[] = $this->sample($port, $measurement->inBps, $measurement->outBps, $now);
            }
        }

        $this->storeSamples(array_filter($samples));
    }

    /**
     * Geçmiş grafiği izleme sisteminde vardır; cihaz kaynaklarında yoktur.
     * Anlık değer saklanmazsa grafik özelliği izleme sistemi kapalıyken
     * tamamen kaybolurdu.
     *
     * @return array<string, mixed>|null
     */
    private function sample(SwitchPort $port, ?float $in, ?float $out, Carbon $at): ?array
    {
        if ($in === null && $out === null) {
            return null;
        }

        return [
            'switch_port_id' => $port->id,
            'sampled_at' => $at,
            'in_bps' => $in === null ? null : (int) round($in),
            'out_bps' => $out === null ? null : (int) round($out),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     */
    private function storeSamples(array $samples): void
    {
        if ($samples === [] || (int) config('measurement.sample_retention_days') <= 0) {
            return;
        }

        // Port başına ayrı INSERT yerine tek sorgu: port sayısı kadar gidiş
        // dönüş olmasın.
        SwitchPortSample::query()->insert(array_values($samples));
    }

    /**
     * Arayüzün türü adından anlaşılır: ae0, ae1... toplama arayüzüdür.
     */
    private function interfaceKind(string $name): PortType
    {
        return preg_match('/^ae\d+$/', $name) === 1
            ? PortType::Aggregated
            : PortType::Physical;
    }

    /**
     * @return array<int, string>
     */
    private function namesByIfIndex(ZabbixItemBag $items, string $prefix): array
    {
        $names = [];

        foreach ($items->all() as $item) {
            $key = (string) ($item['key_'] ?? '');

            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $ifIndex = ItemKey::ifIndex($key);
            $name = ItemKey::portName((string) ($item['name'] ?? ''));

            if ($ifIndex !== null && $name !== null) {
                $names[$ifIndex] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function isTracked(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Switchte tanımlı port açıklamaları (ifAlias).
     *
     * @return array<int, string>
     */
    private function aliasesByIfIndex(ZabbixItemBag $items, string $prefix): array
    {
        $aliases = [];

        foreach ($items->all() as $item) {
            $key = (string) ($item['key_'] ?? '');

            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $ifIndex = ItemKey::ifIndex($key);
            $alias = ItemKey::portAlias((string) ($item['name'] ?? ''));

            if ($ifIndex !== null && $alias !== null) {
                $aliases[$ifIndex] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ports
     */
    private function store(NetworkSwitch $switch, array $ports): void
    {
        /*
         * Arayüz listesinin sahibi kim?
         *
         * NETCONF kimliği varsa CİHAZDIR: port listesi donanımdan okunur
         * (get-interface-information), yapılandırma da oradan gelir. Zabbix o
         * switchte yalnızca ÖLÇÜM taşır — keşif kuralı gecikince liste
         * bozulmasın, iki iş birbirinin kaydını silip yazmasın diye satır
         * yaratmaz ve silmez.
         *
         * NETCONF yoksa (eski cihaz, kimlik tanımsız) tek kaynak Zabbix'tir
         * ve liste ondan kurulur.
         */
        if ($switch->netconfCredential !== null) {
            $this->storeMeasurements($switch, $ports);

            return;
        }

        DB::transaction(function () use ($switch, $ports): void {
            foreach ($ports as $attributes) {
                $switch->ports()->updateOrCreate(
                    ['name' => $attributes['name']],
                    $attributes,
                );
            }

            /*
             * Silme YALNIZCA Zabbix'in sahibi olduğu switchlerde yapılır.
             *
             * NETCONF kimliği olan cihazda arayüz listesinin sahibi NETCONF'tur:
             * Zabbix'in keşif kuralı yeni bir arayüzü (özellikle yeni kurulan
             * bir ae'yi) görene kadar bir saat geçebilir ve o arada burada
             * silinirse ae ile birlikte ÜYELİKLERİ de kaybolur. İki iş
             * birbirinin kaydını silip yazmaya başlar.
             */
            // Zabbix'te artık görünmeyen arayüzler envanterden düşer.
            $switch->ports()
                ->whereNotIn('name', array_column($ports, 'name'))
                ->delete();
        });
    }

    /**
     * Zabbix net.if.speed item'ı bit/sn birimindedir (units=bps); veritabanında
     * megabit tutulur. Doğrudan yazılırsa hız bir milyon kat büyük görünür.
     */
    private function speedInMbps(?string $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $bitsPerSecond = (float) $value;

        return $bitsPerSecond <= 0 ? null : (int) round($bitsPerSecond / 1_000_000);
    }

    private function operStatus(?string $value): PortStatus
    {
        return match ($value) {
            '1' => PortStatus::Up,
            '2' => PortStatus::Down,
            '3' => PortStatus::Testing,
            '5' => PortStatus::Dormant,
            default => PortStatus::Unknown,
        };
    }

    private function duplex(?string $value): Duplex
    {
        return match ($value) {
            '2' => Duplex::Half,
            '3' => Duplex::Full,
            default => Duplex::Auto,
        };
    }
}
