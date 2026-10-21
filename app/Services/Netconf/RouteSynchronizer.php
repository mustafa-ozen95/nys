<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use App\Enums\RouteProtocol;
use App\Models\NetworkSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Route tablosunu cihazdan envantere yazar.
 *
 * İki kaynak birleştirilir:
 *
 * - Aktif routing tablosu (get-route-information): cihazın gerçekten
 *   kullandığı yollar. Öğrenilen yollar (Direct, Local, OSPF, BGP) yalnızca
 *   burada bulunur.
 * - Yapılandırmadaki statik yollar (routing-options static): sonraki atlaması
 *   erişilemeyen statik route tabloya kurulmaz ama config'de durur. Yalnızca
 *   tabloya bakılsaydı panelden yazılan route kaybolmuş görünürdü.
 *
 * Tabloda bulunan yol aktif, yalnızca config'de kalan statik yol pasiftir.
 *
 * Hem eşitleme işi hem de yazma işlemleri sonrası geri okuma bunu kullanır;
 * iki yerde ayrı birleştirme mantığı tutulmaz.
 */
class RouteSynchronizer
{
    public function __construct(private RouteReader $reader) {}

    /**
     * @throws NetconfException
     */
    public function sync(NetworkSwitch $switch, NetconfSession $session): void
    {
        $active = $this->reader->routes($session);
        $configured = $this->reader->configuredStatic($session);

        // Cihaz hiç yol döndürmediyse beklenmedik bir yanıt gelmiştir;
        // mevcut envanter silinmez.
        if ($active === [] && $configured === []) {
            return;
        }

        /*
         * Route'un YAPILANDIRMA alanları (preference, tag, qualified atlamalar,
         * korunan ham satırlar...) routing tablosunda YOKTUR; tablo yalnızca
         * kurulmuş yolu gösterir. Tablodan okunan statik route ile
         * yapılandırmadaki karşılığı eşleştirilir ki ekran ve düzenleme formu
         * tam veriyi görsün.
         */
        $bilgi = [];

        foreach ($configured as $route) {
            $bilgi[$route['destination'].'/'.$route['prefix_length']] = collect($route)
                ->only(self::CONFIGURED_FIELDS)
                ->all();
        }

        DB::transaction(function () use ($switch, $active, $configured, $bilgi): void {
            $tutulan = [];

            foreach ($active as $route) {
                $anahtar = $route['destination'].'/'.$route['prefix_length'];

                $tutulan[] = $this->store($switch, $route + ['is_active' => true] + ($bilgi[$anahtar] ?? []));
            }

            foreach ($configured as $route) {
                if ($this->isPresent($active, $route)) {
                    continue;
                }

                $tutulan[] = $this->store($switch, $route + [
                    'protocol' => RouteProtocol::Static,
                    'is_active' => false,
                ]);
            }

            $switch->routes()->whereNotIn('id', $tutulan)->delete();
        });
    }

    /**
     * Yapılandırmadan gelen ve envantere yazılan alanlar.
     *
     * Öğrenilen yollarda (BGP, OSPF) karşılıkları yoktur ve null kalırlar.
     *
     * @var list<string>
     */
    private const CONFIGURED_FIELDS = [
        'rib', 'routing_instance', 'next_hops', 'qualified_next_hops', 'preference', 'metric',
        'tag', 'tag2', 'flags', 'communities', 'next_table', 'residual_xml',
        'unmanageable_reason',
    ];

    /**
     * @param  array<string, mixed>  $route
     */
    private function store(NetworkSwitch $switch, array $route): int
    {
        $configured = collect($route)->only(self::CONFIGURED_FIELDS)->all();

        return $switch->routes()->updateOrCreate(
            [
                'destination' => $route['destination'],
                'prefix_length' => $route['prefix_length'],
                'next_hop' => $route['next_hop'],
            ],
            [
                'protocol' => $route['protocol'],
                'family' => $route['family'],
                'is_active' => $route['is_active'],
            ] + array_map(
                static fn (string $field) => $configured[$field] ?? null,
                array_combine(self::CONFIGURED_FIELDS, self::CONFIGURED_FIELDS),
            ),
        )->id;
    }

    /**
     * @param  list<array{destination: string, prefix_length: int, next_hop: ?string, protocol: RouteProtocol, family: IpFamily}>  $active
     * @param  array{destination: string, prefix_length: int, next_hop: ?string, family: IpFamily}  $route
     */
    private function isPresent(array $active, array $route): bool
    {
        foreach ($active as $mevcut) {
            if ($mevcut['destination'] === $route['destination']
                && $mevcut['prefix_length'] === $route['prefix_length']) {
                return true;
            }
        }

        return false;
    }
}
