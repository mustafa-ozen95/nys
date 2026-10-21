<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\AdminStatus;
use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Enums\PortType;
use SimpleXMLElement;

/**
 * Portları cihazdan NETCONF ile okur.
 *
 * Yalnızca Zabbix'ten gelselerdi Zabbix'in keşif kuralı çalışana kadar
 * (şablon aralığı bir saat) port envanteri boş kalırdı. Port ayarları cihaza
 * yazıldığı için okuma da aynı kaynaktan yapılır: yazdığımız değeri saniyeler
 * içinde geri okuyabilmeliyiz.
 *
 * Anlık trafik sayaçları Zabbix'te kalır; NETCONF'un işi yapılandırma ve
 * durumdur.
 *
 * İki RPC kullanılır: arayüz durumu (ad, ifIndex, admin/oper) ve
 * yapılandırma (açıklama, disable, hız, VLAN üyeliği, PoE).
 */
class PortReader
{
    /**
     * Yalnızca panel portları: ge-, xe-, et-. me0, vcp, bme, lo0, vlan gibi
     * iç arayüzler envanterde işe yaramaz.
     */
    private const PHYSICAL = '/^(ge|xe|et)-\d+\/\d+\/\d+$/';

    /**
     * Toplama arayüzleri. Bunlar donanım değildir; chassis'in device-count
     * kadarını YARATMASIYLA var olurlar ve sayı düşürülünce yok olurlar.
     */
    private const AGGREGATE = '/^ae\d+$/';

    /**
     * @return array{ports: list<array{name: string, interface_kind: PortType, if_index: ?int, description: ?string, mtu: ?int, admin_status: AdminStatus, oper_status: PortStatus, speed_config: ?string, duplex_config: ?string, is_autonegotiation_enabled: bool, is_poe_enabled: ?bool, port_mode: PortMode, vlans: list<string>, untagged: ?string, bundle: ?string, lacp_mode: ?LacpMode, lacp_periodic: ?LacpPeriodic, minimum_links: ?int, link_speed: ?string}>, vlans: array<string, array{tag: ?int, l3_interface: ?string, description: ?string}>, device_count: ?int}
     *
     * @throws NetconfException
     */
    public function ports(NetconfSession $session): array
    {
        $status = $this->statuses($session->rpc('<get-interface-information/>'));

        $configuration = $session->rpc(
            '<get-configuration database="committed"><configuration>'
            .'<interfaces/><vlans/><poe/><chassis/>'
            .'</configuration></get-configuration>'
        );

        $vlans = $this->vlanTags($configuration);
        $vlanSide = $this->vlanSideMembers($configuration);

        // PoE her portta yok; hangi portlarda olduğunu CİHAZ söyler.
        $poeCapable = $this->poeCapablePorts($session);
        $poeDisabled = $this->poeDisabledPorts($configuration);
        $configured = $this->configuredInterfaces($configuration);
        $ports = [];

        /*
         * Portların kaynağı DONANIMDIR, yapılandırma değil. Yapılandırmada
         * stanza'sı olmayan port da cihazda vardır — EX kasalarındaki xe-0/1/*
         * uplink modülü portları tipik olarak öyledir. Config üzerinden dönülseydi o portlar
         * envanterde hiç görünmezdi.
         */
        foreach ($status as $name => $state) {
            $node = $configured[$name] ?? null;
            $members = $this->vlanMembers($node, $vlans, $vlanSide[$name] ?? []);

            $aggregate = preg_match(self::AGGREGATE, $name) === 1;

            $ports[] = [
                'name' => $name,
                'interface_kind' => $aggregate ? PortType::Aggregated : PortType::Physical,
                'if_index' => $state['if_index'],
                'description' => $node === null ? null : $this->text($node, 'description'),
                'mtu' => $node === null ? null : $this->wholeNumber($node, 'mtu'),
                'admin_status' => $this->adminStatus($node, $state),
                'oper_status' => $state['oper_status'],
                'speed_config' => $node === null ? null : $this->speed($node),
                'duplex_config' => $node === null ? null : $this->linkMode($node),
                'is_autonegotiation_enabled' => $node !== null && $this->hasAutoNegotiation($node),
                'is_poe_enabled' => $this->poeState($name, $poeCapable, $poeDisabled),
                'port_mode' => $this->mode($node, $members),
                'vlans' => $members['tagged'],
                'untagged' => $members['untagged'],

                // Fiziksel portun üye olduğu ae; cihazda "ieee-802.3ad bundle".
                'bundle' => $node === null || $aggregate ? null : $this->bundle($node),

                // Yalnızca ae arayüzünde dolu olan ayarlar.
                'lacp_mode' => $node === null || ! $aggregate ? null : $this->lacpMode($node),
                'lacp_periodic' => $node === null || ! $aggregate ? null : $this->lacpPeriodic($node),
                'minimum_links' => $node === null || ! $aggregate ? null : $this->aggregateInt($node, 'minimum-links'),
                'link_speed' => $node === null || ! $aggregate ? null : $this->aggregateText($node, 'link-speed'),
            ];
        }

        return [
            'ports' => $ports,
            'vlans' => $vlans,
            'device_count' => $this->deviceCount($configuration),
        ];
    }

