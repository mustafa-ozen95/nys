<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use App\Models\NetworkSwitch;
use App\Models\SwitchIpAddress;
use Closure;

/**
 * Arayüz adreslerini cihaza yazar.
 *
 * Cihaz aynı arayüzde hem ethernet-switching hem inet ailesini kabul etmiyor:
 * "When ethernet-switching family is configured on an interface, no other
 * family type can be configured on the same interface." Bu yüzden bir porta
 * adres vermek, o portu ROUTED hâle getirmek ve VLAN üyeliğini düşürmek
 * demektir. VLAN'ın L3 arayüzüne (vlan.100 / irb.100) adres vermek böyle bir
 * yan etki taşımaz ve normal kullanım budur.
 *
 * İKİ AİLE VARDIR. Adresin ailesi adresin KENDİSİNDEN çıkarılır; "inet" sabit
 * yazılırsa cihazdan okunan bir IPv6 adresi düzenlendiğinde yükleme yanlış
 * ailenin altına gider.
 *
 * "SON ADRES Mİ" SORUSU CİHAZA SORULUR. Envanter sayımı bayattır: CLI'dan
 * eklenmiş ikinci bir adres panelde görünmez, ailesi de ayrılmazdı. Aileyi
 * silmek o adresi de süpürürdü — panelin hiç bilmediği bir adresi. Bu yüzden
 * silme belgesi yazma oturumunun içinde, cihazın o anki yapılandırması
 * okunarak üretilir.
 */
class IpAddressConfigurator
{
    public function __construct(
        private ConfigurationWriter $writer,
        private IpAddressSynchronizer $addresses,
        private IpAddressReader $reader,
    ) {}

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function add(NetworkSwitch $switch, string $interfaceName, string $cidr): array
    {
        [$parent, $unit] = $this->split($interfaceName);
        $documents = [];

        // Fiziksel porta adres veriliyorsa önce anahtarlama ailesi kalkmalı.
        if ($this->isSwitchPort($parent)) {
            $documents[] = $this->document($parent, $unit, '<family><ethernet-switching delete="delete"/></family>');
        }

        $documents[] = $this->document($parent, $unit, $this->addressElement($cidr));

        return $this->apply($switch, $documents, "ip eklendi: {$interfaceName} {$cidr}");
    }

    /**
     * Adres değişikliği tek commit'tir: eskisi silinir, yenisi aynı yüklemede
     * yazılır. İkiye bölünürse arayüz bir an adressiz kalırdı.
     *
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function replace(
        NetworkSwitch $switch,
        SwitchIpAddress $address,
        string $interfaceName,
        string $cidr,
    ): array {
        [$parent, $unit] = $this->split($interfaceName);

        $documents = function (NetconfSession $session) use ($address, $interfaceName, $parent, $unit, $cidr): array {
            /*
             * Adres BAŞKA bir arayüze taşınıyorsa eskisinde aile de kalkar;
             * yoksa geride boş bir "family inet;" kalır ve o arayüz cihazın
             * gözünde hâlâ routed'dır. Aynı arayüzde kalıyorsa aile duracak:
             * yeni adres zaten onun altına yazılıyor.
             */
            $documents = [
                $interfaceName === $address->interface_name
                    ? $this->deleteAddressDocument($address)
                    : $this->deleteDocument($session, $address),
            ];

            if ($this->isSwitchPort($parent)) {
                $documents[] = $this->document($parent, $unit, '<family><ethernet-switching delete="delete"/></family>');
            }

            $documents[] = $this->document($parent, $unit, $this->addressElement($cidr));

