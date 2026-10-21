<?php

declare(strict_types=1);

namespace App\Services\Bgp;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\IpFamily;
use App\Models\AddressBlock;
use App\Models\BgpNeighbor;
use App\Models\SwitchPrefixList;

/**
 * Karşı tarafın (müşterinin) BGP'yi kurmak için ihtiyaç duyduğu Junos
 * komutlarını üretir.
 *
 * Üretilen blok configure modunda KOPYALA-YAPIŞTIR ile çalışmalıdır; bu
 * yüzden içinde yalnızca "set" satırları bulunur. Açıklamalar ayrı döner ve
 * ekranda kutunun dışında gösterilir — Junos configure modu yorum satırı
 * kabul etmez, araya karışan tek bir açıklama bütün yapıştırmayı bozar.
 *
 * Yapılandırma bizim tarafın AYNASIDIR:
 *
 * | Bizde | Onlarda |
 * | --- | --- |
 * | komşu adresi | local-address |
 * | yerel adres | neighbor |
 * | kendi AS'imiz | peer-as |
 * | komşunun AS'i | routing-options autonomous-system |
 *
 * Uydurma yapılmaz: bilinmeyen bir değer varsa o satır hiç üretilmez ve
 * eksik olduğu not olarak söylenir. Yanlış bir satır, eksik bir satırdan
 * daha zararlıdır — müşteri onu fark etmeden uygular.
 */
class PeerConfigurationBuilder
{
    /**
     * @return array{
     *     lines: list<string>,
     *     interface: list<string>,
     *     notes: list<string>,
     *     complete: bool
     * }
     */
    public function forNeighbor(BgpNeighbor $neighbor): array
    {
        $neighbor->loadMissing(['group.families', 'switch']);

        $group = $neighbor->group;
        $peerAddress = $neighbor->address;
        $ourAddress = $neighbor->local_address ?? $group->local_address;
        $ourAsn = $group->local_asn ?? $neighbor->switch->autonomous_system;
        $theirAsn = $neighbor->effective_peer_asn;

        $notes = [];
        $lines = [];

        if ($ourAddress === null) {
            $notes[] = 'Bizim taraftaki adres tanımlı değil; grup ya da komşu üzerinde "yerel adres" doldurulmalı.';
        }

        if ($ourAsn === null) {
            $notes[] = 'Switchin AS numarası tanımlı değil.';
        }

        if ($theirAsn === null) {
            $notes[] = 'Karşı tarafın AS numarası tanımlı değil.';
        }

        $groupName = $this->groupName($neighbor);

        if ($theirAsn !== null) {
            $lines[] = "set routing-options autonomous-system {$theirAsn}";
        }

        $lines[] = "set protocols bgp group {$groupName} type ".$group->type->value;

        if ($ourAsn !== null) {
            $lines[] = "set protocols bgp group {$groupName} peer-as {$ourAsn}";
        }

        $lines[] = "set protocols bgp group {$groupName} local-address {$peerAddress}";

        /*
         * Ayarlar KOMŞU seviyesinden okunur: Junos'ta komşudaki değer
         * grubunkini ezer. Yalnızca gruba bakılsaydı, komşuya özel açılmış
         * bir multihop karşı tarafın configinde görünmez ve oturum tek
         * taraflı kalırdı.
         */
        if ($neighbor->effective_multihop) {
            $ttl = $neighbor->effective_multihop_ttl;

            $lines[] = $ttl === null
                ? "set protocols bgp group {$groupName} multihop"
                : "set protocols bgp group {$groupName} multihop ttl {$ttl}";
        }

        // Bizde passive ise karşı taraf BAŞLATMALI; ayna değil, tersidir.
        if ($neighbor->effective_passive) {
            $notes[] = 'Bizim taraf pasif: oturumu karşı taraf başlatacak, onlarda "passive" YAZILMAMALI.';
        }

        // Zamanlayıcı: BGP küçük değeri seçer ama iki taraf da bilerek aynı
        // değeri yazsın diye üretilir.
        if ($neighbor->effective_hold_time !== null) {
            $lines[] = "set protocols bgp group {$groupName} hold-time {$neighbor->effective_hold_time}";
        }

        /*
         * BFD TEK TARAFLI çalışmaz: karşı tarafta da açılmazsa oturum
         * kurulur ama BFD hiç kalkmaz ve kimse fark etmez.
         */
        if ($group->bfd_minimum_interval !== null) {
            $lines[] = "set protocols bgp group {$groupName} bfd-liveness-detection minimum-interval "
                .$group->bfd_minimum_interval;
        }

        /*
         * Adres ailesi yalnızca AÇIKÇA tanımlıysa yazılır. Junos'un
         * varsayılanı "inet unicast"tır; onu yazmak gereksizdir ama inet6
         * taşıyan bir oturumda satır yoksa v6 prefixleri hiç taşınmaz.
         */
        foreach ($group->families as $family) {
            $lines[] = "set protocols bgp group {$groupName} family {$family->family->value} "
                .$family->nlri->value;
        }

        /*
         * MD5 anahtarı iki tarafta AYNI olmalı, yoksa oturum hiç kurulmaz.
         * Değeri buraya YAZILMAZ: cihazdan okunan biçim ($9$...) çözülebilir
         * ve bu metin karşı tarafa gönderiliyor.
         */
        if ($neighbor->effective_authentication_key !== null) {
            $notes[] = 'Bu oturumda MD5 kimlik doğrulaması var. Karşı tarafta da '
                .'"set protocols bgp group ... authentication-key" ile AYNI anahtar tanımlanmalı; '
                .'anahtar güvenlik gereği bu metne konmaz.';
        }

        $lines[] = "set protocols bgp group {$groupName} log-updown";

        $export = $this->exportPolicy($neighbor, $groupName, $notes);
        $lines = [...$lines, ...$export];

        if ($ourAddress !== null) {
            $lines[] = "set protocols bgp group {$groupName} neighbor {$ourAddress}";
        }

        return [
            'lines' => $lines,
            'interface' => $this->interfaceLines($neighbor),
            'notes' => $notes,
            'complete' => $ourAddress !== null && $ourAsn !== null && $theirAsn !== null,
        ];
    }

