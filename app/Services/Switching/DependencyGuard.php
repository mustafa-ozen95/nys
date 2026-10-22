<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Models\AddressBlock;
use App\Models\NetworkSwitch;
use App\Models\SwitchAsPath;
use App\Models\SwitchCommunity;
use App\Models\SwitchFirewallPolicer;
use App\Models\SwitchPolicyStatement;
use App\Models\SwitchPolicyTerm;
use App\Models\SwitchPrefixList;
use App\Models\Vlan;

/**
 * Bir nesneyi silmek başka bir nesneyi bozuyor mu?
 *
 * Junos silmeye izin verir ama commit'i reddeder: "prefix-list referenced
 * (in term A) but not defined", "Policy gelen referenced but not defined |
 * BGP: import list not applied". Bunlar jn32-test üzerinde gerçekten görüldü.
 *
 * Geri alınabilir commit sayesinde cihazda kalıcı bir bozulma olmaz, ama
 * kullanıcı cihazın cümlesiyle karşılaşır ve neyin bağlı olduğunu tek tek
 * aramak zorunda kalır. Panel bunu ÖNCEDEN söyler: neyin bağlı olduğunu
 * adıyla verir ve isteği hiç cihaza götürmez.
 *
 * Yalnızca panelin BİLDİĞİ bağımlılıklar kontrol edilir. Cihazda elle
 * kurulmuş bir atıf buradan görünmez; onu yakalayan yine commit check'tir ve
 * mesajı kullanıcıya olduğu gibi gösterilir.
 */
class DependencyGuard
{
    /**
     * Prefix listeye bağlı olanlar: politika terimleri, firewall terimleri ve
     * o listeye anons edilen adres blokları.
     *
     * @return list<string>
     */
    public function forPrefixList(SwitchPrefixList $list): array
    {
        $switch = $list->switch;
        $bound = [];

        foreach ($switch->policyStatements()->with('terms')->get() as $statement) {
            foreach ($statement->terms as $term) {
                if (in_array($list->name, $term->match_prefix_lists ?? [], true)) {
                    $bound[] = "politika: {$statement->name} ({$term->name})";
                }
            }
        }

        foreach ($switch->firewallFilters()->with('terms')->get() as $filter) {
            foreach ($filter->terms as $term) {
                $lists = [...($term->source_prefix_lists ?? []), ...($term->destination_prefix_lists ?? [])];

                if (in_array($list->name, $lists, true)) {
                    $bound[] = "firewall: {$filter->name} ({$term->name})";
                }
            }
        }

        $block = $list->addressBlocks()->count();

        if ($block > 0) {
            $bound[] = "adresleme: {$block} blok bu listeye anons ediliyor";
        }

        return array_values(array_unique($bound));
    }

    /**
     * Policer'a bağlı olanlar: onu çağıran firewall terimleri.
     *
     * Tanımsız policer'a atıf commit'i düşürür ("policer referenced but not
     * defined"); prefix list'teki kuralın aynısı.
     *
     * @return list<string>
     */
    public function forPolicer(SwitchFirewallPolicer $policer): array
    {
        $bound = [];

        foreach ($policer->switch->firewallFilters()->with('terms')->get() as $filter) {
            foreach ($filter->terms as $term) {
                if ($term->policer === $policer->name) {
                    $bound[] = "firewall: {$filter->name} ({$term->name})";
                }
            }
        }

        return array_values(array_unique($bound));
    }

    /**
     * Bir prefix listeyi kullanan FIREWALL FİLTRELERİNİN aileleri.
     *
     * Junos'ta bir prefix listenin ailesi, KULLANILDIĞI yere göre belirlenir:
     * "family inet" bir filtreden atıf alan liste yalnızca IPv4 prefix
     * taşıyabilir. Cihaz aksini "Policy: invalid prefix 2001:db8::/32 for
     * family inet" ile reddediyor — test1'de ölçüldü.
     *
     * Bağımsız (hiç kullanılmayan) bir liste karışık taşıyabilir; cihaz onu
     * kabul ediyor. Bu yüzden kural "IPv6 yasak" değil, "kullanıldığı aileyle
     * uyumlu olmalı"dır.
     *
     * @return list<string> Kullanan filtrelerin aile değerleri
     */
    public function prefixListFamilies(SwitchPrefixList $list): array
    {
        $families = [];

        foreach ($list->switch->firewallFilters()->with('terms')->get() as $filter) {
            foreach ($filter->terms as $term) {
                $lists = [...($term->source_prefix_lists ?? []), ...($term->destination_prefix_lists ?? [])];

                if (in_array($list->name, $lists, true)) {
                    $families[] = is_string($filter->family) ? $filter->family : $filter->family->value;
                }
            }
        }

        return array_values(array_unique($families));
    }

