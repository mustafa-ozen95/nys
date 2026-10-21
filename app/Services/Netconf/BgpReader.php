<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\BgpNlri;
use App\Enums\BgpState;
use App\Enums\IpFamily;
use App\Support\Netconf\XmlNode;
use SimpleXMLElement;

/**
 * BGP yapılandırmasını ve canlı oturum durumunu NETCONF ile okur.
 *
 * İki ayrı kaynak vardır ve karıştırılmaz:
 *  - yapılandırma (get-configuration): grup, komşu, politika, prefix sınırı
 *  - durum (get-bgp-summary-information): oturum hâli ve sayaçlar
 *
 * Ayrıştırma tahminle değil, cihazın verdiği kanonik XML'e göre yazıldı;
 * örnekleri tests/Fixtures/netconf/bgp-*.xml altında duruyor. Junos aynı
 * ayarı birden çok biçimde yazabildiği için (bare <multihop/> ile
 * <multihop><ttl>), her varyant cihazda denenerek doğrulandı.
 *
 * Açık bir oturum alır; bağlantıyı kendisi açmaz (bkz. .ai/rules/netconf.md).
 */
class BgpReader
{
    /**
     * Cihazın kendi AS numarası. BGP yapılandırılmamışsa null.
     *
     * @throws NetconfException
     */
    public function autonomousSystem(NetconfSession $session): ?string
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><routing-options><autonomous-system/></routing-options></configuration>'
            .'</get-configuration>'
        );

        $value = trim((string) ($reply->xpath('//*[local-name()="autonomous-system"]/*[local-name()="as-number"]')[0] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Yapılandırmadaki peer grupları ve komşuları.
     *
     * @return list<array{
     *     name: string,
     *     type: BgpGroupType,
     *     peer_asn: ?string,
     *     local_asn: ?string,
     *     local_address: ?string,
     *     authentication_key: ?string,
     *     hold_time: ?int,
     *     cluster: ?string,
     *     graceful_restart: BgpGracefulRestart,
     *     remove_private: bool,
     *     as_override: bool,
     *     advertise_inactive: bool,
     *     multipath: bool,
     *     damping: bool,
     *     out_delay: ?int,
     *     bfd_minimum_interval: ?int,
     *     allow_prefixes: list<string>,
     *     multihop: bool,
     *     multihop_ttl: ?int,
     *     log_updown: bool,
     *     passive: bool,
     *     import_policies: list<string>,
     *     export_policies: list<string>,
     *     description: ?string,
     *     families: list<array{family: IpFamily, nlri: BgpNlri, prefix_limit_maximum: ?int, teardown: bool, teardown_threshold: ?int, idle_timeout_forever: bool, idle_timeout_minutes: ?int}>,
     *     neighbors: list<array{address: string, description: ?string, peer_asn: ?string, local_address: ?string, authentication_key: ?string, hold_time: ?int, passive: bool, multihop: bool, multihop_ttl: ?int, import_policies: list<string>, export_policies: list<string>}>
     * }>
     *
     * @throws NetconfException
     */
    public function groups(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><protocols><bgp/></protocols></configuration>'
            .'</get-configuration>'
        );

        $groups = [];

        foreach ($reply->xpath('//*[local-name()="bgp"]/*[local-name()="group"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                /*
                 * Cihazda "deactivate" edilmiş nesne: yapılandırma duruyor ama
                 * ÇALIŞMIYOR. İşaret öznitelikte gelir. Okunmazsa panel devre dışı
                 * bir oturumu etkin gösterir.
                 */
                'is_inactive' => XmlNode::isInactive($node),
                // Junos'ta tip zorunludur ama eski config'lerde eksik olabilir;
                // varsayılanı external'dır.
                'type' => BgpGroupType::tryFrom((string) $this->text($node, 'type')) ?? BgpGroupType::External,
                'peer_asn' => $this->text($node, 'peer-as'),
                'local_asn' => $this->text($node, 'local-as/as-number'),
                'local_address' => $this->text($node, 'local-address'),

                /*
                 * Anahtar cihazda "$9$..." biçiminde durur. Olduğu gibi
                 * saklanır ve olduğu gibi geri yazılır: Junos kendi
                 * şifrelediği değeri kabul eder, yeniden şifrelemez.
                 */
                'authentication_key' => $this->text($node, 'authentication-key'),
                'hold_time' => $this->integer($node, 'hold-time'),
                'cluster' => $this->text($node, 'cluster'),
                'graceful_restart' => $this->gracefulRestart($node),
                'remove_private' => $this->has($node, 'remove-private'),
                'as_override' => $this->has($node, 'as-override'),
                'advertise_inactive' => $this->has($node, 'advertise-inactive'),
                'multipath' => $this->has($node, 'multipath'),
                'damping' => $this->has($node, 'damping'),
                'out_delay' => $this->integer($node, 'out-delay'),
                'bfd_minimum_interval' => $this->integer($node, 'bfd-liveness-detection/minimum-interval'),
                'allow_prefixes' => $this->texts($node, 'allow'),
                'multihop' => $this->has($node, 'multihop'),
                'multihop_ttl' => $this->integer($node, 'multihop/ttl'),
                'log_updown' => $this->has($node, 'log-updown'),
                'passive' => $this->has($node, 'passive'),
                'import_policies' => $this->texts($node, 'import'),
                'export_policies' => $this->texts($node, 'export'),
                'description' => $this->text($node, 'description'),
                'families' => $this->families($node),
                'neighbors' => $this->neighbors($node),
            ];
        }

        return $groups;
    }

    /**
     * Canlı oturum durumu, komşu adresine göre.
     *
     * @return array<string, array{state: BgpState, active_prefixes: ?int, received_prefixes: ?int, accepted_prefixes: ?int, flap_count: ?int, state_changed_seconds: ?int}>
     *
     * @throws NetconfException
     */
    public function neighborStates(NetconfSession $session): array
    {
        $reply = $session->rpc('<get-bgp-summary-information/>');

        $states = [];

        foreach ($reply->xpath('//*[local-name()="bgp-peer"]') ?: [] as $peer) {
            $address = $this->text($peer, 'peer-address');

            if ($address === null) {
                continue;
            }

            // Cihaz adresi "198.18.8.37+179" biçiminde de verebilir; port atılır.
            $address = explode('+', $address)[0];

            $states[$address] = [
                'state' => $this->state($this->text($peer, 'peer-state')),
                'active_prefixes' => $this->integer($peer, 'bgp-rib/active-prefix-count'),
                'received_prefixes' => $this->integer($peer, 'bgp-rib/received-prefix-count'),
                'accepted_prefixes' => $this->integer($peer, 'bgp-rib/accepted-prefix-count'),
                'flap_count' => $this->integer($peer, 'flap-count'),
                'state_changed_seconds' => $this->elapsedSeconds($peer),
            ];
        }

        return $states;
    }

    /**
     * @return list<array{family: IpFamily, nlri: BgpNlri, prefix_limit_maximum: ?int, teardown: bool, teardown_threshold: ?int, idle_timeout_forever: bool, idle_timeout_minutes: ?int}>
     */
    private function families(SimpleXMLElement $group): array
    {
        $families = [];

        foreach (IpFamily::cases() as $family) {
            $nodes = $group->xpath('./*[local-name()="family"]/*[local-name()="'.$family->value.'"]');

            if ($nodes === false || $nodes === []) {
                continue;
            }

            $node = $nodes[0];

            /*
             * Sınır alt eleman altındadır ve adı değişkendir: "any", "unicast"
             * ya da "multicast". Hangisi olduğu prefix sınırı açısından fark
             * etmediği için ada bakmadan aranır.
             */
            $limit = $node->xpath('.//*[local-name()="prefix-limit"]')[0] ?? null;

            $families[] = [
                'family' => $family,
                'nlri' => $this->nlri($node),
                'prefix_limit_maximum' => $limit === null ? null : $this->integer($limit, 'maximum'),
                'teardown' => $limit !== null && $this->has($limit, 'teardown'),
                'teardown_threshold' => $limit === null ? null : $this->integer($limit, 'teardown/limit-threshold'),
                /*
                 * Junos "forever"ı BOŞ bir <idle-timeout/> elemanı olarak
                 * yazar; dakikalı hâlinde altında <timeout> bulunur. İkisi
                 * ancak alt eleman kontrolüyle ayrılır.
                 */
                'idle_timeout_forever' => $limit !== null
                    && $this->has($limit, 'teardown/idle-timeout')
                    && $this->integer($limit, 'teardown/idle-timeout/timeout') === null,
                'idle_timeout_minutes' => $limit === null ? null : $this->integer($limit, 'teardown/idle-timeout/timeout'),
            ];
        }

        return $families;
    }

    /**
     * Ailenin altındaki NLRI alt elemanı. Cihazda hangi biçimde durduğu
     * saklanır; yoksa panelden düzenlenen grup sessizce başka bir satıra
     * dönüşürdü.
     */
    private function nlri(SimpleXMLElement $family): BgpNlri
    {
        foreach ($family->children() as $child) {
            $nlri = BgpNlri::tryFrom($child->getName());

            if ($nlri !== null) {
                return $nlri;
            }
        }

        return BgpNlri::Any;
    }

    /**
     * Grubun ayarlarının hemen hepsi komşu seviyesinde de yazılabilir ve
     * orada yazılmışsa grubunkini ezer; hepsi okunur, yoksa güncelleme
     * döngüsü cihazdaki değeri sessizce silerdi.
     *
     * @return list<array{address: string, description: ?string, peer_asn: ?string, local_address: ?string, authentication_key: ?string, hold_time: ?int, passive: bool, multihop: bool, multihop_ttl: ?int, import_policies: list<string>, export_policies: list<string>}>
     */
    private function neighbors(SimpleXMLElement $group): array
    {
        $neighbors = [];

        foreach ($group->xpath('./*[local-name()="neighbor"]') ?: [] as $node) {
            $address = $this->text($node, 'name');

            if ($address === null) {
                continue;
            }

            $neighbors[] = [
                'address' => $address,
                'is_inactive' => XmlNode::isInactive($node),
                'description' => $this->text($node, 'description'),
                'peer_asn' => $this->text($node, 'peer-as'),
                'local_address' => $this->text($node, 'local-address'),
                'authentication_key' => $this->text($node, 'authentication-key'),
                'hold_time' => $this->integer($node, 'hold-time'),
                'passive' => $this->has($node, 'passive'),
                'multihop' => $this->has($node, 'multihop'),
                'multihop_ttl' => $this->integer($node, 'multihop/ttl'),
                'import_policies' => $this->texts($node, 'import'),
                'export_policies' => $this->texts($node, 'export'),
            ];
        }

        return $neighbors;
    }

    /**
     * graceful-restart üç durumludur ve ikisi aynı şey değildir: satır hiç
     * yoksa cihazın genel ayarı geçerlidir, "disable" alt elemanı varsa
     * grup için kapatılmıştır.
     */
    private function gracefulRestart(SimpleXMLElement $group): BgpGracefulRestart
    {
        if (! $this->has($group, 'graceful-restart')) {
            return BgpGracefulRestart::Inherit;
        }

        return $this->has($group, 'graceful-restart/disable')
            ? BgpGracefulRestart::Disabled
            : BgpGracefulRestart::Enabled;
    }

    /**
     * Cihaz durumu "Established", "Idle", "OpenConfirm" gibi yazar; enum
     * değerleri snake_case olduğu için doğrudan tryFrom çalışmaz.
     */
    private function state(?string $value): BgpState
    {
        return match (strtolower((string) $value)) {
            'idle' => BgpState::Idle,
            'connect' => BgpState::Connect,
            'active' => BgpState::Active,
            'opensent' => BgpState::OpenSent,
            'openconfirm' => BgpState::OpenConfirm,
            'established' => BgpState::Established,
            default => BgpState::Unknown,
        };
    }

    /**
     * Oturumun son durum değişikliğinden bu yana geçen süre.
     *
     * Metin hâli ("1:29", "3w2d") ayrıştırılmaz: cihaz aynı bilgiyi
     * junos:seconds özniteliğinde saniye olarak da veriyor.
     */
    private function elapsedSeconds(SimpleXMLElement $peer): ?int
    {
        $nodes = $peer->xpath('./*[local-name()="elapsed-time"]');

        if ($nodes === false || $nodes === []) {
            return null;
        }

        foreach ($nodes[0]->attributes('junos', true) as $name => $value) {
            if ($name === 'seconds') {
                return (int) (string) $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function texts(SimpleXMLElement $node, string $path): array
    {
        $values = [];

        foreach ($node->xpath($this->xpath($path)) ?: [] as $found) {
            $value = trim((string) $found);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function text(SimpleXMLElement $node, string $path): ?string
    {
        $found = $node->xpath($this->xpath($path))[0] ?? null;

        if ($found === null) {
            return null;
        }

        $value = trim((string) $found);

        return $value === '' ? null : $value;
    }

    private function integer(SimpleXMLElement $node, string $path): ?int
    {
        $value = $this->text($node, $path);

        return $value === null ? null : (int) $value;
    }

    /**
     * Elemanın VARLIĞI ayarın açık olduğunu söyler. Junos bayrakları boş
     * eleman olarak yazar (<log-updown/>), o yüzden metnine bakılamaz.
     */
    private function has(SimpleXMLElement $node, string $path): bool
    {
        $found = $node->xpath($this->xpath($path));

        return $found !== false && $found !== [];
    }

    /**
     * "teardown/limit-threshold" -> ad alanından bağımsız XPath.
     *
     * Junos yanıtları ad alanlıdır ve sürümle değişir (junos-routing gibi);
     * local-name() ile eşleşmek sürüme bağımlılığı ortadan kaldırır.
     */
    private function xpath(string $path): string
    {
        $parts = array_map(
            static fn (string $part): string => '*[local-name()="'.$part.'"]',
            explode('/', $path),
        );

        return './'.implode('/', $parts);
    }
}