    /**
     * BİZİM taraftaki config: bu oturumun panelde duran hâli, Junos "set"
     * satırları olarak.
     *
     * Cihaza yazmak için değildir — yazmayı panel kendisi yapar. İki işe
     * yarar: kaydı gözden geçirmek ve gerektiğinde başka bir cihaza aynı
     * oturumu kurmak. Müşteri configinin yanında durur ki iki taraf
     * karşılaştırılabilsin.
     *
     * Oturum anahtarı YAZILMAZ, yerine tutucu konur: metin ekranda görünür
     * ve kopyalanabilir; sır oraya girmemeli.
     *
     * @return array{lines: list<string>, notes: list<string>, complete: bool}
     */
    public function ourSide(BgpNeighbor $neighbor): array
    {
        $neighbor->loadMissing(['group.families', 'switch']);

        $group = $neighbor->group;
        $name = $group->name;
        $notes = [];
        $lines = [];

        if ($neighbor->switch->autonomous_system !== null) {
            $lines[] = "set routing-options autonomous-system {$neighbor->switch->autonomous_system}";
        } else {
            $notes[] = 'Switchin AS numarası tanımlı değil; cihaz AS numarası olmadan BGP\'yi kabul etmez.';
        }

        $lines[] = "set protocols bgp group {$name} type ".$group->type->value;

        $metinler = [
            'peer-as' => $group->peer_asn,
            'local-address' => $group->local_address,
            'cluster' => $group->cluster,
            'description' => $group->description,
        ];

        foreach ($metinler as $eleman => $deger) {
            if (filled($deger)) {
                $lines[] = "set protocols bgp group {$name} {$eleman} {$deger}";
            }
        }

        if (filled($group->local_asn)) {
            $lines[] = "set protocols bgp group {$name} local-as {$group->local_asn}";
        }

        $sayilar = [
            'hold-time' => $group->hold_time,
            'out-delay' => $group->out_delay,
        ];

        foreach ($sayilar as $eleman => $deger) {
            if ($deger !== null) {
                $lines[] = "set protocols bgp group {$name} {$eleman} {$deger}";
            }
        }

        if ($group->bfd_minimum_interval !== null) {
            $lines[] = "set protocols bgp group {$name} bfd-liveness-detection minimum-interval "
                .$group->bfd_minimum_interval;
        }

        if ($group->multihop) {
            $lines[] = $group->multihop_ttl === null
                ? "set protocols bgp group {$name} multihop"
                : "set protocols bgp group {$name} multihop ttl {$group->multihop_ttl}";
        }

        $bayraklar = [
            'log-updown' => $group->log_updown,
            'passive' => $group->passive,
            'remove-private' => $group->remove_private,
            'as-override' => $group->as_override,
            'advertise-inactive' => $group->advertise_inactive,
            'multipath' => $group->multipath,
            'damping' => $group->damping,
        ];

        foreach ($bayraklar as $eleman => $acik) {
            if ($acik) {
                $lines[] = "set protocols bgp group {$name} {$eleman}";
            }
        }

        // Üç durumlu: "cihazın genel ayarı" satır yazmaz.
        if ($group->graceful_restart === BgpGracefulRestart::Enabled) {
            $lines[] = "set protocols bgp group {$name} graceful-restart";
        } elseif ($group->graceful_restart === BgpGracefulRestart::Disabled) {
            $lines[] = "set protocols bgp group {$name} graceful-restart disable";
        }

        foreach ($group->allow_prefixes ?? [] as $prefix) {
            $lines[] = "set protocols bgp group {$name} allow {$prefix}";
        }

        foreach (['import' => $group->import_policies, 'export' => $group->export_policies] as $eleman => $politikalar) {
            foreach ($politikalar ?? [] as $politika) {
                $lines[] = "set protocols bgp group {$name} {$eleman} {$politika}";
            }
        }

        $lines = [...$lines, ...$this->familyLines($neighbor, $name)];

        if ($group->authentication_key !== null) {
            $lines[] = "set protocols bgp group {$name} authentication-key \"<ANAHTAR>\"";
            $notes[] = 'Oturum anahtarı metne konmadı: cihazdan okunan biçim çözülebilir. '
                .'Başka bir cihaza uygularken <ANAHTAR> yerine gerçek değeri yazın.';
        }

        $lines = [...$lines, ...$this->ourNeighborLines($neighbor, $name, $notes)];

        return [
            'lines' => $lines,
            'notes' => $notes,
            'complete' => $neighbor->switch->autonomous_system !== null,
        ];
    }

