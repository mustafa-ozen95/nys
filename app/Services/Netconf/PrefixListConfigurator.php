<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Models\NetworkSwitch;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;

/**
 * Prefix list'leri cihaza yazar.
 *
 * Firewall filtreleri bu listelere dayanır; boş bir liste, ona dayanan
 * filtrenin hiçbir şeye uymaması demektir. Örneğin uplink çıkış filtresi
 * (koruma) boş bir "koru" listesine dayanıyorsa bütün trafiği düşürür —
 * liste yazma yolu bunun için de gerekli.
 *
 * Liste SİLİNİP YENİDEN YAZILMAZ; her işlem yalnızca dokunduğu düğümü taşır.
 * Prefix listede sıra anlamlı değildir (Junos girdileri kendi sıralar), o
 * yüzden route'un aksine kapsayıcıyı yeniden kurmaya gerek yok. Kazancı
 * güvenliktir: panelin tanımadığı satırlar (apply-groups gibi) ve panelin
 * henüz eşitlemediği girdiler cihazda olduğu yerde kalır. Kapsayıcıyı
 * "replace" ile kursaydık, CLI'dan eklenmiş bir girdi panelden yapılan ilk
 * düzenlemede sessizce silinirdi — kapatmaya çalıştığımız hatanın ta kendisi.
 */
class PrefixListConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private PrefixListSynchronizer $lists,
    ) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function createList(NetworkSwitch $switch, string $name): array
    {
        return $this->apply(
            $switch,
            $this->wrap('<prefix-list><name>'.$this->escape($name).'</name></prefix-list>'),
            "prefix list eklendi: {$name}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteList(NetworkSwitch $switch, SwitchPrefixList $list): array
    {
        return $this->apply(
            $switch,
            $this->wrap('<prefix-list delete="delete"><name>'.$this->escape($list->name).'</name></prefix-list>'),
            "prefix list silindi: {$list->name}",
        );
    }

    /**
     * Listenin ayarları: apply-path ifadeleri.
     *
     * Junos bir listeyi bu ifadelerle DİNAMİK doldurur ("system radius-server
     * <*>"): girdiler yapılandırmada tek tek yazılmaz, cihaz onları başka bir
     * hiyerarşiden türetir. Cihazda geçerli olduğu test1'de commit check ile
     * ölçüldü. Panelde gerçek bir alandır; liste bu yüzden salt okunur
     * sayılmaz.
     *
     * İki belge, TEK commit: apply-path bir listedir ve Junos'ta liste
     * değiştirmek "replace" ile ÇALIŞMAZ, merge üstüne ekler. Önce hepsi
     * silinir, sonra kalanlar yazılır (bkz. .ai/rules/netconf.md, VLAN
     * üyeliğinde aynı yol). Olmayan düğümü silmek uyarı üretir, hata değil.
     *
     * Yalnızca apply-path düğümüne dokunulur: girdiler ve panelin tanımadığı
     * satırlar cihazda olduğu gibi kalır.
     *
     * @param  list<string>  $applyPaths
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function updateSettings(NetworkSwitch $switch, SwitchPrefixList $list, array $applyPaths): array
    {
        $documents = [$this->wrap($this->listElement($list->name, '<apply-path delete="delete"/>'))];

        if ($applyPaths !== []) {
            $documents[] = $this->wrap($this->listElement($list->name, $this->applyPathElements($applyPaths)));
        }

        return $this->apply($switch, $documents, "prefix list guncellendi: {$list->name}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function addEntry(NetworkSwitch $switch, SwitchPrefixList $list, string $cidr): array
    {
        return $this->apply(
            $switch,
            $this->wrap($this->listElement($list->name, '<prefix-list-item><name>'.$this->escape($cidr).'</name></prefix-list-item>')),
            "prefix eklendi: {$list->name} · {$cidr}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function removeEntry(NetworkSwitch $switch, SwitchPrefixList $list, SwitchPrefixListEntry $entry): array
    {
        $cidr = $this->escape($entry->cidr);

        return $this->apply(
            $switch,
            $this->wrap($this->listElement($list->name, "<prefix-list-item delete=\"delete\"><name>{$cidr}</name></prefix-list-item>")),
            "prefix silindi: {$list->name} · {$entry->cidr}",
        );
    }

    /**
     * @param  list<string>  $applyPaths
     */
    private function applyPathElements(array $applyPaths): string
    {
        $body = '';

        foreach ($applyPaths as $path) {
            $body .= '<apply-path>'.$this->escape(trim($path)).'</apply-path>';
        }

        return $body;
    }

    private function listElement(string $name, string $body): string
    {
        return '<prefix-list><name>'.$this->escape($name).'</name>'.$body.'</prefix-list>';
    }

    private function wrap(string $body): string
    {
        return "<configuration><policy-options>{$body}</policy-options></configuration>";
    }

    /**
     * @param  string|list<string>  $configuration  Liste verilirse belgeler
     *                                              sırayla yüklenir ve TEK
     *                                              commit atılır
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, string|array $configuration, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $configuration,
            $summary,
            fn (NetconfSession $session) => $this->lists->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
