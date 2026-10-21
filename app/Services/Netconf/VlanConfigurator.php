<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Enums\PortMode;
use App\Models\NetworkSwitch;
use App\Models\SwitchPort;
use App\Models\Vlan;

/**
 * VLAN'ları cihaza yazar.
 *
 * Cihazda VLAN'ın kimliği ADIDIR; port üyelikleri de adla tutulur. Bu yüzden
 * ad değiştirilemez: Junos'ta yeniden adlandırma, VLAN'ı silip yeniden
 * yaratmak ve bütün üye portları yeniden yazmak demektir. Panelde ad yalnızca
 * VLAN yaratılırken sorulur.
 *
 * İki tuzak cihazda doğrulandı:
 *
 * - l3-interface, "interfaces vlan unit <etiket>" önceden tanımlı değilse
 *   commit check'te reddedilir; ikisi aynı yüklemede gider.
 * - Bir porta VLAN atamak, portun üyeliğini DEĞİŞTİRMEKTİR. Access portta iki
 *   üye olamaz; üyelik port yazma yolundan (PortConfigurator) geçirilir.
 *
 * L3 arayüzünün ADI şema ailesine bağlıdır: ELS öncesinde "vlan", ELS'te
 * "irb". ELS'te "interfaces vlan" diye bir arayüz yoktur.
 *
 * L3 arayüzü VLAN'la BİRLİKTE gider. VLAN silinip birim bırakılırsa geride
 * hiçbir VLAN'a bağlı olmayan, üstünde hâlâ IP taşıyan öksüz bir arayüz
 * kalır: panelde görünmez, cihazda adresi duyurmaya devam eder.
 */
class VlanConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private PortConfigurator $ports,
        private PortSynchronizer $synchronizer,
    ) {}

    /**
     * @param  list<SwitchPort>  $ports  VLAN'a üye olacak portlar
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function create(
        NetworkSwitch $switch,
        string $name,
        ?int $tag,
        ?string $l3Interface,
        array $ports,
        ?string $description = null,
    ): array {
        $documents = [$this->vlanDocument($switch, $name, $tag, $l3Interface, $description)];

        foreach ($ports as $port) {
            $documents = array_merge($documents, $this->ports->membershipDocuments(
                $port->name,
                $this->membersWith($port, $name),
                $this->currentMembers($port),
            ));
        }

        return $this->apply($switch, $documents, "vlan eklendi: {$name} (".($tag ?? 'etiketsiz').')');
    }

    /**
     * @param  list<SwitchPort>  $ports  VLAN'ın yeni üye listesi
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function update(
        NetworkSwitch $switch,
        Vlan $vlan,
        ?int $tag,
        ?string $l3Interface,
        array $ports,
        ?string $description = null,
    ): array {
        $documents = [$this->vlanDocument($switch, $vlan->name, $tag, $l3Interface, $description)];

        /*
         * L3 arayüzü değiştiyse ESKİ birim de gider. Bırakılsaydı hiçbir
         * VLAN'a bağlı olmayan bir arayüz olarak cihazda kalırdı.
         */
        if ($vlan->l3_interface !== null && $vlan->l3_interface !== $l3Interface) {
            $documents[] = $this->l3UnitDocument($vlan->l3_interface);
        }

        $secilen = array_map(fn (SwitchPort $port): int => $port->id, $ports);

        foreach ($this->affectedPorts($switch, $vlan, $secilen) as $port) {
            $members = in_array($port->id, $secilen, true)
                ? $this->membersWith($port, $vlan->name)
                : $this->membersWithout($port, $vlan->name);

            $documents = array_merge($documents, $this->ports->membershipDocuments(
                $port->name,
                $members,
                $this->currentMembers($port),
            ));
        }

        return $this->apply($switch, $documents, "vlan degistirildi: {$vlan->name} (".($tag ?? 'etiketsiz').')');
    }

    /**
     * VLAN silinmeden önce üye portlardan çıkarılır; hâlâ referans veren bir
     * port varsa cihaz commit'i reddeder.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function delete(NetworkSwitch $switch, Vlan $vlan): array
    {
        $documents = [];

        foreach ($vlan->ports as $port) {
            $documents = array_merge($documents, $this->ports->membershipDocuments(
                $port->name,
                $this->membersWithout($port, $vlan->name),
                [...$this->currentMembers($port), $vlan->name],
            ));
        }

        // L3 arayüzü VLAN'la birlikte gider; öksüz birim bırakılmaz.
        if ($vlan->l3_interface !== null) {
            $documents[] = $this->l3UnitDocument($vlan->l3_interface);
        }

        $documents[] = '<configuration><vlans><vlan delete="delete"><name>'
            .$this->escape($vlan->name).'</name></vlan></vlans></configuration>';

        return $this->apply($switch, $documents, "vlan silindi: {$vlan->name} (".($vlan->vlan_tag ?? 'etiketsiz').')');
    }

    /**
     * Üyeliği değişen portlar: yeni listeye girenler ve listeden çıkanlar.
     *
     * @param  list<int>  $selected
     * @return list<SwitchPort>
     */
    private function affectedPorts(NetworkSwitch $switch, Vlan $vlan, array $selected): array
    {
        $mevcut = $vlan->ports()->pluck('switch_ports.id')->all();
        $degisen = array_unique(array_merge(array_diff($selected, $mevcut), array_diff($mevcut, $selected)));

        return $degisen === []
            ? []
            : $switch->ports()->with('vlans')->whereIn('id', $degisen)->get()->all();
    }

    /**
     * Porta VLAN eklemek, port moduna göre farklı şeydir:
     *
     * - Access (ve henüz yapılandırılmamış) portta üyelik TEK olabilir; yeni
     *   VLAN eskisinin yerini alır. Cihaz aksini commit check'te reddediyor:
     *   "Access interface <ge-0/0/9.0> has more than one vlan member".
     * - Trunk portta üyelik listedir; yeni VLAN listeye eklenir.
     *
     * @return list<string>
     */
    private function membersWith(SwitchPort $port, string $name): array
    {
        if ($port->port_mode !== PortMode::Trunk) {
            return [$name];
        }

        $members = $this->currentMembers($port);

        if (! in_array($name, $members, true)) {
            $members[] = $name;
        }

        return array_values($members);
    }

    /**
     * @return list<string>
     */
    private function membersWithout(SwitchPort $port, string $name): array
    {
        return array_values(array_filter(
            $this->currentMembers($port),
            static fn (string $member): bool => $member !== $name,
        ));
    }

    /**
     * @return list<string>
     */
    private function currentMembers(SwitchPort $port): array
    {
        return $port->vlans->pluck('name')->all();
    }

    private function vlanDocument(
        NetworkSwitch $switch,
        string $name,
        ?int $tag,
        ?string $l3Interface,
        ?string $description = null,
    ): string {
        $escaped = $this->escape($name);

        /*
         * Etiket ZORUNLU DEĞİL: cihaz "vlans <ad>" satırını vlan-id olmadan
         * kabul ediyor; etiketsiz VLAN geçerli bir yapılandırmadır.
         * Boş bırakmak "dokunma" değil "sil" demektir; aksi hâlde kullanıcı
         * etiketi temizlediğini sanırken cihazdaki eski etiket kalırdı.
         * Var olan bir VLAN'dan vlan-id silmek test1'de doğrulandı.
         */
        $etiket = $tag === null
            ? '<vlan-id delete="delete"/>'
            : "<vlan-id>{$tag}</vlan-id>";

        /*
         * Boş açıklama "dokunma" değil "sil" demektir: merge yalnızca ekler,
         * silme açıkça istenmezse cihazdaki eski açıklama olduğu yerde kalır
         * ve kullanıcı temizlediğini sanırdı.
         */
        $aciklama = $description === null
            ? '<description delete="delete"/>'
            : '<description>'.$this->escape($description).'</description>';

        if ($l3Interface === null) {
            return "<configuration><vlans><vlan><name>{$escaped}</name>{$etiket}"
                .$aciklama.'<l3-interface delete="delete"/></vlan></vlans></configuration>';
        }

        /*
         * Arayüzün adı kullanıcının yazdığından değil CİHAZIN ŞEMASINDAN
         * gelir: "vlan.100" yazılmış olsa da ELS bir cihazda birim "irb"
         * altına açılmak zorundadır.
         */
        $parent = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->l3InterfaceName();
        $unit = $this->escape($this->unitOf($l3Interface));
        $reference = $this->escape("{$parent}.".$this->unitOf($l3Interface));

        // L3 arayüzü önce "interfaces" altında tanımlanmalı; aynı yüklemede
        // gitmezse commit check reddeder.
        return '<configuration>'
            ."<interfaces><interface><name>{$parent}</name><unit><name>{$unit}</name></unit></interface></interfaces>"
            ."<vlans><vlan><name>{$escaped}</name>{$etiket}"
            .$aciklama
            ."<l3-interface>{$reference}</l3-interface></vlan></vlans>"
            .'</configuration>';
    }

    /**
     * "vlan.100" ya da "irb.100" birimini siler.
     *
     * Silinen birimin üstünde adres varsa adres de gider; bu yüzden çağıran
     * taraf önce DependencyGuard'a sorar ve adresi olan bir VLAN'ın L3
     * arayüzünü hiç kaldırmaz.
     */
    private function l3UnitDocument(string $l3Interface): string
    {
        $parent = $this->escape((string) preg_replace('/\.\d+$/', '', $l3Interface));
        $unit = $this->escape($this->unitOf($l3Interface));

        return '<configuration><interfaces>'
            ."<interface><name>{$parent}</name><unit delete=\"delete\"><name>{$unit}</name></unit></interface>"
            .'</interfaces></configuration>';
    }

    /**
     * "vlan.100" → "100"; ad ne olursa olsun (vlan/irb) birim numarası alınır.
     */
    private function unitOf(string $l3Interface): string
    {
        return (string) preg_replace('/^[A-Za-z0-9_-]+\./', '', $l3Interface);
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
            fn (NetconfSession $session) => $this->synchronizer->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
