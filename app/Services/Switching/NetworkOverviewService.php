<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\PortStatus;
use App\Enums\SwitchStatus;
use App\Models\ActivityLog;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\User;
use App\Support\Format\Bandwidth;
use Illuminate\Support\Collection;

/**
 * Genel bakış ekranının verisini hazırlar.
 *
 * View'a hazır diziler gider; Blade hesap yapmaz. Trafik değerleri Zabbix'ten
 * okunur ve kısa süre önbelleklenir.
 */
class NetworkOverviewService
{
    private const BUSIEST_PORT_LIMIT = 6;

    private const ACTIVITY_LIMIT = 8;

    public function __construct(private SwitchTrafficService $traffic) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $switches = NetworkSwitch::query()
            ->visibleTo($user)
            ->withCount('ports')
            ->orderBySeverity()
            ->orderBy('hostname')
            ->get();

        $counts = $this->counts($switches);
        $traffic = $this->trafficByNetworkSwitch($switches);

        return [
            'switches' => $switches,
            'counts' => $counts,
            'health' => $this->health($counts),
            'totals' => $this->totals($switches, $traffic),
            'busiestPorts' => $this->busiestPorts($switches, $traffic),
            'lastSync' => $switches->max('last_sync_at'),
            'activity' => $this->activity($user),
        ];
    }

    /**
     * @param  Collection<int, NetworkSwitch>  $switches
     * @return array<string, int>
     */
    /**
     * Sayaçlar TOPLAMI VERMEK ZORUNDA.
     *
     * "Bilinmiyor" durumu hiçbir kutuda sayılmasaydı, durumu bilinmeyen
     * switch ekrandaki toplamdan sessizce düşerdi.
     * Ölçüm alınamayan cihaz, sorunun görünmez olduğu yerdir; en son
     * saklanacak şeydir.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     * @return array<string, int>
     */
    private function counts(Collection $switches): array
    {
        return [
            'total' => $switches->count(),
            'online' => $switches->where('status', SwitchStatus::Online)->count(),
            'warning' => $switches->where('status', SwitchStatus::Warning)->count(),
            'offline' => $switches->where('status', SwitchStatus::Offline)->count(),
            'unknown' => $switches->where('status', SwitchStatus::Unknown)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{label: string, tone: string}
     */
    private function health(array $counts): array
    {
        /*
         * ÖLÇÜLEMEYEN CİHAZ "SAĞLIKLI" DEĞİLDİR.
         *
         * Durumu bilinmeyen switch sayılmasaydı başlık "Tüm switchler
         * sağlıklı" derdi; oysa o cihazdan ölçüm alınamıyordur.
         * Yanlış güven veren bir özet, özet olmamasından kötüdür.
         *
         * Yine de "çevrimdışı" denmiyor: ölçüm alamamak, cihazın düştüğünü
         * kanıtlamaz (bkz. SwitchStatus::Unknown).
         */
        return match (true) {
            $counts['total'] === 0 => ['label' => 'Henüz switch yok', 'tone' => 'gray'],
            $counts['offline'] > 0 => ['label' => 'Erişilemeyen switch var', 'tone' => 'red'],
            $counts['warning'] > 0 => ['label' => 'Dikkat gerekiyor', 'tone' => 'yellow'],
            $counts['unknown'] > 0 => ['label' => 'Ölçülemeyen switch var', 'tone' => 'gray'],
            default => ['label' => 'Tüm switchler sağlıklı', 'tone' => 'green'],
        };
    }

    /**
     * Switch başına ifIndex → anlık trafik.
     *
     * Ölçüm kaynağı erişilemezse genel bakış yine açılır ve trafik bölümü boş
     * kalır: envanter listesi kritik, trafik ek bilgidir. Zarif düşüş
     * SwitchTrafficService'in içindedir — hangi kaynağın konuştuğunu bilen ve
     * hepsi susarsa boş dizi döndüren yer orası.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     * @return array<int, array<int, array{in: ?float, out: ?float}>>
     */
    private function trafficByNetworkSwitch(Collection $switches): array
    {
        $toRead = $switches->filter(
            fn (NetworkSwitch $switch): bool => $switch->status !== SwitchStatus::Offline,
        );

        // Tek çağrıda hepsi; switch başına ayrı gidiş dönüş yerine.
        $this->traffic->prefetch($toRead);

        return $toRead
            ->mapWithKeys(fn (NetworkSwitch $switch): array => [$switch->id => $this->traffic->current($switch)])
            // Hiçbir kaynağın cevap veremediği switch boş dizi döner; onu
            // "trafik sıfır" saymak yanlış olur, hiç veri yok demektir.
            ->reject(fn (array $rates): bool => $rates === [])
            ->all();
    }

    /**
     * @param  Collection<int, NetworkSwitch>  $switches
     * @param  array<int, array<int, array{in: ?float, out: ?float}>>  $traffic
     * @return array<string, string|int>
     */
    private function totals(Collection $switches, array $traffic): array
    {
        $in = 0.0;
        $out = 0.0;

        foreach ($traffic as $ports) {
            foreach ($ports as $rates) {
                $in += $rates['in'] ?? 0;
                $out += $rates['out'] ?? 0;
            }
        }

        return [
            'available' => $traffic !== [],
            'in' => Bandwidth::format($in),
            'out' => Bandwidth::format($out),
            'ports' => (int) $switches->sum('ports_count'),
        ];
    }

    /**
     * En yoğun portlar. Sıralama trafiğe göredir; bu yüzden sorguda değil
     * burada yapılır.
     *
     * @param  Collection<int, NetworkSwitch>  $switches
     * @param  array<int, array<int, array{in: ?float, out: ?float}>>  $traffic
     * @return Collection<int, array<string, mixed>>
     */
    private function busiestPorts(Collection $switches, array $traffic): Collection
    {
        if ($traffic === []) {
            return collect();
        }

        return SwitchPort::query()
            ->with('switch')
            ->whereIn('switch_id', array_keys($traffic))
            ->where('oper_status', PortStatus::Up)
            ->get()
            ->map(function (SwitchPort $port) use ($traffic): array {
                $rates = $traffic[$port->switch_id][$port->if_index] ?? ['in' => null, 'out' => null];
                $peak = max($rates['in'] ?? 0, $rates['out'] ?? 0);

                return [
                    'port' => $port,
                    'peak' => $peak,
                    'display' => Bandwidth::format($peak),
                    'utilisation' => Bandwidth::utilisation($peak ?: null, $port->speed_mbps),
                ];
            })
            ->filter(fn (array $row): bool => $row['peak'] > 0)
            ->sortByDesc('peak')
            ->take(self::BUSIEST_PORT_LIMIT)
            ->values();
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    private function activity(User $user): Collection
    {
        if (! $user->can('viewAny', ActivityLog::class)) {
            return collect();
        }

        return ActivityLog::query()->with('user')->latest()->limit(self::ACTIVITY_LIMIT)->get();
    }
}
