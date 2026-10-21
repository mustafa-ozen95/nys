<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Enums\IpFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchFirewallFilter;
use App\Models\SwitchFirewallPolicer;
use App\Support\Netconf\ResidualXml;

/**
 * Firewall filtrelerini ve policer'ları cihaza yazar.
 *
 * Bu, panelin en tehlikeli yazma yoludur: yanlış bir filtre trafiği tamamen
 * keser ve RE filtresi söz konusuysa yönetim erişimini de koparabilir. Yazma
 * yetkisi bu yüzden yalnızca yöneticidedir.
 *
 * Terim SIRASI anlamlıdır ve Junos'ta merge yeni terimi hep SONA ekler; bu
 * yüzden filtre silinip bütün terimleriyle yeniden yazılır (tek commit).
 *
 * PANELİN TANIMADIĞI SATIRLAR KAYBOLMAZ: okurken ham XML olarak saklanan
 * çocuk düğümler geri yazılırken aynen basılır — hem filtre kapsayıcısı hem
 * her terim için (terimin kendisi, "from" ve "then" ayrı ayrı). Panel
 * anladığını yazıp anlamadığını silseydi böyle filtreleri "salt okunur" ilan
 * etmek zorunda kalırdı.
 *
 * Geri alınabilir commit burada hayati: filtre yönetim erişimini koparırsa
 * onay verilemez ve cihaz kendini eski hâline döndürür.
 */
class FirewallConfigurator
{
    /**
     * Terimin "from" koşulları: model alanı => XML elemanı.
     *
     * Değeri alt eleman (<name>) içinde taşıyanlar ayrı listede; Junos
     * adres ve MAC listelerini öyle bekler.
     *
     * @var array<string, string>
     */
    private const NAMED_MATCHES = [
        'source_addresses' => 'source-address',
        'destination_addresses' => 'destination-address',
        'source_mac_addresses' => 'source-mac-address',
        'destination_mac_addresses' => 'destination-mac-address',
    ];

    /**
     * @var array<string, string>
     */
    private const TEXT_MATCHES = [
        'protocols' => 'protocol',
        'next_headers' => 'next-header',
        'ports' => 'port',
        'source_ports' => 'source-port',
        'destination_ports' => 'destination-port',
        'icmp_types' => 'icmp-type',
        'icmp_codes' => 'icmp-code',
        'dscps' => 'dscp',
        'traffic_classes' => 'traffic-class',
        'precedences' => 'precedence',
        'packet_lengths' => 'packet-length',
        'ttls' => 'ttl',
        'ip_options' => 'ip-options',
        'fragment_flags' => 'fragment-flags',
        'interfaces' => 'interface',
        'ether_types' => 'ether-type',
    ];

    /**
     * "from" altındaki boş işaretler: model alanı => XML elemanı.
     *
     * @var array<string, string>
     */
    private const MATCH_FLAGS = [
        'is_tcp_initial' => 'tcp-initial',
        'is_tcp_established' => 'tcp-established',
        'is_fragment' => 'is-fragment',
        'is_first_fragment' => 'first-fragment',
    ];

    /**
     * "then" altındaki metin taşıyan eylemler: model alanı => XML elemanı.
     *
     * Sayaç adı DÜZ METİNDİR (<count>sayac</count>); alt elemanlı biçim
     * "syntax error" verir (cihazda görüldü).
     *
     * @var array<string, string>
     */
    private const ACTION_VALUES = [
        'count_name' => 'count',
        'policer' => 'policer',
        'forwarding_class' => 'forwarding-class',
        'loss_priority' => 'loss-priority',
        'dscp' => 'dscp',
        'routing_instance' => 'routing-instance',
        'analyzer' => 'analyzer',
    ];

    /**
     * "then" altındaki boş işaretler: model alanı => XML elemanı.
     *
     * @var array<string, string>
     */
    private const ACTION_FLAGS = [
        'is_logged' => 'log',
        'is_syslogged' => 'syslog',
        'is_port_mirrored' => 'port-mirror',
        'is_sampled' => 'sample',
    ];

