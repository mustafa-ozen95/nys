<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\SwitchPortSample;
use App\Services\Measurement\MeasurementResolver;
use App\Services\Zabbix\ZabbixHistoryReader;
use App\Services\Zabbix\ZabbixHostMap;
use App\Services\Zabbix\ZabbixItemReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Arayüz trafiği.
 *
 * Anlık değer ölçüm kaynağından gelir ve kısa süre önbelleklenir; kaynağın
 * hangisi olduğunu çözümleyici seçer, bu sınıf bilmez. Kaynak yanıt vermezse
 * boş dizi döner — sayfa çalışmaya devam eder, yalnızca trafik sütunu boş
 * kalır. Burada istisna yükselseydi port sayfası izleme sistemi kesintisinde
 * tamamen açılmazdı.
 *
 * Geçmiş iki yerden okunabilir:
 *   izleme sistemi — asıl kaynak, geriye dönük tam veri.
 *   panelin kendi örnekleri — ölçüm cihazdan alınıyorsa. Cihaz geçmiş
 *     tutmadığı için grafik ileriye doğru dolar.
 */
class SwitchTrafficService
{
    public function __construct(
        private MeasurementResolver $resolver,
        private ZabbixHostMap $hosts,
        private ZabbixItemReader $items,
        private ZabbixHistoryReader $history,
    ) {}

    /**
     * Birden çok switchin trafiği okunacaksa kaynağa önceden haber verir.
     *
     * Yalnızca önbelleği soğuk olanlar sorulur: sıcak olanlar zaten hiç
     * çağrı yapmayacak, onlar için hazırlık boşa giderdi.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     */
    public function prefetch(Collection $switches): void
    {
        $this->resolver->prefetch(
            $switches->reject(fn (NetworkSwitch $switch): bool => Cache::has("switch:{$switch->id}:traffic")),
        );
    }

    /**
     * Switchteki tüm portların anlık bit/sn değeri.
     *
     * @return array<int, array{in: ?float, out: ?float}> ifIndex => değerler
     */
    public function current(NetworkSwitch $switch): array
    {
        return Cache::remember(
            "switch:{$switch->id}:traffic",
            (int) config('zabbix.cache.traffic'),
            fn (): array => $this->rates($switch),
        );
    }

    /**
     * Anlık trafik. İSTEK CİHAZA GİTMEZ.
     *
     * Değer istek içinde ölçüm kaynağından okunsaydı, izleme sistemi o switch
     * için veri döndürmediğinde çözümleyici CİHAZA GERİ DÜŞER ve her switch
     * için NETCONF oturumu açardı: switch başına birkaç saniye, yani ana
     * sayfa switch sayısıyla birlikte dakikalara uzardı. Ayarda "kaynak
     * zabbix" yazması da korumaz — yavaşlatan şey ayarın kendisi değil, geri
     * düşüştür.
     *
     * NETCONF kimliği olan switchin örneklerini SyncSwitchPortsJob zaten
     * topluyor ve veritabanına yazıyor; istek onları okur. Kimliği OLMAYAN
     * switchte cihaza gidilemez zaten (kimlik yok), orada kaynak doğrudan
     * sorulur ve toplu okunduğu için ucuzdur.
     *
     * @return array<int, array{in: ?float, out: ?float}>
     */
    private function rates(NetworkSwitch $switch): array
    {
        return $switch->netconfCredential === null
            ? ($this->resolver->ports($switch)?->rates() ?? [])
            : $this->latestSamples($switch);
    }

    /**
     * Panelin KENDİ topladığı son örnekler.
     *
     * Değer istek içinde ölçüm kaynağından okunsaydı, kaynak NETCONF olduğunda
     * her switch için CİHAZA OTURUM AÇILIRDI: switch başına birkaç saniye,
     * switch sayısıyla birlikte dakikalar. Örnekleri zaten SyncSwitchPortsJob
     * topluyor ve veritabanına yazıyor; istek onları okur, cihaza gitmez.
     *
     * TAZELİK SINIRI var: eski bir örnek "anlık trafik" değildir. Sınırı
     * aşan değer basılmaz, sütun boş kalır — yanlış bir sayı, sayı
     * olmamasından kötüdür.
     *
     * @return array<int, array{in: ?float, out: ?float}> ifIndex => değerler
     */
    private function latestSamples(NetworkSwitch $switch): array
    {
        $sinir = now()->subSeconds((int) config('measurement.sample_freshness'));

        $satirlar = SwitchPortSample::query()
            ->join('switch_ports', 'switch_ports.id', '=', 'switch_port_samples.switch_port_id')
            ->where('switch_ports.switch_id', $switch->id)
            ->whereNotNull('switch_ports.if_index')
            ->where('switch_port_samples.sampled_at', '>=', $sinir)
            ->whereIn('switch_port_samples.id', function ($sorgu) use ($switch): void {
                $sorgu->selectRaw('max(s.id)')
                    ->from('switch_port_samples as s')
                    ->join('switch_ports as p', 'p.id', '=', 's.switch_port_id')
                    ->where('p.switch_id', $switch->id)
                    ->groupBy('s.switch_port_id');
            })
            ->get(['switch_ports.if_index', 'switch_port_samples.in_bps', 'switch_port_samples.out_bps']);

        return $satirlar
            ->mapWithKeys(fn ($satir): array => [
                (int) $satir->if_index => [
                    'in' => $satir->in_bps === null ? null : (float) $satir->in_bps,
                    'out' => $satir->out_bps === null ? null : (float) $satir->out_bps,
                ],
            ])
            ->all();
    }