    /**
     * "ae limiti": chassis aggregated-devices ethernet device-count.
     *
     * Tanımlı değilse cihazda hiç ae arayüzü yoktur.
     */
    private function deviceCount(SimpleXMLElement $configuration): ?int
    {
        $value = $configuration->xpath(
            '//*[local-name()="aggregated-devices"]/*[local-name()="ethernet"]/*[local-name()="device-count"]'
        )[0] ?? null;

        return $value === null ? null : (int) trim((string) $value);
    }

    /**
     * Portun üye olduğu ae.
     *
     * Junos eleman adını "802.3ad" DEĞİL "ieee-802.3ad" olarak yazar; set
     * komutundaki yazımına bakarak aranırsa hiç bulunamaz.
     */
    private function bundle(SimpleXMLElement $node): ?string
    {
        $value = $node->xpath(
            './*[local-name()="ether-options"]/*[local-name()="ieee-802.3ad"]/*[local-name()="bundle"]'
        )[0] ?? null;

        return $value === null ? null : (trim((string) $value) ?: null);
    }

    /**
     * LACP rolü boş eleman olarak gelir: <lacp><active/></lacp>. Hiç yoksa
     * LAG statiktir.
     */
    private function lacpMode(SimpleXMLElement $node): ?LacpMode
    {
        foreach (LacpMode::cases() as $mode) {
            $found = $node->xpath(
                './*[local-name()="aggregated-ether-options"]/*[local-name()="lacp"]/*[local-name()="'.$mode->value.'"]'
            );

            if ($found !== false && $found !== []) {
                return $mode;
            }
        }

        return null;
    }

    private function lacpPeriodic(SimpleXMLElement $node): ?LacpPeriodic
    {
        $value = $node->xpath(
            './*[local-name()="aggregated-ether-options"]/*[local-name()="lacp"]/*[local-name()="periodic"]'
        )[0] ?? null;

        return $value === null ? null : LacpPeriodic::tryFrom(trim((string) $value));
    }

    private function aggregateText(SimpleXMLElement $node, string $name): ?string
    {
        $value = $node->xpath('./*[local-name()="aggregated-ether-options"]/*[local-name()="'.$name.'"]')[0] ?? null;

        return $value === null ? null : (trim((string) $value) ?: null);
    }

    private function aggregateInt(SimpleXMLElement $node, string $name): ?int
    {
        $value = $this->aggregateText($node, $name);

        return $value === null ? null : (int) $value;
    }

    /**
     * Yapılandırmada stanza'sı olan arayüzler, ada göre.
     *
     * @return array<string, SimpleXMLElement>
     */
    private function configuredInterfaces(SimpleXMLElement $configuration): array
    {
        $interfaces = [];

        foreach ($configuration->xpath('//*[local-name()="interfaces"]/*[local-name()="interface"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name !== null) {
                $interfaces[$name] = $node;
            }
        }

        return $interfaces;
    }

