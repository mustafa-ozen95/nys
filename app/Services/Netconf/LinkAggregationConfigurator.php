<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;

/**
 * Bağlantı toplamayı (LAG) cihaza yazar.
 *
 * İki ayrı şey yönetilir:
 *
 *  1. "ae limiti" — chassis aggregated-devices ethernet device-count. Bu bir
 *     üst sınır değil, KAÇ TANE ae ARAYÜZÜNÜN YARATILACAĞIDIR: 4 verilirse
 *     cihaz ae0..ae3'ü gerçekten oluşturur. Sayı düşürüldüğünde üsttekiler
 *     yapılandırmaları dururken bile SESSİZCE yok olur — cihazda ölçüldü.
 *  2. ae arayüzünün kendi ayarları ve üye portları.
 *
 * Üyelikte kritik kısıt: üye olacak portun KENDİ mantıksal birimi aynı
 * commit'te silinmeli. Yoksa cihaz "logical unit is not allowed on aggregated
 * links" der ve commit düşer.
 *
 * ae'nin açıklaması, port modu ve VLAN üyeliği burada DEĞİL PortConfigurator'da
 * yönetilir: ae bir arayüzdür ve o yol zaten bu işi doğru yapıyor.
 */
class LinkAggregationConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private PortSynchronizer $ports,
        private PortConfigurator $interfaces,
    ) {}

    /**
     * ae limitini ayarlar. Aralık cihazda 1..111'dir.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function setDeviceCount(NetworkSwitch $switch, int $count): array
    {
        $configuration = '<configuration><chassis><aggregated-devices><ethernet>'
            ."<device-count>{$count}</device-count>"
            .'</ethernet></aggregated-devices></chassis></configuration>';

        return $this->write($switch, [$configuration], "ae limiti {$count} yapildi");
    }

    /**
     * ae arayüzünün toplama ayarları ve üye portları.
     *
     * Ayarlar önce SİLİNİR sonra yazılır: Junos'ta merge yalnızca ekler,
     * kaldırılan bir ayar (minimum-links gibi) olduğu yerde kalırdı.
     *
     * @param  array{lacp_mode?: ?LacpMode, lacp_periodic?: ?LacpPeriodic, minimum_links?: ?int, link_speed?: ?string}  $options
     * @param  list<string>|null  $memberNames  null verilirse üyelik değişmez
     * @param  array<string, mixed>  $settings  açıklama, port modu, VLAN'lar —
     *                                          arayüzün diğer ayarlarıyla AYNI
     *                                          commit'te yazılır
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function apply(
        NetworkSwitch $switch,
        SwitchPort $aggregate,
        array $options,
        ?array $memberNames = null,
        array $settings = [],
    ): array {
        $name = $this->escape($aggregate->name);

        $documents = [
            $this->wrap("<interface><name>{$name}</name><aggregated-ether-options delete=\"delete\"/></interface>"),
            $this->wrap("<interface><name>{$name}</name>".$this->optionsElement($options).'</interface>'),
        ];

        if ($settings !== []) {
            $documents = [
                ...$documents,
                ...$this->interfaces->documents($aggregate->name, $settings, $switch->schema_profile),
            ];
        }

        if ($memberNames !== null) {
            $documents = [...$documents, ...$this->membershipDocuments($aggregate, $memberNames)];
        }

        return $this->write($switch, $documents, "{$aggregate->name} ayarlandi");
    }

    /**
     * ae arayüzünü siler.
     *
     * Üyelikler de aynı commit'te kaldırılır. Cihaz dangling bir üyeliği
     * kabul ediyor (commit geçiyor) ama o port artık var olmayan bir ae'ye
     * bağlı kalır ve hiçbir VLAN'a ait olmaz — sessiz bir kayıp.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, SwitchPort $aggregate): array
    {
        $body = '';

        foreach ($aggregate->members()->get() as $member) {
            $body .= $this->memberElement($member->name, null);
        }

        $body .= '<interface delete="delete"><name>'.$this->escape($aggregate->name).'</name></interface>';

        return $this->write($switch, [$this->wrap($body)], "{$aggregate->name} silindi");
    }

    /**
     * Üyelik değişikliği: çıkarılanlardan bağ silinir, eklenenlere yazılır.
     *
     * @param  list<string>  $memberNames
     * @return list<string>
     */
    private function membershipDocuments(SwitchPort $aggregate, array $memberNames): array
    {
        $mevcut = $aggregate->members()->pluck('name')->all();

        $body = '';

        foreach (array_diff($mevcut, $memberNames) as $cikarilan) {
            $body .= $this->memberElement($cikarilan, null);
        }

        foreach (array_diff($memberNames, $mevcut) as $eklenen) {
            $body .= $this->memberElement($eklenen, $aggregate->name);
        }

        return $body === '' ? [] : [$this->wrap($body)];
    }

    /**
     * Bir portu ae'ye bağlar ya da bağını koparır.
     *
     * Bağlarken portun KENDİ birimi silinir: cihaz "logical unit is not
     * allowed on aggregated links" diyerek reddediyor. Bağ koparıldığında
     * port birimsiz kalır; hiçbir VLAN'a ait olmaz ve port ekranından yeniden
     * yapılandırılması gerekir.
     */
    private function memberElement(string $portName, ?string $aggregateName): string
    {
        $name = $this->escape($portName);

        if ($aggregateName === null) {
            return "<interface><name>{$name}</name>"
                .'<ether-options><ieee-802.3ad delete="delete"/></ether-options>'
                .'</interface>';
        }

        return "<interface><name>{$name}</name>"
            .'<unit delete="delete"><name>0</name></unit>'
            .'<ether-options><ieee-802.3ad><bundle>'.$this->escape($aggregateName).'</bundle></ieee-802.3ad></ether-options>'
            .'</interface>';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function optionsElement(array $options): string
    {
        $body = '';

        if (($options['minimum_links'] ?? null) !== null) {
            $body .= '<minimum-links>'.((int) $options['minimum_links']).'</minimum-links>';
        }

        if (($options['link_speed'] ?? null) !== null && $options['link_speed'] !== '') {
            $body .= '<link-speed>'.$this->escape((string) $options['link_speed']).'</link-speed>';
        }

        $lacp = '';

        // LACP rolü boş eleman olarak yazılır: <lacp><active/></lacp>.
        if (($options['lacp_mode'] ?? null) instanceof LacpMode) {
            $lacp .= '<'.$options['lacp_mode']->value.'/>';
        }

        // Periyot yalnızca LACP açıkken anlamlıdır.
        if ($lacp !== '' && ($options['lacp_periodic'] ?? null) instanceof LacpPeriodic) {
            $lacp .= '<periodic>'.$options['lacp_periodic']->value.'</periodic>';
        }

        if ($lacp !== '') {
            $body .= "<lacp>{$lacp}</lacp>";
        }

        return $body === '' ? '' : "<aggregated-ether-options>{$body}</aggregated-ether-options>";
    }

    private function wrap(string $body): string
    {
        return "<configuration><interfaces>{$body}</interfaces></configuration>";
    }

    /**
     * @param  list<string>  $documents
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function write(NetworkSwitch $switch, array $documents, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $documents,
            $summary,
            fn (NetconfSession $session) => $this->ports->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
