<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * RIB gruplarını ve arayüz yollarının hangi gruba kopyalandığını okur.
 *
 * Filtre tabanlı yönlendirmenin üçüncü parçasıdır: tablo tanımlı ve firewall
 * terimi trafiği oraya atıyor olsa bile, arayüz yolları tabloya kopyalanmazsa
 * geri dönüş yolu bulunamaz.
 *
 * Biçim test1'de ölçüldü: import-rib YİNELENEN düz elemanlardır (sarmalayıcı
 * yoktur) ve sıraları anlamlıdır.
 */
class RibGroupReader
{
    /** @var list<string> */
    private const KNOWN_CHILDREN = ['name', 'import-rib'];

    /**
     * @return array{groups: list<array{name: string, import_ribs: list<string>, residual_xml: list<string>}>, interface_routes: ?string}
     *
     * @throws NetconfException
     */
    public function read(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><routing-options/></configuration>'
            .'</get-configuration>'
        );

        $groups = [];

        foreach ($reply->xpath('//*[local-name()="routing-options"]/*[local-name()="rib-groups"]') ?: [] as $node) {
            $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

            if ($name === '') {
                continue;
            }

            $ribs = [];

            foreach ($node->xpath('./*[local-name()="import-rib"]') ?: [] as $rib) {
                $value = trim((string) $rib);

                if ($value !== '') {
                    $ribs[] = $value;
                }
            }

            $groups[] = [
                'name' => $name,
                'import_ribs' => $ribs,
                'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
            ];
        }

        return [
            'groups' => $groups,
            'interface_routes' => $this->interfaceRoutes($reply),
        ];
    }

    /**
     * Arayüz yollarının kopyalandığı grup: switch başına TEK işaretçi.
     */
    private function interfaceRoutes(SimpleXMLElement $reply): ?string
    {
        $value = trim((string) ($reply->xpath(
            '//*[local-name()="interface-routes"]/*[local-name()="rib-group"]/*[local-name()="inet"]'
        )[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
