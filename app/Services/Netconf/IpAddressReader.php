<?php

declare(strict_types=1);

namespace App\Services\Netconf;

use App\Enums\IpFamily;
use SimpleXMLElement;

/**
 * Arayüzlere tanımlı IP adreslerini yapılandırmadan okur.
 *
 * Adres genellikle bir VLAN'ın L3 arayüzündedir (vlan.100); doğrudan porta
 * tanımlı adres ancak port routed ise olur — cihaz aynı arayüzde hem
 * ethernet-switching hem inet ailesini kabul etmiyor.
 */
class IpAddressReader
{
    /**
     * Cihazın arayüzlerindeki adresler.
     *
     * "interfaces" sayısı da döner ve bu ayrım envanteri korur: SIFIR ADRES
     * gerçek bir durumdur (son adres silindiğinde olur), sıfır ARAYÜZ ise
     * kullanılamayacak bir yanıttır. İkisi ayrılmazsa ya bozuk yanıtta
     * envanter silinir ya da son adres silindiğinde hayalet kayıt kalır.
     *
     * @return array{interfaces: int, addresses: list<array{interface_name: string, address: string, prefix_length: int, family: IpFamily}>}
     *
     * @throws NetconfException
     */
    public function addresses(NetconfSession $session): array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><interfaces/></configuration>'
            .'</get-configuration>'
        );

        $addresses = [];
        $interfaces = $reply->xpath('//*[local-name()="interfaces"]/*[local-name()="interface"]') ?: [];

        foreach ($interfaces as $interface) {
            $parent = $this->text($interface, 'name');

            if ($parent === null) {
                continue;
            }

            foreach ($interface->xpath('./*[local-name()="unit"]') ?: [] as $unit) {
                $number = $this->text($unit, 'name');

                if ($number === null) {
                    continue;
                }

                foreach ($this->unitAddresses($unit) as $address) {
                    $addresses[] = ['interface_name' => "{$parent}.{$number}"] + $address;
                }
            }
        }

        return ['interfaces' => count($interfaces), 'addresses' => $addresses];
    }

    /**
     * Bir arayüz biriminde o ailenin ALTINDA NE VAR.
     *
     * Silme kararı buna dayanır. "family inet delete" yalnızca adresleri
     * değil, o ailenin altındaki HER ŞEYİ siler: giriş/çıkış filtresi, mtu,
     * sampling, rpf-check... Panel bunları okumuyor, dolayısıyla yazarken de
     * yok sayamaz — sildiğini bile söyleyemezdi.
     *
     * Bu yüzden aile ancak "içinde bu adresten başka hiçbir şey yok" ise
     * kaldırılır; aksi hâlde sadece adres silinir ve geride boş bir aile
     * kalması göze alınır.
     *
     * @return array{addresses: list<string>, others: list<string>}|null
     *                                                                   Aile tanımlı değilse null
     *
     * @throws NetconfException
     */
    public function familyContents(NetconfSession $session, string $interfaceName, IpFamily $family): ?array
    {
        $reply = $session->rpc(
            '<get-configuration database="committed">'
            .'<configuration><interfaces/></configuration>'
            .'</get-configuration>'
        );

        [$parent, $unit] = $this->split($interfaceName);

        $nodes = $reply->xpath(
            '//*[local-name()="interfaces"]/*[local-name()="interface"]'
            ."[./*[local-name()=\"name\"]=\"{$parent}\"]"
            .'/*[local-name()="unit"]'
            ."[./*[local-name()=\"name\"]=\"{$unit}\"]"
            .'/*[local-name()="family"]'
            ."/*[local-name()=\"{$family->value}\"]"
        ) ?: [];

        if ($nodes === []) {
            return null;
        }

        $addresses = [];
        $others = [];

        foreach ($nodes[0]->children() as $child) {
            $name = $child->getName();

            if ($name === 'address') {
                $addresses[] = trim((string) ($child->xpath('./*[local-name()="name"]')[0] ?? ''));

                continue;
            }

            $others[] = $name;
        }

        return ['addresses' => $addresses, 'others' => array_values(array_unique($others))];
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

        $parcalar = explode('.', $interfaceName);
        $unit = array_pop($parcalar);

        return [implode('.', $parcalar), $unit];
    }

    /**
     * @return list<array{address: string, prefix_length: int, family: IpFamily}>
     */
    private function unitAddresses(SimpleXMLElement $unit): array
    {
        $addresses = [];

        foreach (['inet' => IpFamily::Inet, 'inet6' => IpFamily::Inet6] as $element => $family) {
            $path = ".//*[local-name()=\"{$element}\"]/*[local-name()=\"address\"]/*[local-name()=\"name\"]";

            foreach ($unit->xpath($path) ?: [] as $node) {
                $cidr = trim((string) $node);

                if (! str_contains($cidr, '/')) {
                    continue;
                }

                [$address, $length] = explode('/', $cidr, 2);

                $addresses[] = [
                    'address' => $address,
                    'prefix_length' => (int) $length,
                    'family' => $family,
                ];
            }
        }

        return $addresses;
    }

    private function text(SimpleXMLElement $node, string $element): ?string
    {
        $value = trim((string) ($node->xpath("./*[local-name()=\"{$element}\"]")[0] ?? ''));

        return $value === '' ? null : $value;
    }
}