    /**
     * Politikaya bağlı olanlar: onu içeri alan ya da dışarı veren BGP grupları
     * ve komşuları.
     *
     * @return list<string>
     */
    public function forPolicy(SwitchPolicyStatement $statement): array
    {
        $switch = $statement->switch;
        $bound = [];

        foreach ($switch->bgpGroups()->with('neighbors')->get() as $group) {
            if ($this->uses($group->import_policies, $group->export_policies, $statement->name)) {
                $bound[] = "bgp grubu: {$group->name}";
            }

            foreach ($group->neighbors as $neighbor) {
                if ($this->uses($neighbor->import_policies, $neighbor->export_policies, $statement->name)) {
                    $bound[] = "bgp komşusu: {$neighbor->address}";
                }
            }
        }

        return array_values(array_unique($bound));
    }

    /**
     * Community'ye bağlı olanlar: ona dokunan politika terimleri.
     *
     * Yalnızca "community delete" değil, "community add/set" ve
     * "from community" da sayılır: terim modeli genişledikten sonra tek
     * sütuna bakmak, kullanılan bir tanımı silinebilir gösteriyordu.
     *
     * @return list<string>
     */
    public function forCommunity(SwitchCommunity $community): array
    {
        return $this->referencingTerms(
            $community->switch,
            fn (SwitchPolicyTerm $term): bool => in_array($community->name, $term->referenced_communities, true),
        );
    }

    /**
     * As-path'e bağlı olanlar: "from as-path <ad>" diyen politika terimleri.
     *
     * @return list<string>
     */
    public function forAsPath(SwitchAsPath $asPath): array
    {
        return $this->referencingTerms(
            $asPath->switch,
            fn (SwitchPolicyTerm $term): bool => in_array($asPath->name, $term->referenced_as_paths, true),
        );
    }

    /**
     * @param  callable(SwitchPolicyTerm): bool  $uses
     * @return list<string>
     */
    private function referencingTerms(NetworkSwitch $switch, callable $uses): array
    {
        $bound = [];

        foreach ($switch->policyStatements()->with('terms')->get() as $statement) {
            foreach ($statement->terms as $term) {
                if ($uses($term)) {
                    $bound[] = "politika: {$statement->name} ({$term->name})";
                }
            }
        }

        return array_values(array_unique($bound));
    }

    /**
     * VLAN'a bağlı olanlar: L3 arayüzünün üstündeki IP adresleri.
     *
     * VLAN silinince L3 arayüzü de gider (yoksa hiçbir VLAN'a bağlı olmayan
     * öksüz bir arayüz kalırdı) ve arayüzle birlikte üstündeki adresler de
     * silinir. Adres silmek ayrı bir karardır ve ayrı bir yetki ister;
     * VLAN silme onu SESSİZCE yapmamalı.
     *
     * @return list<string>
     */
    public function forVlan(Vlan $vlan): array
    {
        if ($vlan->l3_interface === null) {
            return [];
        }

        return $vlan->switch->ipAddresses()
            ->where('interface_name', $vlan->l3_interface)
            ->get()
            ->map(fn ($address): string => "ip adresi: {$address->cidr} ({$vlan->l3_interface})")
            ->values()
            ->all();
    }

    /**
     * VLAN'a bağlanmış adres blokları.
     *
     * Blok VLAN'ı kayıtla işaret ediyor; VLAN silinirse bağ da düşer ve blok
     * "atanmamış"a döner. Bu sessizce olmamalı: kullanıcı VLAN'ı silerken
     * adresleme envanterindeki atamayı da bozduğunu görmeli.
     *
     * @return list<string>
     */
    public function blocksForVlan(Vlan $vlan): array
    {
        return AddressBlock::query()
            ->where('vlan_id', $vlan->id)
            ->get()
            ->map(fn (AddressBlock $block): string => "adres bloğu: {$block->cidr}")
            ->values()
            ->all();
    }

    /**
     * Kullanıcıya gösterilecek cümle.
     *
     * @param  list<string>  $dependents
     */
    public function message(string $what, array $dependents): string
    {
        return "{$what} silinemez: hâlâ kullanılıyor — ".implode(', ', $dependents)
            .'. Önce bu bağlantılar kaldırılmalı; cihaz aksi hâlde commit’i reddediyor.';
    }

    /**
     * @param  ?list<string>  $import
     * @param  ?list<string>  $export
     */
    private function uses(?array $import, ?array $export, string $name): bool
    {
        return in_array($name, $import ?? [], true) || in_array($name, $export ?? [], true);
    }
}
