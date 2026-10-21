<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * Cihazın policy-options prefix list'lerini NETCONF ile okur.
 *
 * Bu veri yalnızca yapılandırmada durur; SNMP'de karşılığı yoktur. Boş bir
 * prefix list gerçek bir bulgudur: uplink çıkış filtresi (koruma) boş bir
 * listeye dayanıyorsa bütün trafiği düşürür, o yüzden girdisi olmayan liste
 * de kaydedilir.
 *
 * Açık bir oturum alır; bağlantıyı kendisi açmaz (bkz. .ai/rules/netconf.md).
 *
 * Girdi ayrıştırma dolu bir listeyle sınanır
 * (tests/Fixtures/netconf/policy-options-backbone.xml).
 */
class PrefixListReader
{
    /**
     * Listenin altında panelin MODELLEDİĞİ elemanlar. Bunların dışındaki her
     * şey ham XML olarak saklanır (residual_xml) ve kaybolmaz.
     *
     * "apply-path" modellenir: Junos bir listeyi bu ifadeyle DİNAMİK
     * doldurabiliyor (test1'de commit check ile geçerli olduğu ölçüldü).
     * Yalnızca statik girdiler okunsaydı böyle bir liste sıfır girdili
     * görünürdü — oysa boş prefix list bu kod tabanında bir TEHLİKE işaretidir
     * ve kullanıcı olmayan bir tehlikeyi düzeltmeye çalışırdı. Bu yüzden ifade
     * okunur, ekranda gösterilir ve panelden yazılabilir.
     *
     * @var list<string>
     */
    private const KNOWN_CHILDREN = ['name', 'prefix-list-item', 'apply-path'];

    /**
     * @return list<array{name: string, apply_paths: list<string>, residual_xml: list<string>, entries: list<array{address: string, prefix_length: int, family: IpFamily}>}>
     *
     * @throws NetconfException
     */
    public function prefixLists(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><policy-options/></configuration>'
            .'</get-configuration>'
        );

        $lists = [];

        foreach ($reply->xpath('//*[local-name()="prefix-list"]') ?: [] as $node) {
            $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

            if ($name === '') {
                continue;
            }

            $entries = [];

            foreach ($node->xpath('./*[local-name()="prefix-list-item"]') ?: [] as $item) {
                $entry = $this->entry(trim((string) ($item->xpath('./*[local-name()="name"]')[0] ?? '')));

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $lists[] = [
                'name' => $name,
                'apply_paths' => $this->applyPaths($node),

                /*
                 * Panelin tanımadığı her çocuk ham hâliyle saklanır. Liste
                 * sil+yeniden yaz EDİLMEZ (girdi sırası anlamsızdır), o yüzden
                 * bu satırlar cihazda zaten yerinde kalır; saklanmalarının
                 * sebebi kullanıcıya "panelde görünmeyen N satır var" diyebilmek.
                 */
                'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
                'entries' => $entries,
            ];
        }

        return $lists;
    }

    /**
     * Listeyi dinamik dolduran ifadeler.
     *
     * Junos aynı listede birden çok apply-path taşıyabilir; yalnızca ilki
     * okunsaydı düzenleme sırasında diğerleri kaybolurdu.
     *
     * @return list<string>
     */
    private function applyPaths(SimpleXMLElement $node): array
    {
        $paths = [];

        foreach ($node->xpath('./*[local-name()="apply-path"]') ?: [] as $path) {
            $value = trim((string) $path);

            if ($value !== '') {
                $paths[] = $value;
            }
        }

        return $paths;
    }

    /**
     * @return array{address: string, prefix_length: int, family: IpFamily}|null
     */
    private function entry(string $cidr): ?array
    {
        if ($cidr === '') {
            return null;
        }

        $family = str_contains($cidr, ':') ? IpFamily::Inet6 : IpFamily::Inet;

        if (! str_contains($cidr, '/')) {
            return [
                'address' => $cidr,
                'prefix_length' => $family === IpFamily::Inet6 ? 128 : 32,
                'family' => $family,
            ];
        }

        [$address, $length] = explode('/', $cidr, 2);

        return ['address' => $address, 'prefix_length' => (int) $length, 'family' => $family];
    }
}
