<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Support\Netconf\PolicyMatch;
use App\Support\Netconf\PolicyThen;
use App\Support\Netconf\ResidualXml;
use App\Support\Netconf\XmlNode;
use SimpleXMLElement;

/**
 * Cihazın policy-statement'larını NETCONF ile okur.
 *
 * Junos politika dili panelin modellediğinden geniştir ve hep öyle kalacak.
 * Temsil edilemeyen bir yapı yüzünden politikayı SALT OKUNUR yapmak,
 * kullanıcının ona hiç dokunamaması demektir. Bu yüzden iki katman birden
 * çalışır:
 *
 *   1. Modellenen alan kümesi Junos'un pratik yüzeyine kadar geniş:
 *      prefix-list, prefix-list-filter, route-filter, protocol, as-path,
 *      community, neighbor, next-hop, interface, rib, route-type, tag,
 *      family, level, area; then tarafında local-preference, metric,
 *      metric2, preference, community add/set/delete, as-path-prepend,
 *      as-path-expand, next-hop, origin, tag, load-balance, install-nexthop,
 *      forwarding-class, damping ve sonlandırıcı eylemler.
 *   2. Geri kalan uzun kuyruk HAM XML olarak saklanır (residual_xml) ve geri
 *      yazılırken aynen basılır.
 *
 * Bu yüzden is_manageable HEP true'dur.
 *
 * Politikanın KENDİ from/then'i de okunur: terimlerden sonra gelen "then
 * reject;" politikanın VARSAYILAN EYLEMİDİR ve bütün BGP süzgeçlerinin
 * standart biçimidir. Panel onu okumazsa güncellemede siler ve politika
 * "gerisini reddet"ten "gerisini de al"a döner (test1'de commit check ile
 * ölçüldü).
 *
 * Açık bir oturum alır; bağlantıyı kendisi açmaz (bkz. .ai/rules/netconf.md).
 */
class PolicyStatementReader
{
    /**
     * Politika düğümünün panelin ÜRETTİĞİ çocukları. Başkası ham XML olarak
     * saklanır.
     *
     * @var list<string>
     */
    private const STATEMENT_CHILDREN = ['name', 'term', 'from', 'then'];

    /**
     * Terim düğümünün panelin ürettiği çocukları.
     *
     * @var list<string>
     */
    private const TERM_CHILDREN = ['name', 'from', 'then'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws NetconfException
     */
    public function statements(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><policy-options/></configuration>'
            .'</get-configuration>'
        );

        $statements = [];

        foreach ($reply->xpath('//*[local-name()="policy-statement"]') ?: [] as $node) {
            $name = XmlNode::text($node, 'name');

            if ($name === null) {
                continue;
            }

            $terms = [];
            $position = 0;

            foreach (XmlNode::children($node, 'term') as $termNode) {
                $term = $this->term($termNode, $position);

                if ($term !== null) {
                    $terms[] = $term;
                    $position++;
                }
            }

            $statements[] = [
                'name' => $name,
                'is_inactive' => XmlNode::isInactive($node),

                // Politikanın KENDİ koşulu ve varsayılan eylemi.
                'default_match' => PolicyMatch::read(XmlNode::first($node, 'from')),
                'default_then' => PolicyThen::read(XmlNode::first($node, 'then')),

                'residual_xml' => ResidualXml::collect($node, self::STATEMENT_CHILDREN),

                // Modellenmeyen satırlar korunduğu için hiçbir politika salt
                // okunur kalmak zorunda değil.
                'is_manageable' => true,
                'terms' => $terms,
            ];
        }

        return $statements;
    }

    /**
     * Community tanımları.
     *
     * Ayrı bir RPC'dir ama aynı oturumda çalışır ve ~0.1 saniye sürer;
     * pahalı olan oturumu kurmaktır (bkz. .ai/rules/netconf.md).
     *
     * @return list<array{name: string, members: list<string>, invert_match: bool, residual_xml: list<string>}>
     *
     * @throws NetconfException
     */
    public function communities(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><policy-options><community/></policy-options></configuration>'
            .'</get-configuration>'
        );