    /**
     * Arayüz durumu; ad => ifIndex, admin ve oper.
     *
     * @return array<string, array{if_index: ?int, admin_status: AdminStatus, oper_status: PortStatus}>
     */
    private function statuses(SimpleXMLElement $reply): array
    {
        $statuses = [];

        foreach ($reply->xpath('//*[local-name()="physical-interface"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null
                || (preg_match(self::PHYSICAL, $name) !== 1 && preg_match(self::AGGREGATE, $name) !== 1)) {
                continue;
            }

            $index = $this->text($node, 'snmp-index');

            $statuses[$name] = [
                'if_index' => $index === null ? null : (int) $index,
                'admin_status' => $this->text($node, 'admin-status') === 'up' ? AdminStatus::Up : AdminStatus::Down,
                'oper_status' => $this->text($node, 'oper-status') === 'up' ? PortStatus::Up : PortStatus::Down,
            ];
        }

        return $statuses;
    }

    /**
     * VLAN adı => etiket ve L3 arayüzü. Port üyelikleri cihazda adla tutulur,
     * envanterde etiketle; çeviri buradan yapılır.
     *
     * @return array<string, array{tag: ?int, l3_interface: ?string}>
     */
    private function vlanTags(SimpleXMLElement $configuration): array
    {
        $tags = [];

        foreach ($configuration->xpath('//*[local-name()="vlans"]/*[local-name()="vlan"]') ?: [] as $node) {
            $name = $this->text($node, 'name');
            $tag = $this->text($node, 'vlan-id');

            /*
             * Etiket ZORUNLU DEĞİLDİR. Junos "vlans <ad>" satırını vlan-id
             * olmadan da kabul ediyor (test1'de ölçüldü); etiketsiz bir VLAN
             * L3 arayüzü ve adres de taşıyabilir. Etiket şart koşulsaydı bu
             * VLAN'lar envanterde hiç görünmez, adresleri sahipsiz kalırdı.
             */
            if ($name !== null) {
                $tags[$name] = [
                    'tag' => $tag !== null && ctype_digit($tag) ? (int) $tag : null,
                    'l3_interface' => $this->text($node, 'l3-interface'),
                    'description' => $this->text($node, 'description'),
                ];
            }
        }

        return $tags;
    }

    /**
     * "vlans <ad> interface <port>.0" ile bildirilen üyelikler.
     *
     * Cihaz arayüz adını birimle yazar (ge-0/0/38.0); envanterde port adı
     * birimsizdir.
     *
     * @return array<string, list<string>> port adı => VLAN adları
     */
    private function vlanSideMembers(SimpleXMLElement $configuration): array
    {
        $members = [];

        foreach ($configuration->xpath('//*[local-name()="vlans"]/*[local-name()="vlan"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name === null) {
                continue;
            }

            foreach ($node->xpath('./*[local-name()="interface"]/*[local-name()="name"]') ?: [] as $arayuz) {
                $port = preg_replace('/\.\d+$/', '', trim((string) $arayuz));

                if ($port !== null && $port !== '') {
                    $members[$port][] = $name;
                }
            }
        }

        return $members;
    }

    /**
     * PoE besleyebilen portlar. Donanım sorusudur, yapılandırma sorusu değil:
     * bazı kasalarda yalnızca ilk sekiz port PoE taşır ve cihaz bunu
     * get-poe-interface-information ile bildirir.
     *
     * PoE'si hiç olmayan bir cihazda RPC hata döndürür; o durumda liste boştur
     * ve panelde PoE alanı hiç çizilmez.
     *
     * @return list<string>
     */
    private function poeCapablePorts(NetconfSession $session): array
    {
        try {
            $reply = $session->rpc('<get-poe-interface-information/>');
        } catch (NetconfException) {
            return [];
        }

        $ports = [];

        foreach ($reply->xpath('//*[local-name()="interface-name"]') ?: [] as $node) {
            $name = trim((string) $node);

            if ($name !== '') {
                $ports[] = $name;
            }
        }

        return $ports;
    }

    /**
     * Yapılandırmada PoE'si kapatılmış portlar. Junos'ta PoE varsayılan olarak
     * açıktır; kapalıysa "disable" satırı durur.
     *
     * @return list<string>
     */
    private function poeDisabledPorts(SimpleXMLElement $configuration): array
    {
        $disabled = [];

        foreach ($configuration->xpath('//*[local-name()="poe"]/*[local-name()="interface"]') ?: [] as $node) {
            $name = $this->text($node, 'name');

            if ($name !== null && ($node->xpath('./*[local-name()="disable"]') ?: []) !== []) {
                $disabled[] = $name;
            }
        }

        return $disabled;
    }

