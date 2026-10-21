<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchAnalyzer;
use App\Support\Netconf\ConfigurationFilter;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * Port aynalama (analyzer) oturumlarını cihazdan okur.
 *
 * ELS öncesi EX3200/EX4200'de aynalama "ethernet-switching-options analyzer"
 * altındadır; şemanın geçerliliği test1'de commit check ile ölçüldü.
 *
 * Okuma İKİ YAZIMA DA dayanıklıdır: Junos bir listeyi ya
 * <interface><name>x</name></interface> ya da <interface>x</interface>
 * biçiminde döndürebilir. Yalnızca birine bakan bir okuyucu, cihazda duran
 * kaynağı hiç görmez ve ilk güncellemede sessizce siler — bu kod tabanında
 * en zararlı hata sınıfı budur.
 *
 * Panelin tanımadığı çocuk düğümler HER SEVİYEDE ham XML olarak saklanır
 * (residual_xml) ve geri yazılırken aynen basılır; analyzer bütün olarak
 * yeniden kurulduğu için saklanmayan her satır kaybolurdu.
 */
class AnalyzerReader
{
    /**
     * Panelin analyzer düğümünün altında ürettiği elemanlar.
     *
     * @var list<string>
     */
    private const ANALYZER_CHILDREN = ['name', 'ratio', 'loss-priority', 'input', 'output'];

    /**
     * @var list<string>
     */
    private const INPUT_CHILDREN = ['ingress', 'egress'];

    /**
     * "ingress" ve "egress" altında panelin ürettiği elemanlar.
     *
     * @var list<string>
     */
    private const DIRECTION_CHILDREN = ['interface', 'vlan'];

    /**
     * @var list<string>
     */
    private const OUTPUT_CHILDREN = ['interface', 'vlan'];

    /**
     * Çıkış VLAN'ının altında panelin ürettiği elemanlar.
     *
     * "no-tag" gibi ek satırlar burada MODELLENMEZ; korunan satır olarak
     * saklanır ve geri yazılır. Modellenmiş gibi davranmak, ölçülmemiş bir
     * alanı forma koymak olurdu.
     *
     * @var list<string>
     */
    private const OUTPUT_VLAN_CHILDREN = ['name', 'vlan-name'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws NetconfException
     */
    public function analyzers(NetconfSession $session, ?NetworkSwitch $switch = null): array
    {
        return $this->parse($session->rpc(
            '<get-configuration database="committed">'
            .ConfigurationFilter::build($switch, ['ethernet-switching-options', 'forwarding-options'])
            .'</get-configuration>'
        ));
    }

    /**
     * Ayrıştırma ayrı durur: aynı yanıttan başka bir soru soran okuyucular
     * RPC'yi tekrarlamadan bunu kullanabilsin (oturum pahalı, RPC ucuz).
     *
     * @return list<array<string, mixed>>
     */
    public function parse(SimpleXMLElement $reply): array
    {
        $analyzers = [];

        $kok = $reply->xpath(
            /*
             * Aynalama ELS'te "forwarding-options" altındadır (test4'te
             * ölçüldü); ELS öncesinde "ethernet-switching-options". Okuyucu
             * cihazın şemasını bilmediği için ikisine de bakar.
             */
            '//*[local-name()="ethernet-switching-options" or local-name()="forwarding-options"]'
            .'/*[local-name()="analyzer"]'
        ) ?: [];

        foreach ($kok as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            $input = $this->child($node, 'input');
            $ingress = $input === null ? null : $this->child($input, 'ingress');
            $egress = $input === null ? null : $this->child($input, 'egress');
            $output = $this->child($node, 'output');
            $outputVlan = $output === null ? null : $this->child($output, 'vlan');

            $analyzers[] = [
                'name' => $name,
                'ratio' => $this->number($node, 'ratio'),
                'loss_priority' => $this->text($node, 'loss-priority'),

                'ingress_interfaces' => $this->identifiers($ingress, 'interface'),
                'ingress_vlans' => $this->identifiers($ingress, 'vlan'),
                'egress_interfaces' => $this->identifiers($egress, 'interface'),
                'egress_vlans' => $this->identifiers($egress, 'vlan'),

                'output_interface' => $output === null
                    ? null
                    : ($this->identifiers($output, 'interface')[0] ?? null),
                'output_vlan' => $outputVlan === null ? null : $this->identifier($outputVlan),

                'residual_xml' => array_filter([
                    SwitchAnalyzer::RESIDUAL_ROOT => ResidualXml::collect($node, self::ANALYZER_CHILDREN),
                    SwitchAnalyzer::RESIDUAL_INPUT => $input === null
                        ? [] : ResidualXml::collect($input, self::INPUT_CHILDREN),
                    SwitchAnalyzer::RESIDUAL_INGRESS => $ingress === null
                        ? [] : ResidualXml::collect($ingress, self::DIRECTION_CHILDREN),
                    SwitchAnalyzer::RESIDUAL_EGRESS => $egress === null
                        ? [] : ResidualXml::collect($egress, self::DIRECTION_CHILDREN),
                    SwitchAnalyzer::RESIDUAL_OUTPUT => $output === null
                        ? [] : ResidualXml::collect($output, self::OUTPUT_CHILDREN),
                    SwitchAnalyzer::RESIDUAL_OUTPUT_VLAN => $outputVlan === null
                        ? [] : ResidualXml::collect($outputVlan, self::OUTPUT_VLAN_CHILDREN),
                ]),
            ];
        }

        return $analyzers;
    }

    /**
     * Bir listenin bütün değerleri.
     *
     * @return list<string>
     */
    private function identifiers(?SimpleXMLElement $node, string $element): array
    {
        if ($node === null) {
            return [];
        }

        $values = [];

        foreach ($node->xpath('./*[local-name()="'.$element.'"]') ?: [] as $child) {
            $value = $this->identifier($child);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Düğümün kimliği: ya alt elemanda ya düz metinde.
     */
    private function identifier(SimpleXMLElement $node): ?string
    {
        foreach (['name', 'vlan-name'] as $element) {
            $value = $this->text($node, $element);

            if ($value !== null) {
                return $value;
            }
        }

        $duz = trim((string) $node);

        return $duz === '' ? null : $duz;
    }

    private function child(SimpleXMLElement $node, string $element): ?SimpleXMLElement
    {
        return $node->xpath('./*[local-name()="'.$element.'"]')[0] ?? null;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }

    private function number(SimpleXMLElement $node, string $element): ?int
    {
        $value = $this->text($node, $element);

        return $value === null || ! ctype_digit($value) ? null : (int) $value;
    }
}
