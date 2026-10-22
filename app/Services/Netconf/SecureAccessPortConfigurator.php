<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\JunosSchemaProfile;
use App\Models\NetworkSwitch;
use App\Models\SwitchSecureInterface;
use App\Models\SwitchSecureVlan;
use App\Support\Netconf\ResidualXml;

/**
 * DHCP snooping ve port güvenliğini cihaza yazar.
 *
 * Yer: ethernet-switching-options secure-access-port. Cihazda ölçüldü
 * (test1, commit check):
 *
 *   secure-access-port { vlan all { examine-dhcp; } }   → KABUL
 *
 * Sınırların (mac-limit / mac-move-limit) ve allowed-mac'in XML biçimi
 * Juniper belgesine göre yazıldı, ÖLÇÜLMEDİ. Yanlışsa cihaz commit check'te
 * reddeder: kalıcı bir değişiklik olmaz ve hata cihaz işlem kaydına düşer.
 * Ölçmek için: php artisan network:verify-writes test1.
 *
 * ELS'TE BU DÜĞÜM YOKTUR. Juniper ELS'te DHCP snooping'i başka bir yere
 * (forwarding-options dhcp-relay) taşıdı ve orası ayrı bir hiyerarşidir.
 * Cihazda ölçülmemiş bir yol UYDURULMAZ; ELS yazımı aşağıdaki els*
 * yöntemlerinde, test4'te ölçülen biçimle yapılır.
 *
 * DÜĞÜM "replace" İLE YENİDEN KURULUR ve silme markörü YAZILMAZ.
 *
 * Önce her kapatılan bayrak ayrıca delete="delete" ile siliniyordu; cihazda
 * ölçüldü ve bu YANLIŞ çıktı:
 *
 *   <dhcp-trusted/> + <no-dhcp-trusted delete="delete"/>  → syntax error
 *   yalnız <dhcp-trusted/> (replace ile)                  → KABUL
 *
 * Sebebi şu: "dhcp-trusted" ile "no-dhcp-trusted" Junos'ta İKİ ayrı satır
 * değil, TEK bir ifadenin iki değeridir. Birini yazıp diğerini silmek
 * cihaza anlamsız gelir. "replace" zaten yeniden basılmayan her şeyi
 * düşürdüğü için silme markörüne gerek de yoktur.
 */
class SecureAccessPortConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private SecureAccessPortSynchronizer $secure,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyVlan(NetworkSwitch $switch, string $vlanName, array $settings): array
    {
        if (! $this->profile($switch)->usesSecureAccessPort()) {
            return $this->send(
                $switch,
                $this->elsVlanDocument($vlanName, $settings),
                "dhcp snooping vlan: {$vlanName}",
            );
        }

        $protected = ResidualXml::names($settings['residual_xml'] ?? null);

        $body = '<name>'.$this->escape($vlanName).'</name>'
            .$this->flag('examine-dhcp', (bool) ($settings['examine_dhcp'] ?? false), $protected)
            .$this->flag('arp-inspection', (bool) ($settings['arp_inspection'] ?? false), $protected)
            .$this->flag('ip-source-guard', (bool) ($settings['ip_source_guard'] ?? false), $protected)
            .$this->limit('mac-move-limit', $settings['mac_move_limit'] ?? null, $settings['mac_move_action'] ?? null, $protected)
            .ResidualXml::emit($settings['residual_xml'] ?? null);

        return $this->send(
            $switch,
            $this->document("<vlan replace=\"replace\">{$body}</vlan>"),
            "dhcp snooping vlan: {$vlanName}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteVlan(NetworkSwitch $switch, SwitchSecureVlan $vlan): array
    {
        if (! $this->profile($switch)->usesSecureAccessPort()) {
            return $this->send(
                $switch,
                '<configuration><vlans><vlan><name>'.$this->escape($vlan->vlan_name).'</name>'
                .'<forwarding-options><dhcp-security delete="delete"/></forwarding-options>'
                .'<switch-options delete="delete"/>'
                .'</vlan></vlans></configuration>',
                "dhcp snooping vlan silindi: {$vlan->vlan_name}",
            );
        }

        return $this->send(
            $switch,
            $this->document('<vlan delete="delete"><name>'.$this->escape($vlan->vlan_name).'</name></vlan>'),
            "dhcp snooping vlan silindi: {$vlan->vlan_name}",
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function applyInterface(NetworkSwitch $switch, string $interfaceName, array $settings): array
    {
        if (! $this->profile($switch)->usesSecureAccessPort()) {
            return $this->send(
                $switch,
                $this->elsInterfaceDocument($interfaceName, $settings),
                "port guvenligi: {$interfaceName}",
            );
        }

        $protected = ResidualXml::names($settings['residual_xml'] ?? null);

        $body = '<name>'.$this->escape($interfaceName).'</name>'
            .$this->trust($settings['dhcp_trusted'] ?? null, $protected)
            .$this->limit('mac-limit', $settings['mac_limit'] ?? null, $settings['mac_limit_action'] ?? null, $protected)
            .$this->allowedMacs($settings['allowed_macs'] ?? null, $protected)
            .ResidualXml::emit($settings['residual_xml'] ?? null);

        return $this->send(
            $switch,
            $this->document("<interface replace=\"replace\">{$body}</interface>"),
            "port guvenligi: {$interfaceName}",
        );
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function deleteInterface(NetworkSwitch $switch, SwitchSecureInterface $interface): array
    {
        if (! $this->profile($switch)->usesSecureAccessPort()) {
            return $this->send(
                $switch,
                $this->elsInterfaceDeleteDocument($interface),
                "port guvenligi silindi: {$interface->interface_name}",
            );
        }

        return $this->send(
            $switch,
            $this->document('<interface delete="delete"><name>'.$this->escape($interface->interface_name).'</name></interface>'),
            "port guvenligi silindi: {$interface->interface_name}",
        );
    }

    /**
     * Varlığı anlam taşıyan bayrak: açıkken yazılır, kapalıyken silinir.
     *
     * @param  list<string>  $korunan
     */
    private function flag(string $element, bool $enabled, array $protected): string
    {
        /*
         * Kapalıysa HİÇBİR ŞEY yazılmaz. Düğüm "replace" ile yeniden
         * kurulduğu için yazılmayan satır zaten düşer; ayrıca silme markörü
         * göndermek cihazda sözdizimi hatası üretiyordu (ölçüldü).
         */
        return $enabled ? "<{$element}/>" : '';
    }

    /**
     * DHCP güven durumu üç değerlidir; ikisi de yazılabilir bir SATIRDIR.
     *
     * @param  list<string>  $korunan
     */
    private function trust(?bool $trusted, array $protected): string
    {
        /*
         * ÜÇ DEĞERLİ ama TEK satır: ya "dhcp-trusted" ya "no-dhcp-trusted"
         * ya da hiçbiri (cihazın varsayılanı). İkisini birden göndermek —
         * biri yazıp diğerini silmek dâhil — cihazda sözdizimi hatası
         * veriyor; ölçüldü.
         */
        return match ($trusted) {
            true => '<dhcp-trusted/>',
            false => '<no-dhcp-trusted/>',
            default => '',
        };
    }

    /**
     * "<sınır> <n> action <...>" satırı.
     *
     * Eylem verilmezse yazılmaz: Junos'un kendi varsayılanı geçerli olur ve
     * panel cihaza kurmadığı bir davranış yazmaz.
     *
     * @param  list<string>  $korunan
     */
    private function limit(string $element, ?int $limit, ?string $action, array $protected): string
    {
        // "replace" yazılmayanı zaten düşürür; silme markörü gereksiz ve
        // bazı ifadelerde cihazca reddediliyor (bkz. sınıf açıklaması).
        if ($limit === null) {
            return '';
        }

        $body = "<limit>{$limit}</limit>";

        if ($action !== null) {
            $body .= '<action>'.$this->escape($action).'</action>';
        }

        return "<{$element}>{$body}</{$element}>";
    }

    /**
     * allowed-mac [ ... ] leaf-list'i: Junos köşeli parantezli listeleri
     * yinelenen elemanlarla yazar.
     *
     * @param  list<string>|null  $macs
     * @param  list<string>  $korunan
     */
    private function allowedMacs(?array $macs, array $protected): string
    {
        if ($macs === null || $macs === []) {
            return '';
        }

        return implode('', array_map(
            fn (string $mac): string => '<allowed-mac>'.$this->escape($mac).'</allowed-mac>',
            $macs,
        ));
    }

    /**
     * ELS'te tek bir "secure-access-port" düğümü YOKTUR; VLAN ayarları iki
     * ayrı hiyerarşiye dağılır (test4, EX2300-24T, 24.4R2-S4'te ölçüldü):
     *
     *   vlans <v> forwarding-options dhcp-security   snooping, arp, source guard
     *   vlans <v> switch-options                      mac-move-limit
     *
     * ANLAM DA TERSİNE DÖNER: ELS öncesinde "examine-dhcp" snooping'i AÇAR;
     * ELS'te düğümün varlığı snooping'i zaten açar ve kapatmak için
     * "no-dhcp-snooping" yazılır. Bunu görmeden yazılan bir panel, kapalı
     * demek isterken açık bırakırdı.
     *
     * mac-move-limit'in ELS'te EYLEM siblingi yoktur (cihazda syntax error);
     * o alan yazılmaz.
     *
     * @param  array<string, mixed>  $settings
     */
    private function elsVlanDocument(string $vlanName, array $settings): string
    {
        $security = ((bool) ($settings['examine_dhcp'] ?? false)) ? '' : '<no-dhcp-snooping/>';
        $security .= ((bool) ($settings['arp_inspection'] ?? false)) ? '<arp-inspection/>' : '';
        $security .= ((bool) ($settings['ip_source_guard'] ?? false)) ? '<ip-source-guard/>' : '';

        $limit = $settings['mac_move_limit'] ?? null;
        $key = $limit === null ? '' : '<mac-move-limit>'.((int) $limit).'</mac-move-limit>';

        return '<configuration><vlans><vlan><name>'.$this->escape($vlanName).'</name>'
            .'<forwarding-options><dhcp-security replace="replace">'.$security.'</dhcp-security></forwarding-options>'
            .'<switch-options replace="replace">'.$key.'</switch-options>'
            .'</vlan></vlans></configuration>';
    }

    /**
     * ELS'te port güvenliği "switch-options interface <ad>" altındadır.
     *
     * dhcp-trusted ve allowed-mac buraya YAZILAMAZ: ikisi de ELS'te VLAN
     * kapsamındadır (dhcp-security group / switch-options static-mac) ve
     * panelin switch geneli modeline oturmaz. İstek doğrulaması onları
     * ELS cihazda zaten reddeder; burada da yazılmaz.
     *
     * @param  array<string, mixed>  $settings
     */
    private function elsInterfaceDocument(string $interfaceName, array $settings): string
    {
        $limit = $settings['mac_limit'] ?? null;
        $body = '';

        if ($limit !== null) {
            $action = $settings['mac_limit_action'] ?? null;

            $body = '<interface-mac-limit><limit>'.((int) $limit).'</limit>'
                .($action === null ? '' : '<packet-action>'.$this->escape((string) $action).'</packet-action>')
                .'</interface-mac-limit>';
        }

        return '<configuration><switch-options><interface replace="replace">'
            .'<name>'.$this->escape($interfaceName).'</name>'.$body
            .'</interface></switch-options>'
            .$this->elsVlanScopedElement($interfaceName, $settings)
            .'</configuration>';
    }

    /**
     * ELS'te bir port güvenliği satırını silmek ÜÇ yeri birden temizler:
     * switch-options arayüzü, VLAN'daki güven grubu ve VLAN'daki statik MAC
     * listesi. Yalnızca ilki silinseydi geride panelde görünmeyen ama cihazda
     * duran bir güven satırı kalırdı.
     */
    private function elsInterfaceDeleteDocument(SwitchSecureInterface $interface): string
    {
        $escapedInterface = $this->escape($interface->interface_name);
        $body = '<switch-options><interface delete="delete"><name>'.$escapedInterface.'</name></interface></switch-options>';

        if (filled($interface->vlan_name)) {
            $name = $this->escape((string) $interface->vlan_name);
            $group = $this->escape($this->elsTrustGroup($interface->interface_name));

            $body .= "<vlans><vlan><name>{$name}</name>"
                .'<forwarding-options><dhcp-security>'
                ."<group delete=\"delete\"><name>{$group}</name></group>"
                .'</dhcp-security></forwarding-options>'
                ."<switch-options><interface delete=\"delete\"><name>{$escapedInterface}</name></interface></switch-options>"
                .'</vlan></vlans>';
        }

        return "<configuration>{$body}</configuration>";
    }

    /**
     * ELS'te VLAN KAPSAMINDA yaşayan iki alan.
     *
     *   dhcp-trusted → vlans <v> forwarding-options dhcp-security
     *                    group <g> { overrides trusted; interface <if>; }
     *   allowed-mac  → vlans <v> switch-options interface <if> static-mac <mac>
     *
     * Grup ARAYÜZ BAŞINA açılır ve adı arayüzden türetilir. Tek bir ortak
     * gruba yazılsaydı, bir portun güvenini kaldırmak grubu "replace" ile
     * yeniden kurup ORADAKİ DİĞER PORTLARI da düşürürdü; her satır kendi
     * grubuna sahip olunca silme de yazma da o satıra kalır.
     *
     * ELS'te "açıkça güvenilmez" diye bir işaret YOKTUR (no-dhcp-trusted
     * sözdizimi hatası veriyor): güven ya gruptadır ya değildir. Bu yüzden
     * hem false hem null aynı sonucu verir — grup silinir.
     *
     * @param  array<string, mixed>  $settings
     */
    private function elsVlanScopedElement(string $interfaceName, array $settings): string
    {
        $vlan = $settings['vlan_name'] ?? null;

        if (! filled($vlan)) {
            return '';
        }

        $name = $this->escape((string) $vlan);
        $interface = $this->escape($interfaceName);
        $group = $this->escape($this->elsTrustGroup($interfaceName));

        $trust = ($settings['dhcp_trusted'] ?? null) === true
            ? "<group replace=\"replace\"><name>{$group}</name><overrides><trusted/></overrides>"
                ."<interface><name>{$interface}</name></interface></group>"
            : "<group delete=\"delete\"><name>{$group}</name></group>";

        $macs = '';

        foreach ($settings['allowed_macs'] ?? [] as $mac) {
            $macs .= '<static-mac><name>'.$this->escape((string) $mac).'</name></static-mac>';
        }

        return "<vlans><vlan><name>{$name}</name>"
            ."<forwarding-options><dhcp-security>{$trust}</dhcp-security></forwarding-options>"
            ."<switch-options><interface replace=\"replace\"><name>{$interface}</name>{$macs}</interface></switch-options>"
            .'</vlan></vlans>';
    }

    /**
     * Arayüzün kendi güven grubunun adı: "guven-ge-0-0-5-0".
     *
     * Junos grup adında "/" ve "." kabul etmiyor; ayraçlar tireye çevrilir.
     */
    private function elsTrustGroup(string $interfaceName): string
    {
        return 'guven-'.str_replace(['/', '.'], '-', $interfaceName);
    }

    private function profile(NetworkSwitch $switch): JunosSchemaProfile
    {
        return $switch->schema_profile ?? JunosSchemaProfile::PreEls;
    }

    private function document(string $body): string
    {
        return '<configuration><ethernet-switching-options><secure-access-port>'
            .$body
            .'</secure-access-port></ethernet-switching-options></configuration>';
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function send(NetworkSwitch $switch, string $document, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            [$document],
            $summary,
            fn (NetconfSession $session) => $this->secure->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