    /**
     * null: portta PoE donanımı yok. true/false: var ve açık/kapalı.
     *
     * @param  list<string>  $capable
     * @param  list<string>  $disabled
     */
    private function poeState(string $name, array $capable, array $disabled): ?bool
    {
        if (! in_array($name, $capable, true)) {
            return null;
        }

        return ! in_array($name, $disabled, true);
    }

    /**
     * Etiketsiz VLAN port moduna göre farklı yerdedir:
     *
     * - Access portta üyelik tektir ve o üye etiketsizdir.
     * - Trunk portta arayüz seviyesindeki native-vlan-id işaret eder; o VLAN
     *   üye listesinde de durur.
     *
     * Üyelik CİHAZDA İKİ YOLDAN bildirilebilir ve cihaz ikisini TEK üyelik
     * sayar:
     *
     *     interfaces <port> unit 0 family ethernet-switching vlan members <ad>
     *     vlans <ad> interface <port>.0
     *
     * Kanıtı cihazın kendi reddi: iki yoldan FARKLI VLAN bildirilen access
     * portta "Access interface <ge-0/1/0.0> has more than one vlan member"
     * diyor. Yalnızca arayüz tarafı okunsaydı, üyeliği vlans tarafında
     * yazılmış portlar panelde ÜYELİKSİZ görünürdü.
     *
     * @param  array<string, array{tag: ?int, l3_interface: ?string}>  $vlans
     * @param  list<string>  $vlanSide  vlans altında bildirilmiş üyelikler
     * @return array{tagged: list<string>, untagged: ?string}
     */
    private function vlanMembers(?SimpleXMLElement $node, array $vlans, array $vlanSide = []): array
    {
        $members = [];

        foreach ($node?->xpath('.//*[local-name()="ethernet-switching"]//*[local-name()="members"]') ?: [] as $member) {
            $value = trim((string) $member);

            if ($value !== '') {
                $members[] = $value;
            }
        }

        // İki yoldan da bildirilen VLAN listede bir kez durur.
        foreach ($vlanSide as $value) {
            if (! in_array($value, $members, true)) {
                $members[] = $value;
            }
        }

        $trunk = $node !== null && $this->switchingMode($node) === 'trunk';

        $untagged = $trunk
            ? $this->nativeVlanName($node, $vlans)
            : (count($members) === 1 ? $members[0] : null);

        return [
            'tagged' => array_values(array_filter(
                $members,
                static fn (string $member): bool => $member !== $untagged,
            )),
            'untagged' => $untagged,
        ];
    }

    /**
     * Portun anahtarlama modu: "access", "trunk" ya da boş.
     *
     * Junos aynı ayarı İKİ farklı adla kabul ediyor ve hangisi yazıldıysa
     * onu saklıyor:
     *
     *   port-mode       — klasik ad (pre-ELS)
     *   interface-mode  — ELS'in adı; 12.3R12.4 de kabul ediyor
     *                     (jn32-test üzerinde ölçüldü: ikisi de yazılabiliyor
     *                     ve fark çıktısında olduğu gibi görünüyor)
     *
     * Yalnızca "port-mode" aranırsa, CLI'dan "interface-mode" ile ayarlanmış
     * bir port panelde MODSUZ görünür: trunk portu access sanılır, VLAN
     * üyeliği yanlış yorumlanır. İkisi de okunur.
     */
    private function switchingMode(SimpleXMLElement $node): string
    {
        foreach (['port-mode', 'interface-mode'] as $etiket) {
            $deger = trim((string) ($node->xpath('.//*[local-name()="'.$etiket.'"]')[0] ?? ''));

            if ($deger !== '') {
                return $deger;
            }
        }

        return '';
    }