            return $documents;
        };

        return $this->apply($switch, $documents, "ip degistirildi: {$interfaceName} {$cidr}");
    }

    /**
     * @return array{diff: string, committed: bool, warnings: list<string>}
     *
     * @throws NetconfException
     */
    public function remove(NetworkSwitch $switch, SwitchIpAddress $address): array
    {
        return $this->apply(
            $switch,
            fn (NetconfSession $session): array => [$this->deleteDocument($session, $address)],
            "ip silindi: {$address->interface_name} {$address->cidr}",
        );
    }

    /**
     * Adresi siler; o ailenin CİHAZDAKİ son adresiyse aileyi de kaldırır.
     *
     * Yalnızca adres silinirse geride boş bir "family inet;" satırı kalır ve
     * her silme bir iz bırakır. Ama aile ancak gerçekten son adresse
     * kaldırılabilir: cihazda panelin bilmediği ikinci bir adres varsa aileyi
     * silmek onu da süpürürdü.
     *
     * @throws NetconfException
     */
    private function deleteDocument(NetconfSession $session, SwitchIpAddress $address): string
    {
        [$parent, $unit] = $this->split($address->interface_name);
        $family = $this->familyOf($address->address);

        $body = $this->familyHoldsOnly($session, $address, $family)
            ? "<family><{$family->value} delete=\"delete\"/></family>"
            : $this->deleteAddressElement($address->cidr, $family);

        return $this->document($parent, $unit, $body);
    }

    /**
     * Aileyi bırakıp yalnızca adresi siler.
     */
    private function deleteAddressDocument(SwitchIpAddress $address): string
    {
        [$parent, $unit] = $this->split($address->interface_name);

        return $this->document(
            $parent,
            $unit,
            $this->deleteAddressElement($address->cidr, $this->familyOf($address->address)),
        );
    }

    /**
     * Ailenin altında bu adresten BAŞKA hiçbir şey yok mu?
     *
     * İki şeyi birden sorar ve ikisi de cihaza sorulur:
     *
     * - Başka ADRES var mı? Envanter sayımı bunu yanlış biliyordu: CLI'dan
     *   eklenmiş ikinci bir adres panelde görünmez ve aileyi silmek onu da
     *   süpürürdü.
     * - Adres DIŞINDA bir şey var mı? "family inet delete" filtreyi, mtu'yu,
     *   sampling'i de siler. Panel bunları okumuyor; okumadığı bir satırı
     *   silmesi kabul edilemez.
     *
     * @throws NetconfException
     */
    private function familyHoldsOnly(NetconfSession $session, SwitchIpAddress $address, IpFamily $family): bool
    {
        $contents = $this->reader->familyContents($session, $address->interface_name, $family);

        return $contents !== null
            && $contents['others'] === []
            && $contents['addresses'] === [$address->cidr];
    }

    /**
     * Adresin ailesi adresin kendisinden gelir; envanterdeki alandan değil.
     * Envanter alanı bayat olabilir, adres dizesi olamaz.
     */
    private function familyOf(string $address): IpFamily
    {
        return str_contains($address, ':') ? IpFamily::Inet6 : IpFamily::Inet;
    }

    private function addressElement(string $cidr): string
    {
        $family = $this->familyOf($cidr);

        return "<family><{$family->value}><address><name>".$this->escape($cidr)
            ."</name></address></{$family->value}></family>";
    }

    private function deleteAddressElement(string $cidr, IpFamily $family): string
    {
        return "<family><{$family->value}><address delete=\"delete\"><name>".$this->escape($cidr)
            ."</name></address></{$family->value}></family>";
    }

    /**
     * ANAHTARLANABİLİR bir port mu?
     *
     * Yalnızca bunlarda "ethernet-switching" ailesi olabilir ve adres
     * verilirken kaldırılması gerekir. Soru olumlu sorulur: "L3 arayüzü
     * değilse porttur" varsayımı lo0'ı da port sayıyordu ve loopback'e adres
     * verirken cihaza olmayan bir aileyi silme isteği gidiyordu.
     */
    private function isSwitchPort(string $parent): bool
    {
        return preg_match('/^(ge|xe|et|mge|xle)-\d+\/\d+\/\d+$/', $parent) === 1
            || preg_match('/^ae\d+$/', $parent) === 1;
    }

    /**
     * "vlan.100" -> ["vlan", "100"]; birim yoksa 0 varsayılır.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $interfaceName): array
    {
        if (! str_contains($interfaceName, '.')) {
            return [$interfaceName, '0'];
        }

        $fragments = explode('.', $interfaceName);
        $unit = array_pop($fragments);

        return [implode('.', $fragments), $unit];
    }

    private function document(string $parent, string $unit, string $body): string
    {
        return '<configuration><interfaces><interface><name>'.$this->escape($parent).'</name>'
            .'<unit><name>'.$this->escape($unit).'</name>'.$body.'</unit>'
            .'</interface></interfaces></configuration>';
    }

    /**
     * @param  list<string>|Closure(NetconfSession): list<string>  $documents
     * @return array{diff: string, committed: bool, warnings: list<string>}
     */
    private function apply(NetworkSwitch $switch, array|Closure $documents, string $summary): array
    {
        return $this->writer->apply(
            $switch,
            $documents,
            $summary,
            fn (NetconfSession $session) => $this->addresses->sync($switch, $session),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }
}
