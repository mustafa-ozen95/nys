<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\SecureAccessAction;
use App\Models\NetworkSwitch;
use App\Support\Netconf\ConfigurationFilter;
use App\Support\Netconf\ResidualXml;
use SimpleXMLElement;

/**
 * DHCP snooping ve port güvenliği ayarlarını cihazdan okur.
 *
 * Kaynak: ethernet-switching-options secure-access-port (ELS öncesi).
 * Cihazda ölçüldü (test1, commit check): "vlan all { examine-dhcp; }" KABUL.
 *
 * BİLİNEN LİSTESİ SABİT DEĞİL, ANLAŞILANA GÖRE KURULUR.
 *
 * Sabit bir "bilinen çocuklar" listesi bu düğümde tehlikelidir. Bir eleman
 * bilinen sayıldığı anda ham XML olarak KORUNMAZ (ResidualXml onu toplamaz)
 * ve yazma yolu da onu panelin modelinden üretir. Panel o elemanı
 * ayrıştıramamışsa — cihazın biçimi beklediğimizden farklıysa — satır önce
 * ekranda kaybolur, sonra ilk düzenlemede CİHAZDAN silinir.
 *
 * Bu yüzden mac-limit / mac-move-limit / allowed-mac ancak GERÇEKTEN
 * çözümlenebildiğinde "bilinen" sayılır; çözümlenemeyen her biçim artık
 * listesine düşer ve olduğu gibi geri yazılır. Ölçülmemiş bir XML biçimi
 * yüzünden yapılandırma kaybedilmez.
 *
 * Ölçülen tek biçim examine-dhcp'dir; sınırların XML biçimi (aşağıdaki iki
 * şekil) Juniper belgesine göre yazıldı ve cihazda DOĞRULANMADI.
 */
class SecureAccessPortReader
{
    /**
     * Biçimi tartışmasız olan çocuklar: ya var ya yok.
     *
     * @var list<string>
     */
    private const VLAN_FLAGS = ['name', 'examine-dhcp', 'arp-inspection', 'ip-source-guard'];

    /**
     * @var list<string>
     */
    private const INTERFACE_FLAGS = ['name', 'dhcp-trusted', 'no-dhcp-trusted'];

