<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\PolicyAction;
use App\Enums\PrefixMatchType;
use App\Models\NetworkSwitch;
use App\Models\SwitchAsPath;
use App\Models\SwitchCommunity;
use App\Models\SwitchPolicyStatement;
use App\Support\Netconf\PolicyMatch;
use App\Support\Netconf\PolicyThen;
use App\Support\Netconf\ResidualXml;
use App\Support\Netconf\XmlNode;

/**
 * Policy-statement'ları cihaza yazar.
 *
 * Terim SIRASI anlamlıdır: ilk eşleşen terim kazanır ve sonuncusu genellikle
 * "reject"tir. Junos "merge" yeni terimi mevcutların ARKASINA eklediği için
 * sıra merge ile yönetilemez — "her şeyi kabul et" terimi "her şeyi reddet"in
 * arkasına düşer ve politika sessizce etkisizleşir.
 *
 * Bu yüzden güncelleme, politikayı silip aynı yüklemede yeniden yazar. İki
 * belge TEK commit'te uygulanır; cihaz aradaki ara durumu hiç görmez. Bunu
 * yapabilmemizin sebebi politikanın alt nesnesi olmamasıdır — BGP grubunda
 * aynı yol izlenemez, çünkü grubu silmek komşularını da silerdi.
 *
 * SİL + YENİDEN YAZ kaybettirmez: panelin modellemediği satırlar okurken ham
 * XML olarak saklanır (residual_xml) ve gövde ResidualXml::emit ile
 * TAMAMLANIR — hem politikanın kendisi, hem her terim, hem from ve then için.
 * Politikanın kendi "then reject;" satırı da modellenir ve geri yazılır.
 */
class PolicyStatementConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private PolicyStatementSynchronizer $policies,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>  $defaults  Politikanın kendi from/then'i
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(NetworkSwitch $switch, string $name, array $terms, array $defaults = []): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($this->statementElement($name, $terms, $defaults))],
            "politika eklendi: {$name}",
        );
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>  $defaults
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function update(
        NetworkSwitch $switch,
        SwitchPolicyStatement $statement,
        array $terms,
        array $defaults = [],
    ): array {
        $documents = [
            $this->wrap($this->deleteElement('policy-statement', $statement->name)),
            $this->wrap($this->statementElement($statement->name, $terms, $defaults)),
        ];

        return $this->apply($switch, $documents, "politika degistirildi: {$statement->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchPolicyStatement $statement): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($this->deleteElement('policy-statement', $statement->name))],
            "politika silindi: {$statement->name}",
        );
    }

    /**
     * Community tanımı. Politika terimi bir community'ye atıfta bulunuyorsa
     * o community ÖNCE tanımlı olmalıdır; cihaz aksi hâlde commit'i düşürür:
     * "topluluk-a community referenced (in term clear) but not defined".
     *
     * @param  list<string>  $members
     * @param  list<string>|null  $residual
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createCommunity(
        NetworkSwitch $switch,
        string $name,
        array $members,
        bool $invertMatch = false,
        ?array $residual = null,
    ): array {
        return $this->apply(
            $switch,
            [$this->wrap($this->communityElement($name, $members, $invertMatch, $residual))],
            "community eklendi: {$name}",
        );
    }

    /**
     * Üye listesi değişiyorsa community silinip yeniden yazılır: merge
     * yalnızca EKLER, çıkarılan üye olduğu yerde kalırdı.
     *
     * @param  list<string>  $members
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updateCommunity(
        NetworkSwitch $switch,
        SwitchCommunity $community,
        array $members,
        ?bool $invertMatch = null,
    ): array {
        $documents = [
            $this->wrap($this->deleteElement('community', $community->name)),
            $this->wrap($this->communityElement(
                $community->name,
                $members,
                $invertMatch ?? (bool) $community->invert_match,
                $community->residual_xml,
            )),
        ];

        return $this->apply($switch, $documents, "community degistirildi: {$community->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteCommunity(NetworkSwitch $switch, SwitchCommunity $community): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($this->deleteElement('community', $community->name))],
            "community silindi: {$community->name}",
        );
    }

    /**
     * as-path tanımı: policy-options as-path <ad> { path "<ifade>"; }
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createAsPath(NetworkSwitch $switch, string $name, string $path): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($this->asPathElement($name, $path, null))],
            "as-path eklendi: {$name}",
        );
    }

    /**
     * İfade değiştiğinde tanım silinip yeniden yazılır; merge yalnızca ekler.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updateAsPath(NetworkSwitch $switch, SwitchAsPath $asPath, string $path): array
    {
        $documents = [
            $this->wrap($this->deleteElement('as-path', $asPath->name)),
            $this->wrap($this->asPathElement($asPath->name, $path, $asPath->residual_xml)),
        ];

        return $this->apply($switch, $documents, "as-path degistirildi: {$asPath->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteAsPath(NetworkSwitch $switch, SwitchAsPath $asPath): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($this->deleteElement('as-path', $asPath->name))],
            "as-path silindi: {$asPath->name}",
        );
    }

    /**
     * @param  list<string>  $members
     * @param  list<string>|null  $residual
     */
    private function communityElement(string $name, array $members, bool $invertMatch, ?array $residual): string
    {
        $body = '<name>'.XmlNode::escape($name).'</name>';
        $body .= XmlNode::leaves('members', $members);

        if ($invertMatch) {
            $body .= '<invert-match/>';
        }

        $body .= ResidualXml::emit($residual);

        return XmlNode::wrap('community', $body);
    }

    /**
     * @param  list<string>|null  $residual
     */
    private function asPathElement(string $name, string $path, ?array $residual): string
    {
        $body = '<name>'.XmlNode::escape($name).'</name>'
            .'<path>'.XmlNode::escape($path).'</path>'
            .ResidualXml::emit($residual);

        return XmlNode::wrap('as-path', $body);
    }

    /**
     * Politika gövdesi: ad, terimler, politikanın KENDİ from/then'i ve
     * panelin tanımadığı ham satırlar.
     *
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>  $defaults
     */
    private function statementElement(string $name, array $terms, array $defaults): string
    {
        $body = '<name>'.XmlNode::escape($name).'</name>';

        foreach ($terms as $term) {
            $body .= $this->termElement($term);
        }

        /*
         * Politikanın kendi koşulu ve VARSAYILAN EYLEMİ terimlerden sonra
         * gelir; Junos'ta sıra budur ve "then reject;" bütün BGP
         * süzgeçlerinin standart kapanışıdır.
         */
        $body .= PolicyMatch::emit($defaults['match'] ?? null);
        $body .= PolicyThen::emit($defaults['then'] ?? null);

        $body .= ResidualXml::emit($defaults['residual_xml'] ?? null);

        return XmlNode::wrap('policy-statement', $body);
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function termElement(array $term): string
    {
        $body = '<name>'.XmlNode::escape((string) $term['name']).'</name>';
        $body .= $this->fromElement($term);
        $body .= $this->thenElement($term);
        $body .= ResidualXml::emit($term['residual_xml'] ?? null);

        return XmlNode::wrap('term', $body);
    }

    /**
     * Koşulsuz terim HER ŞEYE uyar; "from" düğümü hiç yazılmaz.
     *
     * prefix-list-filter ayrı alanlardan gelir (match_prefix_lists +
     * match_type); geri kalan koşullar match_conditions içindedir.
     *
     * @param  array<string, mixed>  $term
     */
    private function fromElement(array $term): string
    {
        $match = (array) ($term['match_conditions'] ?? PolicyMatch::blank());
        $lists = array_filter((array) ($term['match_prefix_lists'] ?? []));

        /*
         * Eşleşme türü ZORUNLUDUR: türsüz filtre "syntax error, expecting
         * <flag-value> or <attribute-value>" ile reddedilir. Tür verilmemişse
         * en yaygın kullanım olan orlonger yazılır.
         */
        $type = ($term['match_type'] ?? null) instanceof PrefixMatchType
            ? $term['match_type']
            : PrefixMatchType::OrLonger;

        foreach ($lists as $list) {
            $match['prefix_list_filters'][] = ['list' => (string) $list, 'match_type' => $type->value];
        }

        return PolicyMatch::emit($match);
    }

    /**
     * Terimin eylemleri.
     *
     * Sonlandırıcı eylem ayrı alandan (action) gelir; değiştirici eylemler
     * then_actions içindedir. then_actions verilmemişse eski çağrı biçimi
     * kullanılıyordur (yalnızca community silme); o da desteklenir.
     *
     * @param  array<string, mixed>  $term
     */
    private function thenElement(array $term): string
    {
        $actions = is_array($term['then_actions'] ?? null)
            ? $term['then_actions']
            : $this->legacyActions($term);

        $action = $term['action'] ?? null;

        $actions['action'] = $action instanceof PolicyAction
            ? $action->value
            : ($actions['action'] ?? null);

        return PolicyThen::emit($actions);
    }

    /**
     * then_actions'tan önceki çağrı biçimi: yalnızca "community delete".
     *
     * @param  array<string, mixed>  $term
     * @return array<string, mixed>
     */
    private function legacyActions(array $term): array
    {
        $actions = PolicyThen::blank();
        $community = $term['community_delete'] ?? null;

        if ($community !== null && $community !== '') {
            $actions['communities'][] = ['operation' => 'delete', 'name' => (string) $community];
        }

        return $actions;
    }

    private function deleteElement(string $element, string $name): string
    {
        return '<'.$element.' delete="delete"><name>'.XmlNode::escape($name).'</name></'.$element.'>';
    }

    private function wrap(string $body): string
    {
        return "<configuration><policy-options>{$body}</policy-options></configuration>";
    }

    /**
     * @param  list<string>  $documents
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, array $documents, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $documents,
            $summary,
            fn (NetconfSession $session) => $this->policies->sync($switch, $session),
        );
    }
}
