<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use SimpleXMLElement;

/**
 * Bir BGP komşusundan ALINAN prefixleri okur ve kabul edilenle reddedileni
 * ayırır.
 *
 * Junos karşılığı "show route receive-protocol bgp <komşu> all". Tek RPC ile
 * ikisi birden gelir; ayrımı `active-tag` yapar:
 *
 *   <active-tag>*</active-tag>  -> kabul edildi, tabloda etkin
 *   <active-tag> </active-tag>  -> içeri alma politikası reddetti (hidden)
 *
 * "all" OLMADAN yalnızca kabul edilenler döner; reddedilenler görünmez ve
 * panel "hepsini kabul ediyoruz" gibi yanlış bir izlenim verirdi.
 *
 * Veri SAKLANMAZ: alınan tablo binlerce satır olabilir ve sürekli değişir.
 * Kullanıcı istediğinde okunur (bkz. .ai/rules/netconf.md — poll edilmez).
 */
class ReceivedRouteReader
{
    /**
     * Panelin ilgilendiği tablolar. Junos ayrıca __juniper_private*__
     * tablolarını da döndürür; onlar cihazın iç kullanımıdır.
     *
     * @var list<string>
     */
    private const TABLES = ['inet.0', 'inet6.0'];

    /**
     * @return array{
     *     accepted: list<array{prefix: string, as_path: ?string, next_hop: ?string}>,
     *     rejected: list<array{prefix: string, as_path: ?string, next_hop: ?string}>
     * }
     *
     * @throws NetconfException
     */
    public function forNeighbor(NetconfSession $session, string $address): array
    {
        $reply = $session->rpc(
            '<get-route-information>'
            .'<receive-protocol-name>bgp</receive-protocol-name>'
            .'<peer>'.htmlspecialchars($address, ENT_XML1 | ENT_QUOTES).'</peer>'
            .'<all/>'
            .'</get-route-information>'
        );

        $accepted = [];
        $rejected = [];

        foreach ($reply->xpath('//*[local-name()="route-table"]') ?: [] as $table) {
            if (! in_array($this->text($table, 'table-name'), self::TABLES, true)) {
                continue;
            }

            foreach ($table->xpath('./*[local-name()="rt"]') ?: [] as $node) {
                $route = $this->route($node);

                if ($route === null) {
                    continue;
                }

                if ($route['accepted']) {
                    $accepted[] = $route['route'];
                } else {
                    $rejected[] = $route['route'];
                }
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @return array{accepted: bool, route: array{prefix: string, as_path: ?string, next_hop: ?string}}|null
     */
    private function route(SimpleXMLElement $node): ?array
    {
        $prefix = $this->text($node, 'rt-destination');

        if ($prefix === null) {
            return null;
        }

        $entry = $node->xpath('./*[local-name()="rt-entry"]')[0] ?? null;

        if ($entry === null) {
            return null;
        }

        /*
         * active-tag "*" ise yol etkindir. Reddedilen yolda eleman VARDIR ama
         * içi tek boşluktur; varlığına bakan bir kontrol ikisini ayırt edemez.
         */
        $active = $entry->xpath('./*[local-name()="active-tag"]')[0] ?? null;

        return [
            'accepted' => trim((string) $active) === '*',
            'route' => [
                'prefix' => $prefix,
                'as_path' => $this->text($entry, 'as-path'),
                'next_hop' => $this->text($entry, 'nh/to'),
            ],
        ];
    }

    private function text(SimpleXMLElement $node, string $path): ?string
    {
        $parts = array_map(
            static fn (string $part): string => '*[local-name()="'.$part.'"]',
            explode('/', $path),
        );

        $found = $node->xpath('./'.implode('/', $parts))[0] ?? null;

        if ($found === null) {
            return null;
        }

        $value = trim((string) $found);

        return $value === '' ? null : $value;
    }
}