    /**
     * @return array{vlans: list<array<string, mixed>>, interfaces: list<array<string, mixed>>}
     *
     * @throws NetconfException
     */
    public function secureAccessPort(NetconfSession $session, ?NetworkSwitch $switch = null): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .ConfigurationFilter::build($switch, ['ethernet-switching-options', 'switch-options', 'vlans'])
            .'</get-configuration>'
        );

        $roots = $reply->xpath(
            '//*[local-name()="ethernet-switching-options"]/*[local-name()="secure-access-port"]'
        ) ?: [];

        $vlans = $this->elsVlans($reply);
        $interfaces = $this->elsInterfaces($reply);

        foreach ($roots as $root) {
            foreach ($root->xpath('./*[local-name()="vlan"]') ?: [] as $node) {
                $row = $this->vlan($node);

                if ($row !== null) {
                    $vlans[] = $row;
                }
            }

            foreach ($root->xpath('./*[local-name()="interface"]') ?: [] as $node) {
                $row = $this->interface($node);

                if ($row !== null) {
                    $interfaces[] = $row;
                }
            }
        }

        return ['vlans' => $vlans, 'interfaces' => $interfaces];
    }

    /**
     * ELS'te VLAN ayarları "secure-access-port" altında DEĞİL, VLAN'ın kendi
     * içindedir (test4'te ölçüldü):
     *
     *   vlans <v> forwarding-options dhcp-security   snooping, arp, source guard
     *   vlans <v> switch-options mac-move-limit
     *
     * ANLAM TERSTİR: düğümün varlığı snooping'i AÇAR; kapalıysa
     * "no-dhcp-snooping" yazılıdır. Okuyucu bunu çevirir, yoksa panel kapalı
     * bir VLAN'ı açık gösterirdi.
     *
     * @return list<array<string, mixed>>
     */
    private function elsVlans(SimpleXMLElement $reply): array
    {
        $rows = [];

        foreach ($reply->xpath('//*[local-name()="vlans"]/*[local-name()="vlan"]') ?: [] as $node) {
            $name = $this->name($node);
            $guvenlik = $node->xpath('./*[local-name()="forwarding-options"]/*[local-name()="dhcp-security"]')[0] ?? null;
            $anahtar = $node->xpath('./*[local-name()="switch-options"]')[0] ?? null;

            if ($name === null || ($guvenlik === null && $anahtar === null)) {
                continue;
            }

            $rows[] = [
                'vlan_name' => $name,
                'examine_dhcp' => $guvenlik !== null && ! $this->has($guvenlik, 'no-dhcp-snooping'),
                'arp_inspection' => $guvenlik !== null && $this->has($guvenlik, 'arp-inspection'),
                'ip_source_guard' => $guvenlik !== null && $this->has($guvenlik, 'ip-source-guard'),
                'mac_move_limit' => $anahtar === null ? null : $this->digits($anahtar, 'mac-move-limit'),

                // ELS'te mac-move-limit'in eylem siblingi yoktur.
                'mac_move_action' => null,
                'residual_xml' => [],
            ];
        }

        return $rows;
    }

    /**
     * ELS'te port güvenliği "switch-options interface <ad>" altındadır.
     *
     * dhcp-trusted ve allowed-mac orada YOKTUR (ikisi de VLAN kapsamında);
     * okunmazlar ve panel onları bu cihazda zaten kapatır.
     *
     * @return list<array<string, mixed>>
     */
    private function elsInterfaces(SimpleXMLElement $reply): array
    {
        $rows = [];

        // switch-options ALTINDAKİ arayüzler: switch geneli MAC sınırı.
        foreach ($reply->xpath('/*/*[local-name()="configuration"]/*[local-name()="switch-options"]'
            .'/*[local-name()="interface"]') ?: [] as $node) {
            $name = $this->name($node);
            $limit = $node->xpath('./*[local-name()="interface-mac-limit"]')[0] ?? null;

            if ($name === null) {
                continue;
            }

            $rows[$name] = [
                'interface_name' => $name,
                'vlan_name' => null,
                'dhcp_trusted' => null,
                'mac_limit' => $limit === null ? null : $this->digits($limit, 'limit'),

                /*
                 * ELS eylemi "packet-action" diye yazar; ELS öncesinde
                 * "action". Tanınmayan değer null bırakılır — uydurulmaz.
                 */
                'mac_limit_action' => $limit === null ? null : SecureAccessAction::tryFrom(
                    trim((string) ($limit->xpath('./*[local-name()="packet-action"]')[0] ?? ''))
                )?->value,
                'allowed_macs' => [],
                'residual_xml' => [],
            ];
        }

        return array_values($this->elsVlanScoped($reply, $rows));
    }

    /**
     * ELS'te VLAN KAPSAMINDA yaşayan iki alan geri okunur.
     *
     *   güven      → vlans <v> forwarding-options dhcp-security group <g>
     *                  { overrides trusted; interface <if>; }
     *   izinli MAC → vlans <v> switch-options interface <if> static-mac
     *
     * Yalnızca VLAN kapsamında görünen bir arayüz için satır BURADA açılır:
     * switch-options altında MAC sınırı olmayabilir ama güven satırı vardır
     * ve panelde görünmesi gerekir.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function elsVlanScoped(SimpleXMLElement $reply, array $rows): array
    {
        foreach ($reply->xpath('//*[local-name()="vlans"]/*[local-name()="vlan"]') ?: [] as $vlan) {
            $vlanAdi = $this->name($vlan);

            if ($vlanAdi === null) {
                continue;
            }

            $yol = './*[local-name()="forwarding-options"]/*[local-name()="dhcp-security"]/*[local-name()="group"]';

            foreach ($vlan->xpath($yol) ?: [] as $grup) {
                $guvenli = ($grup->xpath('./*[local-name()="overrides"]/*[local-name()="trusted"]') ?: []) !== [];

                foreach ($grup->xpath('./*[local-name()="interface"]') ?: [] as $arayuz) {
                    $ad = $this->name($arayuz);

                    if ($ad === null) {
                        continue;
                    }

                    $rows[$ad] ??= $this->elsBosSatir($ad);
                    $rows[$ad]['vlan_name'] = $vlanAdi;
                    $rows[$ad]['dhcp_trusted'] = $guvenli ? true : $rows[$ad]['dhcp_trusted'];
                }
            }

            $macYolu = './*[local-name()="switch-options"]/*[local-name()="interface"]';

            foreach ($vlan->xpath($macYolu) ?: [] as $arayuz) {
                $ad = $this->name($arayuz);
                $macler = [];

                foreach ($arayuz->xpath('./*[local-name()="static-mac"]/*[local-name()="name"]') ?: [] as $mac) {
                    $macler[] = trim((string) $mac);
                }

                if ($ad === null || $macler === []) {
                    continue;
                }

                $rows[$ad] ??= $this->elsBosSatir($ad);
                $rows[$ad]['vlan_name'] = $vlanAdi;
                $rows[$ad]['allowed_macs'] = $macler;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function elsBosSatir(string $interfaceName): array
    {
        return [
            'interface_name' => $interfaceName,
            'vlan_name' => null,
            'dhcp_trusted' => null,
            'mac_limit' => null,
            'mac_limit_action' => null,
            'allowed_macs' => [],
            'residual_xml' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function vlan(SimpleXMLElement $node): ?array
    {
        $name = $this->name($node);

        if ($name === null) {
            return null;
        }

        $limit = $this->limit($node, 'mac-move-limit');

        return [
            'vlan_name' => $name,
            'examine_dhcp' => $this->has($node, 'examine-dhcp'),
            'arp_inspection' => $this->has($node, 'arp-inspection'),
            'ip_source_guard' => $this->has($node, 'ip-source-guard'),
            'mac_move_limit' => $limit['limit'] ?? null,
            'mac_move_action' => $limit['action'] ?? null,
            'residual_xml' => ResidualXml::collect($node, [
                ...self::VLAN_FLAGS,
                ...($limit['known'] ?? []),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interface(SimpleXMLElement $node): ?array
    {
        $name = $this->name($node);

        if ($name === null) {
            return null;
        }

        $limit = $this->limit($node, 'mac-limit');
        $macs = $this->allowedMacs($node);

        return [
            'interface_name' => $name,

            // ELS öncesinde ayarlar switch genelindedir; VLAN sorulmaz.
            'vlan_name' => null,
            'dhcp_trusted' => $this->trust($node),
            'mac_limit' => $limit['limit'] ?? null,
            'mac_limit_action' => $limit['action'] ?? null,
            'allowed_macs' => $macs,
            'residual_xml' => ResidualXml::collect($node, [
                ...self::INTERFACE_FLAGS,
                ...($limit['known'] ?? []),
                // Hiç yoksa da "bilinen" sayılır: toplanacak bir şey yoktur.
                ...($macs !== null || ! $this->has($node, 'allowed-mac') ? ['allowed-mac'] : []),
            ]),
        ];
    }

    /**
     * Üç durum ayrıdır ve tek boolean'a sıkışmaz: satır yok (cihazın
     * varsayılanı), dhcp-trusted, no-dhcp-trusted.
     */
    private function trust(SimpleXMLElement $node): ?bool
    {
        return match (true) {
            $this->has($node, 'dhcp-trusted') => true,
            $this->has($node, 'no-dhcp-trusted') => false,
            default => null,
        };
    }

    /**
     * "<sınır> <n> action <...>" satırını çözer.
     *
     * İki biçim de tanınır; hangisinin geldiği cihazda ÖLÇÜLMEDİ:
     *
     *   1) <mac-limit><limit>4</limit><action>drop</action></mac-limit>
     *   2) <mac-limit>4</mac-limit> ve kardeş <action>drop</action>
     *
     * Çözülemeyen (ya da eylemi tanınmayan) biçimde null döner: satır o zaman
     * ham XML olarak korunur, panel onu yönetmez ama SİLMEZ de.
     *
     * @return array{limit: ?int, action: ?string, known: list<string>}|null
     */
    private function limit(SimpleXMLElement $node, string $element): ?array
    {
        $found = $node->xpath('./*[local-name()="'.$element.'"]')[0] ?? null;

        if ($found === null) {
            return ['limit' => null, 'action' => null, 'known' => [$element]];
        }

        $inner = $this->digits($found, 'limit');

        if ($inner !== null) {
            $action = $this->action($found);

            return $action === false
                ? null
                : ['limit' => $inner, 'action' => $action, 'known' => [$element]];
        }

        $value = trim((string) $found);

        if ($value !== '' && ctype_digit($value)) {
            $action = $this->action($node);

            return $action === false
                ? null
                : ['limit' => (int) $value, 'action' => $action, 'known' => [$element, 'action']];
        }

        return null;
    }

    /**
     * Eylem: yoksa null, tanınmayan bir değer varsa false.
     *
     * false "bu düğümü hiç anlamadım" demektir; çağıran o zaman satırı ham
     * XML olarak korumaya bırakır. Tanımadığımız bir eylemi sessizce
     * düşürmek, cihazdaki koruma davranışını değiştirmek olurdu.
     */
    private function action(SimpleXMLElement $node): string|false|null
    {
        $value = trim((string) ($node->xpath('./*[local-name()="action"]')[0] ?? ''));

        if ($value === '') {
            return null;
        }

        return SecureAccessAction::tryFrom($value)?->value ?? false;
    }

    /**
     * allowed-mac [ ... ] leaf-list'i.
     *
     * Junos köşeli parantezli listeleri yinelenen elemanlarla yazar; bazı
     * şemalarda değerler bir sarmalayıcının altındadır. İkisi de okunur.
     * Hiçbiri çözülemezse null döner ve satır ham XML olarak korunur.
     *
     * @return list<string>|null
     */
    private function allowedMacs(SimpleXMLElement $node): ?array
    {
        $found = $node->xpath('./*[local-name()="allowed-mac"]') ?: [];

        if ($found === []) {
            return null;
        }

        $macs = [];

        foreach ($found as $element) {
            $children = $element->children();

            if (count($children) > 0) {
                foreach ($children as $child) {
                    $macs[] = trim((string) $child);
                }

                continue;
            }

            $macs[] = trim((string) $element);
        }

        $macs = array_values(array_filter($macs, static fn (string $mac): bool => $mac !== ''));

        return $macs === [] ? null : $macs;
    }

    private function name(SimpleXMLElement $node): ?string
    {
        $name = trim((string) ($node->xpath('./*[local-name()="name"]')[0] ?? ''));

        return $name === '' ? null : $name;
    }

    private function has(SimpleXMLElement $node, string $element): bool
    {
        return ($node->xpath('./*[local-name()="'.$element.'"]') ?: []) !== [];
    }

    private function digits(SimpleXMLElement $node, string $element): ?int
    {
        $value = trim((string) ($node->xpath('./*[local-name()="'.$element.'"]')[0] ?? ''));

        return $value === '' || ! ctype_digit($value) ? null : (int) $value;
    }
}