    /**
     * Tek portun geçmiş trafiği.
     *
     * @return array{labels: array<int, int>, in: array<int, float>, out: array<int, float>, trend: bool}
     */
    public function history(SwitchPort $port, string $range): array
    {
        $seconds = (int) config("zabbix.chart_ranges.{$range}");
        $to = time();
        $from = $to - $seconds;

        if ($this->resolver->activeSource($port->switch)?->readsDevice() === true) {
            return $this->fromSamples($port, $from, $to);
        }

        return $this->fromMonitoring($port, $from, $to);
    }

    /**
     * @return array{labels: array<int, int>, in: array<int, float>, out: array<int, float>, trend: bool}
     */
    private function fromMonitoring(SwitchPort $port, int $from, int $to): array
    {
        $hostId = $this->hosts->hostIdFor($port->switch);

        if ($hostId === null || $port->if_index === null) {
            return $this->emptySeries();
        }

        $bag = $this->items->forHost($hostId);
        $keys = config('zabbix.items');

        $inId = $bag->itemIdsByIfIndex($keys['interface_in'])[$port->if_index] ?? null;
        $outId = $bag->itemIdsByIfIndex($keys['interface_out'])[$port->if_index] ?? null;

        if ($inId === null && $outId === null) {
            return $this->emptySeries();
        }

        $rows = $this->history->series(array_filter([$inId, $outId]), $from, $to);
        $labels = $rows->pluck('clock')->unique()->sort()->values()->all();

        return [
            'labels' => $labels,
            'in' => $this->valuesFor($rows, $inId, $labels),
            'out' => $this->valuesFor($rows, $outId, $labels),
            'trend' => $this->history->usesTrends($from, $to),
        ];
    }

    /**
     * Panelin kendi örnekleri. Ölçüm cihazdan alındığında geçmiş yalnızca
     * burada vardır ve örnekleme başladığı andan itibaren doludur.
     *
     * @return array{labels: array<int, int>, in: array<int, float>, out: array<int, float>, trend: bool}
     */
    private function fromSamples(SwitchPort $port, int $from, int $to): array
    {
        $samples = SwitchPortSample::query()
            ->where('switch_port_id', $port->id)
            ->between($from, $to)
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'in_bps', 'out_bps']);

        return [
            'labels' => $samples->map(fn (SwitchPortSample $sample): int => $sample->sampled_at->getTimestamp())->all(),
            'in' => $samples->map(fn (SwitchPortSample $sample): float => (float) $sample->in_bps)->all(),
            'out' => $samples->map(fn (SwitchPortSample $sample): float => (float) $sample->out_bps)->all(),
            'trend' => false,
        ];
    }

    /**
     * Bir serinin değerleri, ZAMAN ETİKETLERİNE HİZALI.
     *
     * Hizalama şart: gelen ve giden serileri farklı sayıda nokta
     * döndürebiliyor (bir sayaç geç toplanmışsa, item aralığı farklıysa).
     * Değerler sırayla dizilirse eksik seri etiketlerin başına sıkışır —
     * grafikte "gelen" solda küçük bir tutam olarak görünüp geri kalanı boş
     * kalır.
     *
     * Ölçüm olmayan an null'dır; grafik orayı çizmez, sıfır saymaz.
     *
     * @param  Collection<int, array{clock: int, itemid: string, value: float}>  $rows
     * @param  array<int, int>  $labels
     * @return array<int, ?float>
     */
    private function valuesFor(Collection $rows, ?string $itemId, array $labels): array
    {
        if ($itemId === null) {
            return [];
        }

        $byClock = $rows->where('itemid', $itemId)->keyBy('clock');

        return array_map(
            static fn (int $clock): ?float => isset($byClock[$clock]) ? (float) $byClock[$clock]['value'] : null,
            $labels,
        );
    }

    /**
     * @return array{labels: array<int, int>, in: array<int, float>, out: array<int, float>, trend: bool}
     */
    private function emptySeries(): array
    {
        return ['labels' => [], 'in' => [], 'out' => [], 'trend' => false];
    }
}
