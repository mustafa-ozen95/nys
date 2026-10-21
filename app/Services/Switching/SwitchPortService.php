<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Support\Format\Bandwidth;
use Illuminate\Support\Collection;

/**
 * Port listesinin hazır verisi.
 *
 * Anlık trafik Zabbix'ten gelir ve veritabanındaki port kaydıyla ifIndex
 * üzerinden eşleşir; Blade bu birleştirmeyi yapmaz.
 */
class SwitchPortService
{
    public function __construct(private SwitchTrafficService $traffic) {}

    /**
     * @return array<string, mixed>
     */
    public function forNetworkSwitch(
        NetworkSwitch $switch,
        ?string $filter = null,
        ?string $selected = null,
    ): array {
        $traffic = $this->traffic->current($switch);

        /*
         * Yalnızca FİZİKSEL portlar. ae arayüzlerinin hız, PoE ve
         * auto-negotiation gibi alanları yoktur; bu ekranın sütunları onlar
         * için anlamsızdır ve ayrı bir ekranda (Bağlantı toplama) yönetilirler.
         */
        /*
         * Arayüze bağlı firewall filtreleri: bağlama FİLTRENİN üstünde durur
         * (hangi arayüzlere bağlı), portun üstünde değil. Ekranda portun
         * yanında göstermek için ters çevrilir.
         */
        $bindings = $this->bindings($switch);

        $ports = $switch->ports()
            ->physical()
            ->with(['vlans', 'ipAddresses'])
            ->search($filter)
            ->orderBy('if_index')
            ->get()
            ->map(fn (SwitchPort $port): array => $this->present($port, $traffic, $selected, $bindings));

        return [
            'ports' => $ports,
            'summary' => $this->summary($ports),
        ];
    }

    /**
     * Arayüz adı => ['input' => filtre adı, 'output' => filtre adı].
     *
     * @return array<string, array<string, string>>
     */
    private function bindings(NetworkSwitch $switch): array
    {
        $bindings = [];

        foreach ($switch->firewallFilters()->get(['name', 'bindings']) as $filter) {
            foreach ((array) $filter->bindings as $binding) {
                $arayuz = $binding['interface'] ?? null;
                $yon = $binding['direction'] ?? null;

                if ($arayuz !== null && $yon !== null) {
                    $bindings[$arayuz][$yon] = (string) $filter->name;
                }
            }
        }

        return $bindings;
    }

    /**
     * Bir portun mantıksal biriminde (.0) bağlı filtreler: ['input' => ad, 'output' => ad].
     *
     * Yazma yolu "değişiyor mu" sorusunu bununla sorar.
     *
     * @return array<string, string>
     */
    public function boundFilters(NetworkSwitch $switch, string $portName): array
    {
        return $this->bindings($switch)[$portName.'.0'] ?? [];
    }

    /**
     * @param  array<int, array{in: ?float, out: ?float}>  $traffic
     * @return array<string, mixed>
     */
    private function present(SwitchPort $port, array $traffic, ?string $selected, array $bindings): array
    {
        $rates = $traffic[$port->if_index] ?? ['in' => null, 'out' => null];
        $bagli = $bindings[$port->name.'.0'] ?? [];

        return [
            'model' => $port,
            'selected' => $selected === $port->name,

            // Yönlendirme modunda düzenlenebilen filtre bağlamaları.
            'input_filter' => $bagli['input'] ?? null,
            'output_filter' => $bagli['output'] ?? null,

            /*
             * Modalda SEÇİLİ gelecek mod. Yalnızca YAPILANDIRILMAMIŞ portta
             * null: o bir mod değil, hiç ayarlanmamış olma hâlidir ve listede
             * karşılığı yoktur. Karşılığı olmayan bir değerde tarayıcı ilk
             * seçeneğe ("Access") düşüyor ve kaydeden kullanıcı portun
             * yapılandırmasını uyarısız siliyordu.
             */
            'selected_mode' => in_array(
                $port->port_mode,
                [PortMode::Access, PortMode::Trunk, PortMode::Routed],
                true,
            ) ? $port->port_mode->value : null,

            // Üye portun modalı hangi toplama arayüzüne ait olduğunu söyler.
            'aggregate' => $port->aggregate?->name,
            'in' => $rates['in'],
            'out' => $rates['out'],
            'in_display' => Bandwidth::format($rates['in']),
            'out_display' => Bandwidth::format($rates['out']),
            'utilisation' => Bandwidth::utilisation(
                max($rates['in'] ?? 0, $rates['out'] ?? 0) ?: null,
                $this->linkSpeed($port),
            ),
            'link_speed_display' => $this->linkSpeedDisplay($port),

            /*
             * Basit modda düzenlenmeyen alanların CİHAZDAKİ değeri.
             *
             * Varsayılandaki alan null döner ve ekranda hiç anılmaz; dolu
             * olan salt okunur bir satır olarak görünür. Zorla 100 Mbps'e
             * çekilmiş ya da MTU'su büyütülmüş bir port, basit moddaki
             * kullanıcıya da görünmek zorundadır (bkz. Form\Field).
             */
            'speed_current' => $port->speed_config === null ? null : $port->speed_config.' Mbps',
            'duplex_current' => $this->duplexDisplay($port),
            'mtu_current' => $port->mtu === null ? null : $port->mtu.' bayt',
        ];
    }

    /** Ayarlanmış duplex. Boş bırakılmışsa cihaz kendi seçer, söylenecek bir şey yok. */
    private function duplexDisplay(SwitchPort $port): ?string
    {
        return match ($port->duplex_config) {
            null, '' => null,
            'full' => 'Full duplex',
            'half' => 'Half duplex',
            default => $port->duplex_config,
        };
    }

    /**
     * Portun ANLAŞILMIŞ hızı. Link yoksa hız da yoktur.
     *
     * Değer SNMP'den (ifSpeed) geliyor ve Junos, linki olmayan bakır portta
     * bunu 100 Mbps diye bildiriyor — otomatik anlaşmaya ayarlanmış, kablosu
     * takılı olmayan bir port panelde "100 Mbps" görünüyordu ve zorla 100'e
     * sabitlenmiş sanılıyordu. Kablo takılınca değer zaten gerçekleşir.
     *
     * Ayarlanmış hız AYRI bir alandır (speed_config) ve düzenleme
     * penceresinde durur; bu sütun ölçümdür, niyet değil.
     */
    private function linkSpeed(SwitchPort $port): ?int
    {
        return $port->oper_status === PortStatus::Up ? $port->speed_mbps : null;
    }

    private function linkSpeedDisplay(SwitchPort $port): string
    {
        $hiz = $this->linkSpeed($port);

        return $hiz === null ? '—' : $hiz.' Mbps';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ports
     * @return array<string, int>
     */
    private function summary(Collection $ports): array
    {
        $up = $ports->filter(
            fn (array $port): bool => $port['model']->oper_status === PortStatus::Up
        )->count();

        return [
            'total' => $ports->count(),
            'up' => $up,
            'down' => $ports->count() - $up,
        ];
    }
}