    public function __construct(
        private ConfigurationWriter $writer,
        private FirewallSynchronizer $filters,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>  $settings  Filtre seviyesi: interface_specific, residual_xml
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(
        NetworkSwitch $switch,
        FirewallFamily|IpFamily $family,
        string $name,
        array $terms,
        array $settings = [],
    ): array {
        $aile = FirewallFamily::coerce($family);

        return $this->apply(
            $switch,
            [$this->wrap($aile, $this->filterElement($name, $terms, $settings))],
            "firewall filtresi eklendi: {$name}",
        );
    }

    /**
     * Filtre seviyesindeki ayarlar (interface-specific ve korunan ham
     * satırlar) KAYITTAN okunur, çağırandan değil: her çağıranın ayrı ayrı
     * taşıması, birinin unutmasıyla o satırların silinmesi demek olurdu.
     *
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>|null  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function update(
        NetworkSwitch $switch,
        SwitchFirewallFilter $filter,
        array $terms,
        ?array $settings = null,
    ): array {
        $ayarlar = $settings ?? [
            'interface_specific' => $filter->interface_specific,
            'residual_xml' => $filter->residual_xml,
        ];

        $documents = [
            $this->wrap($filter->family, $this->deleteElement($filter->name)),
            $this->wrap($filter->family, $this->filterElement($filter->name, $terms, $ayarlar)),
        ];

        return $this->apply($switch, $documents, "firewall filtresi degistirildi: {$filter->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchFirewallFilter $filter): array
    {
        return $this->apply(
            $switch,
            [$this->wrap($filter->family, $this->deleteElement($filter->name))],
            "firewall filtresi silindi: {$filter->name}",
        );
    }

    /**
     * Policer cihazda "firewall policer <ad>" olarak, ailenin DIŞINDA durur.
     *
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createPolicer(NetworkSwitch $switch, string $name, array $settings): array
    {
        return $this->apply(
            $switch,
            [$this->wrapFirewall($this->policerElement($name, $settings))],
            "firewall policer eklendi: {$name}",
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updatePolicer(NetworkSwitch $switch, SwitchFirewallPolicer $policer, array $settings): array
    {
        /*
         * "replace" düğümü olduğu gibi yeniden kurar: panelden kaldırılan bir
         * sınır cihazda da kalkar. Merge kullanılsaydı boşaltılan
         * burst-size-limit olduğu yerde kalır, kullanıcı sildiğini sanırdı.
         */
        return $this->apply(
            $switch,
            [$this->wrapFirewall($this->policerElement($policer->name, $settings, replace: true))],
            "firewall policer degistirildi: {$policer->name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deletePolicer(NetworkSwitch $switch, SwitchFirewallPolicer $policer): array
    {
        return $this->apply(
            $switch,
            [$this->wrapFirewall(
                '<policer delete="delete"><name>'.$this->escape($policer->name).'</name></policer>'
            )],
            "firewall policer silindi: {$policer->name}",
        );
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @param  array<string, mixed>  $settings
     */
    private function filterElement(string $name, array $terms, array $settings): string
    {
        $body = '<name>'.$this->escape($name).'</name>';

        // Arayüz başına ayrı sayaç; filtrenin kendi satırıdır.
        if (($settings['interface_specific'] ?? false) === true) {
            $body .= '<interface-specific/>';
        }

        // Filtre seviyesinde panelin tanımadığı satırlar: olduğu gibi geri.
        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        foreach ($terms as $term) {
            $body .= $this->termElement($term);
        }

        return "<filter>{$body}</filter>";
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function termElement(array $term): string
    {
        $body = '<name>'.$this->escape((string) $term['name']).'</name>';
        $body .= $this->fromElement($term);
        $body .= $this->thenElement($term);
        $body .= ResidualXml::emit($term['residual_xml'] ?? null);

        return "<term>{$body}</term>";
    }

    /**
     * Koşulsuz terim HER ŞEYE uyar; "from" düğümü hiç yazılmaz.
     *
     * @param  array<string, mixed>  $term
     */
    private function fromElement(array $term): string
    {
        $body = '';

        foreach (self::NAMED_MATCHES as $field => $element) {
            foreach ($term[$field] ?? [] as $value) {
                $body .= "<{$element}><name>".$this->escape((string) $value)."</name></{$element}>";
            }
        }

        $body .= $this->prefixListElements($term, 'source_prefix_lists', 'source_prefix_list_excepts', 'source-prefix-list');
        $body .= $this->prefixListElements($term, 'destination_prefix_lists', 'destination_prefix_list_excepts', 'destination-prefix-list');

        foreach (self::TEXT_MATCHES as $field => $element) {
            foreach ($term[$field] ?? [] as $value) {
                $body .= "<{$element}>".$this->escape((string) $value)."</{$element}>";
            }
        }

        if (filled($term['tcp_flags'] ?? null)) {
            $body .= '<tcp-flags>'.$this->escape((string) $term['tcp_flags']).'</tcp-flags>';
        }

        $body .= $this->flagElements($term, self::MATCH_FLAGS);
        $body .= ResidualXml::emit($term['from_residual_xml'] ?? null);

        return $body === '' ? '' : "<from>{$body}</from>";
    }

    /**
     * Prefix list koşulu. "except" işareti koşulu TERSİNE çevirir; adla
     * birlikte yazılmazsa filtre sessizce ters çalışır.
     *
     * @param  array<string, mixed>  $term
     */
    private function prefixListElements(array $term, string $field, string $exceptField, string $element): string
    {
        $excepts = (array) ($term[$exceptField] ?? []);
        $body = '';

        foreach ($term[$field] ?? [] as $value) {
            $ic = '<name>'.$this->escape((string) $value).'</name>';

            if (in_array((string) $value, array_map('strval', $excepts), true)) {
                $ic .= '<except/>';
            }

            $body .= "<{$element}>{$ic}</{$element}>";
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function thenElement(array $term): string
    {
        $body = '';

        // Sayaç, günlük ve niteleyiciler eylemden ÖNCE yazılır; accept/discard
        // zinciri bitirir.
        foreach (self::ACTION_VALUES as $field => $element) {
            if (filled($term[$field] ?? null)) {
                $body .= "<{$element}>".$this->escape((string) $term[$field])."</{$element}>";
            }
        }

        $body .= $this->flagElements($term, self::ACTION_FLAGS);
        $body .= ResidualXml::emit($term['then_residual_xml'] ?? null);

        /*
         * "next term" zinciri bitirmez, sıradaki terime geçer; bu yüzden
         * accept/discard/reject ile birlikte yazılmaz (cihaz da reddeder).
         */
        if (($term['goes_to_next_term'] ?? false) === true) {
            return "<then>{$body}<next>term</next></then>";
        }

        $action = $term['action'] ?? null;

        if ($action instanceof FirewallAction) {
            $body .= '<'.$action->value.'/>';
        }

        return $body === '' ? '' : "<then>{$body}</then>";
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $flags  model alanı => XML elemanı
     */
    private function flagElements(array $source, array $flags): string
    {
        $body = '';

        foreach ($flags as $field => $element) {
            if (($source[$field] ?? false) === true) {
                $body .= "<{$element}/>";
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function policerElement(string $name, array $settings, bool $replace = false): string
    {
        $body = '<name>'.$this->escape($name).'</name>';

        if (($settings['filter_specific'] ?? false) === true) {
            $body .= '<filter-specific/>';
        }

        $limit = '';

        foreach (['bandwidth_limit' => 'bandwidth-limit', 'bandwidth_percent' => 'bandwidth-percent', 'burst_size_limit' => 'burst-size-limit'] as $field => $element) {
            if (filled($settings[$field] ?? null)) {
                $limit .= "<{$element}>".$this->escape((string) $settings[$field])."</{$element}>";
            }
        }

        if ($limit !== '') {
            $body .= "<if-exceeding>{$limit}</if-exceeding>";
        }

        $then = ($settings['is_discard'] ?? false) === true ? '<discard/>' : '';

        foreach (['forwarding_class' => 'forwarding-class', 'loss_priority' => 'loss-priority'] as $field => $element) {
            if (filled($settings[$field] ?? null)) {
                $then .= "<{$element}>".$this->escape((string) $settings[$field])."</{$element}>";
            }
        }

        if ($then !== '') {
            $body .= "<then>{$then}</then>";
        }

        $body .= ResidualXml::emit($settings['residual_xml'] ?? null);

        return $replace ? "<policer replace=\"replace\">{$body}</policer>" : "<policer>{$body}</policer>";
    }

    private function deleteElement(string $name): string
    {
        return '<filter delete="delete"><name>'.$this->escape($name).'</name></filter>';
    }

    private function wrap(FirewallFamily $family, string $body): string
    {
        return $this->wrapFirewall(
            '<family><'.$family->value.'>'.$body.'</'.$family->value.'></family>'
        );
    }

    private function wrapFirewall(string $body): string
    {
        return "<configuration><firewall>{$body}</firewall></configuration>";
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
            fn (NetconfSession $session) => $this->filters->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