        $communities = [];

        foreach ($reply->xpath('//*[local-name()="community"]') ?: [] as $node) {
            $name = XmlNode::text($node, 'name');

            if ($name === null) {
                continue;
            }

            $communities[] = [
                'name' => $name,
                'members' => XmlNode::texts($node, 'members'),

                // invert-match tanımın anlamını tersine çevirir; okunmazsa
                // güncellemede sessizce silinirdi.
                'invert_match' => XmlNode::has($node, 'invert-match'),
                'residual_xml' => ResidualXml::collect($node, ['name', 'members', 'invert-match']),
            ];
        }

        return $communities;
    }

    /**
     * as-path tanımları: policy-options as-path <ad> { path "<ifade>"; }
     *
     * Politika terimi "from as-path <ad>" ile bunlara atıfta bulunur;
     * tanımsız ada atıf commit'i düşürür.
     *
     * @return list<array{name: string, path: string, residual_xml: list<string>}>
     *
     * @throws NetconfException
     */
    public function asPaths(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><policy-options><as-path/></policy-options></configuration>'
            .'</get-configuration>'
        );

        $paths = [];

        foreach ($reply->xpath('//*[local-name()="as-path"]') ?: [] as $node) {
            $name = XmlNode::text($node, 'name');
            $path = XmlNode::text($node, 'path');

            if ($name === null || $path === null) {
                continue;
            }

            $paths[] = [
                'name' => $name,
                'path' => $path,
                'residual_xml' => ResidualXml::collect($node, ['name', 'path']),
            ];
        }

        return $paths;
    }

    /**
     * Tek bir terim, bütün koşulları ve eylemleriyle.
     *
     * prefix-list-filter kendi sütunlarına ayrılır (match_prefix_lists +
     * match_type): ekran, bağımlılık denetimi ve eski çağrı biçimi onları
     * kullanıyor. Filtrelerin eşleşme türü BİRBİRİNDEN FARKLIYSA tek sütuna
     * sığmaz; o durumda hepsi match_conditions içinde tam hâliyle kalır.
     *
     * @return array<string, mixed>|null
     */
    private function term(SimpleXMLElement $node, int $position): ?array
    {
        $name = XmlNode::text($node, 'name');

        if ($name === null) {
            return null;
        }

        $match = PolicyMatch::read(XmlNode::first($node, 'from'));
        $then = PolicyThen::read(XmlNode::first($node, 'then'));

        [$lists, $matchType, $match] = $this->splitPrefixListFilters($match);

        $action = PolicyAction::tryFrom((string) $then['action']);
        $then['action'] = null;

        return [
            'name' => $name,
            'position' => $position,
            'is_inactive' => XmlNode::isInactive($node),
            'match_prefix_lists' => $lists,
            'match_type' => $matchType,
            'match_conditions' => $match,
            'action' => $action,
            'community_delete' => $this->communityDelete($then),
            'then_actions' => $then,
            'residual_xml' => ResidualXml::collect($node, self::TERM_CHILDREN),
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array{0: list<string>, 1: ?PrefixMatchType, 2: array<string, mixed>}
     */
    private function splitPrefixListFilters(array $match): array
    {
        $filters = $match['prefix_list_filters'] ?? [];
        $turler = array_unique(array_column($filters, 'match_type'));

        if ($filters === [] || count($turler) !== 1) {
            return [[], null, $match];
        }

        $match['prefix_list_filters'] = [];

        return [
            array_values(array_column($filters, 'list')),
            PrefixMatchType::tryFrom((string) reset($turler)),
            $match,
        ];
    }

    /**
     * Ekranın ve bağımlılık denetiminin kullandığı kısayol: terimin sildiği
     * ilk community. Eylemlerin tamamı then_actions içindedir.
     *
     * @param  array<string, mixed>  $then
     */
    private function communityDelete(array $then): ?string
    {
        foreach ($then['communities'] ?? [] as $community) {
            if (($community['operation'] ?? null) === 'delete') {
                return (string) $community['name'];
            }
        }

        return null;
    }
}
