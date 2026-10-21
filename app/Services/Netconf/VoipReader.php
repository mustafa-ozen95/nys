<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Support\Netconf\ConfigurationFilter;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * VoIP (ses) VLAN ayarlarını cihazdan okur.
 *
 * Ayar iki şema ailesinde iki ayrı kapsayıcının altında durur ve İKİSİ DE
 * aranır — envanterin göstermesi gereken şey cihazda ne olduğudur, panelin
 * ne yazdığı değil:
 *
 *   ELS öncesi : ethernet-switching-options voip interface <ad>
 *   ELS        : switch-options voip interface <ad>
 *
 * Yapı test1'de (12.3R12.4) commit check ile doğrulandı.
 */
class VoipReader
{
    /**
     * Panelin modellediği çocuk düğümler; gerisi ham XML olarak korunur.
     *
     * @var list<string>
     */
    private const KNOWN_CHILDREN = ['name', 'vlan', 'forwarding-class'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws NetconfException
     */
    public function voip(NetconfSession $session, ?NetworkSwitch $switch = null): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .ConfigurationFilter::build($switch, ['ethernet-switching-options', 'switch-options'])
            .'</get-configuration>'
        );

        $interfaces = [];

        $nodes = $reply->xpath(
            '//*[local-name()="ethernet-switching-options" or local-name()="switch-options"]'
            .'/*[local-name()="voip"]/*[local-name()="interface"]'
        ) ?: [];

        foreach ($nodes as $node) {
            $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

            if ($name === '') {
                continue;
            }

            $interfaces[] = [
                'interface_name' => $name,
                'vlan_name' => $this->text($node, 'vlan'),
                'forwarding_class' => $this->text($node, 'forwarding-class'),
                'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
            ];
        }

        return $interfaces;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