    /**
     * Adres ailesi ve prefix sınırı satırları.
     *
     * @return list<string>
     */
    private function familyLines(BgpNeighbor $neighbor, string $name): array
    {
        $lines = [];

        foreach ($neighbor->group->families as $family) {
            $yol = "set protocols bgp group {$name} family {$family->family->value} {$family->nlri->value}";

            $lines[] = $yol;

            if ($family->prefix_limit_maximum === null) {
                continue;
            }

            $lines[] = "{$yol} prefix-limit maximum {$family->prefix_limit_maximum}";

            if (! $family->teardown) {
                continue;
            }

            $lines[] = $family->teardown_threshold === null
                ? "{$yol} prefix-limit teardown"
                : "{$yol} prefix-limit teardown {$family->teardown_threshold}";

            /*
             * "forever" ile dakikalı hâl aynı anda yazılamaz; ikisi de
             * teardown'ın altındadır.
             */
            if ($family->idle_timeout_forever) {
                $lines[] = "{$yol} prefix-limit teardown idle-timeout forever";
            } elseif ($family->idle_timeout_minutes !== null) {
                $lines[] = "{$yol} prefix-limit teardown idle-timeout {$family->idle_timeout_minutes}";
            }
        }

        return $lines;
    }

    /**
     * Komşuya özel satırlar; grubun değerini ezerler.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private function ourNeighborLines(BgpNeighbor $neighbor, string $name, array &$notes): array
    {
        $yol = "set protocols bgp group {$name} neighbor {$neighbor->address}";
        $lines = [$yol];

        $metinler = [
            'description' => $neighbor->description,
            'peer-as' => $neighbor->peer_asn,
            'local-address' => $neighbor->local_address,
        ];

        foreach ($metinler as $eleman => $deger) {
            if (filled($deger)) {
                $lines[] = "{$yol} {$eleman} {$deger}";
            }
        }

        if ($neighbor->hold_time !== null) {
            $lines[] = "{$yol} hold-time {$neighbor->hold_time}";
        }

        if ($neighbor->passive) {
            $lines[] = "{$yol} passive";
        }

        if ($neighbor->multihop) {
            $lines[] = $neighbor->multihop_ttl === null
                ? "{$yol} multihop"
                : "{$yol} multihop ttl {$neighbor->multihop_ttl}";
        }

        foreach (['import' => $neighbor->import_policies, 'export' => $neighbor->export_policies] as $eleman => $politikalar) {
            foreach ($politikalar ?? [] as $politika) {
                $lines[] = "{$yol} {$eleman} {$politika}";
            }
        }

        if ($neighbor->authentication_key !== null) {
            $lines[] = "{$yol} authentication-key \"<ANAHTAR>\"";
            $notes[] = 'Komşuya özel bir oturum anahtarı var; o da metne konmadı.';
        }

        return $lines;
    }

    /**
     * Karşı tarafın anons etmesi gereken prefixler.
     *
     * Kaynak tahmin edilmez: bizim gruba bağlı içeri alma politikasının
     * dayandığı prefix list, o komşudan KABUL ETTİĞİMİZ prefixlerin ta
     * kendisidir. Anons etmeleri gereken de tam olarak odur.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private function exportPolicy(BgpNeighbor $neighbor, string $groupName, array &$notes): array
    {
        $prefixes = $this->acceptedPrefixes($neighbor);

        if ($prefixes === []) {
            $notes[] = 'Anons politikası üretilmedi: bu grubun içeri alma politikasına bağlı dolu bir prefix list bulunamadı.';

            return [];
        }

        $listName = strtoupper($groupName).'-PREFIXES';
        $policyName = 'anons-'.$groupName;
        $lines = [];

        foreach ($prefixes as $prefix) {
            $lines[] = "set policy-options prefix-list {$listName} {$prefix}";
        }

        // Eşleşme türü Junos'ta ZORUNLUDUR; türsüz filtre sözdizimi hatası verir.
        $lines[] = "set policy-options policy-statement {$policyName} term anons from prefix-list-filter {$listName} orlonger";
        $lines[] = "set policy-options policy-statement {$policyName} term anons then accept";
        $lines[] = "set policy-options policy-statement {$policyName} term diger then reject";
        $lines[] = "set protocols bgp group {$groupName} export {$policyName}";

        return $lines;
    }

    /**
     * Bizim gruba bağlı politikaların dayandığı prefix listelerin içeriği.
     *
     * @return list<string>
     */
    private function acceptedPrefixes(BgpNeighbor $neighbor): array
    {
        $policies = $neighbor->group->import_policies ?? [];

        if ($policies === []) {
            return [];
        }

        $listNames = $neighbor->switch->policyStatements()
            ->whereIn('name', $policies)
            ->with('terms')
            ->get()
            ->flatMap(fn ($statement) => $statement->terms->flatMap(
                fn ($term): array => $term->match_prefix_lists ?? []
            ))
            ->unique()
            ->all();

        if ($listNames === []) {
            return [];
        }

        return SwitchPrefixList::query()
            ->where('switch_id', $neighbor->switch_id)
            ->whereIn('name', $listNames)
            ->with('entries')
            ->get()
            ->flatMap(fn (SwitchPrefixList $list) => $list->entries->map(
                fn ($entry): string => $entry->cidr
            ))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Karşı tarafın bacak adresi.
     *
     * Arayüz adı BİLİNMEZ — o onların cihazında hangi porta bağlandıklarına
     * göre değişir. Bu yüzden ayrı döner ve ekranda "arayüz adını düzeltin"
     * uyarısıyla gösterilir; ana bloğa karıştırılsaydı kopyala-yapıştır
     * yanlış porta adres yazardı.
     *
     * @return list<string>
     */
    private function interfaceLines(BgpNeighbor $neighbor): array
    {
        $block = $this->peeringBlock($neighbor->address);

        if ($block === null) {
            return [];
        }

        $family = str_contains($neighbor->address, ':') ? IpFamily::Inet6 : IpFamily::Inet;

        return [
            sprintf(
                'set interfaces <ARAYUZ> unit 0 family %s address %s/%d',
                $family->value,
                $neighbor->address,
                $block->prefix_length,
            ),
        ];
    }

    /**
     * Komşu adresini kapsayan peering bloğu; maske oradan gelir.
     */
    private function peeringBlock(string $address): ?AddressBlock
    {
        /*
         * address_blocks'un aralık sütunları IPv4 tamsayısıdır; IPv6 adresler
         * orada aranamaz ve aranırsa sorgu hata verir.
         */
        if (str_contains($address, ':')) {
            return null;
        }

        /*
         * Adres PAKETLENMİŞ BAYT olarak aranır; "/32" varsayımı IPv6 komşuda
         * yanlış olur ve blok hiç bulunamaz.
         */
        $binary = @inet_pton(trim($address));

        if ($binary === false) {
            return null;
        }

        $family = strlen($binary) === 16 ? IpFamily::Inet6 : IpFamily::Inet;

        return AddressBlock::query()
            ->where('is_subnetted', false)
            ->where('family', $family)
            ->where('range_start', '<=', $binary)
            ->where('range_end', '>=', $binary)
            ->orderByDesc('prefix_length')
            ->first();
    }

    /**
     * Karşı taraftaki grup adı. Junos tanımlayıcısı olmalı: harfle başlar.
     */
    private function groupName(BgpNeighbor $neighbor): string
    {
        $hostname = $neighbor->switch->hostname;
        $name = preg_replace('/[^A-Za-z0-9_.\-]/', '-', $hostname) ?? '';
        $name = trim((string) $name, '-');

        return $name === '' || ! ctype_alpha($name[0]) ? 'upstream' : $name;
    }

    /**
     * Kopyalanacak metin: yalnızca set satırları.
     *
     * @param  list<string>  $lines
     */
    public function toText(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * Karşı tarafın türü bizimkiyle aynıdır; iBGP ise iki taraf da aynı AS'i
     * kullanır ve peer-as kendi AS'imizdir.
     */
    public function isInternal(BgpNeighbor $neighbor): bool
    {
        return $neighbor->group->type === BgpGroupType::Internal;
    }
}