    /**
     * native-vlan-id etiketi taşır; envanter ise VLAN adıyla çalışır.
     *
     * @param  array<string, array{tag: ?int, l3_interface: ?string}>  $vlans
     */
    private function nativeVlanName(?SimpleXMLElement $node, array $vlans): ?string
    {
        $tag = $node === null ? null : $this->text($node, 'native-vlan-id');

        if ($tag === null || ! ctype_digit($tag)) {
            return null;
        }

        foreach ($vlans as $name => $vlan) {
            if ($vlan['tag'] === (int) $tag) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array{tagged: list<string>, untagged: ?string}  $members
     */
    private function mode(?SimpleXMLElement $node, array $members): PortMode
    {
        if ($node !== null && $this->switchingMode($node) === 'trunk') {
            return PortMode::Trunk;
        }

        if (($node?->xpath('.//*[local-name()="family"]/*[local-name()="inet"]') ?: []) !== []) {
            return PortMode::Routed;
        }

        if ($members['untagged'] !== null || $members['tagged'] !== []) {
            return PortMode::Access;
        }

        return PortMode::Unconfigured;
    }

    /**
     * Yapılandırmada "disable" varsa port kapalıdır; yoksa cihazın bildirdiği
     * durum geçerlidir.
     *
     * @param  array{if_index: ?int, admin_status: AdminStatus, oper_status: PortStatus}  $status
     */
    private function adminStatus(?SimpleXMLElement $node, array $status): AdminStatus
    {
        if ($node !== null && ($node->xpath('./*[local-name()="disable"]') ?: []) !== []) {
            return AdminStatus::Down;
        }

        return $status['admin_status'];
    }

    /**
     * ether-options altındaki hız ayarı. auto-negotiation bir hız değeri
     * değildir, ayrı okunur.
     */
    private function speed(SimpleXMLElement $node): ?string
    {
        /*
         * ELS'te hız arayüzün KENDİ altında düz metindir (speed 100m);
         * ELS öncesinde ether-options altında bir ELEMANDIR
         * (ether-options { speed { ethernet-100m; } }). Okuyucu cihazın
         * şemasını bilmiyor, bu yüzden ikisine de bakar — bir cihazda
         * yalnızca biri bulunur.
         */
        $duz = trim((string) ($node->xpath('./*[local-name()="speed"]')[0] ?? ''));

        if ($duz !== '') {
            return match ($duz) {
                '10m' => '10',
                '100m' => '100',
                '1g' => '1000',
                default => null,
            };
        }

        foreach ($node->xpath('.//*[local-name()="ether-options"]/*[local-name()="speed"]/*') ?: [] as $child) {
            $element = $child->getName();

            if ($element === 'auto-negotiation') {
                continue;
            }

            // ethernet-100m, ethernet-1g gibi; Mbps karşılığı config'de tutulur.
            return match ($element) {
                'ethernet-10m' => '10',
                'ethernet-100m' => '100',
                'ethernet-1g' => '1000',
                default => null,
            };
        }

        return null;
    }

    /**
     * Ayarlanmış duplex; satır yoksa cihaz kendi seçiyordur.
     */
    private function linkMode(SimpleXMLElement $node): ?string
    {
        /*
         * İKİ SEVİYE, tek dize değil.
         *
         * text() sorguyu `./*[local-name()="..."]` diye kuruyor ve
         * local-name() asla "/" içermez: "ether-options/link-mode" tek dize
         * olarak hiçbir zaman eşleşmez ve duplex cihazdan HİÇ okunmaz. Panel
         * "Otomatik" gösterir ve bir sonraki kayıt, kullanıcı hiç dokunmadığı
         * hâlde <link-mode delete="delete"/> yazıp cihazdaki ayarı silerdi.
         */
        $value = trim((string) (
            $node->xpath('./*[local-name()="ether-options"]/*[local-name()="link-mode"]')[0] ?? ''
        )) ?: null;

        if ($value === null) {
            return null;
        }

        return array_search($value, (array) config('netconf.link_modes'), true) ?: null;
    }

    private function hasAutoNegotiation(SimpleXMLElement $node): bool
    {
        /*
         * ELS'te auto-negotiation "speed" düğümünün İÇİNDE değil,
         * ether-options'ın doğrudan çocuğudur. İkisine de bakılır.
         */
        return ($node->xpath('.//*[local-name()="ether-options"]/*[local-name()="speed"]/*[local-name()="auto-negotiation"]') ?: []) !== []
            || ($node->xpath('./*[local-name()="ether-options"]/*[local-name()="auto-negotiation"]') ?: []) !== []
            || trim((string) ($node->xpath('./*[local-name()="speed"]')[0] ?? '')) === 'auto';
    }

    /**
     * Sayısal alan; cihaz boş ya da sayı olmayan bir değer verirse null.
     */
    private function wholeNumber(SimpleXMLElement $node, string $element): ?int
    {
        $value = $this->text($node, $element);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath("./*[local-name()=\"{$element}\"]")[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
