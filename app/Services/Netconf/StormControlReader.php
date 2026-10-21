<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Support\Netconf\ConfigurationFilter;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * Fırtına denetimi ayarlarını cihazdan okur.
 *
 * İki şema ailesinde iki ayrı yerde durur; ikisi de aranır çünkü envanterin
 * cihazda ne olduğunu göstermesi gerekir, panelin ne yazdığını değil.
 */
class StormControlReader
{
    /**
     * Panelin modellediği çocuk düğümler; gerisi ham XML olarak korunur.
     *
     * @var list<string>
     */
    private const KNOWN_CHILDREN = [
        'name', 'level', 'bandwidth',
        'no-broadcast', 'no-unknown-unicast', 'no-multicast',
        'no-registered-multicast', 'no-unregistered-multicast',
    ];

    /**
     * @return array{shutdown: bool, interfaces: list<array<string, mixed>>}
     *
     * @throws NetconfException
     */
    public function stormControl(NetconfSession $session, ?NetworkSwitch $switch = null): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .ConfigurationFilter::build($switch, ['ethernet-switching-options', 'forwarding-options'])
            .'</get-configuration>'
        );

        $kok = $reply->xpath('//*[local-name()="ethernet-switching-options"]/*[local-name()="storm-control"]') ?: [];
        $interfaces = [];

        foreach ($kok as $storm) {
            foreach ($storm->xpath('./*[local-name()="interface"]') ?: [] as $node) {
                $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

                if ($name === '') {
                    continue;
                }

                $interfaces[] = [
                    'interface_name' => $name,
                    'level' => $this->number($node, 'level'),
                    'bandwidth' => $this->number($node, 'bandwidth'),
                    'no_broadcast' => $this->has($node, 'no-broadcast'),
                    'no_unknown_unicast' => $this->has($node, 'no-unknown-unicast'),
                    'no_multicast' => $this->has($node, 'no-multicast'),
                    'no_registered_multicast' => $this->has($node, 'no-registered-multicast'),
                    'no_unregistered_multicast' => $this->has($node, 'no-unregistered-multicast'),
                    'residual_xml' => ResidualXml::collect($node, self::KNOWN_CHILDREN),
                ];
            }
        }

        return [
            'shutdown' => $kok !== [] && $this->has($kok[0], 'action-shutdown'),
            'interfaces' => $interfaces,
        ];
    }

    private function has(SimpleXMLElement $node, string $element): bool
    {
        return ($node->xpath('./*[local-name()="'.$element.'"]') ?: []) !== [];
    }

    private function number(SimpleXMLElement $node, string $element): ?int
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' || ! ctype_digit($value) ? null : (int) $value;
    }
}
