<?php

declare(strict_types=1);

namespace App\Support\Netconf;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Enums\BgpNlri;
use App\Enums\FirewallAction;
use App\Enums\FirewallFamily;
use App\Enums\IpFamily;
use App\Enums\JunosSchemaProfile;
use App\Enums\JunosUserClass;
use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Enums\PolicyAction;
use App\Enums\PortMode;
use App\Enums\PortType;
use App\Enums\PrefixMatchType;
use App\Models\BgpGroup;
use App\Models\NetworkSwitch;
use App\Models\SwitchLoginUser;
use App\Models\SwitchPort;
use App\Models\SwitchPrefixList;
use App\Models\SwitchPrefixListEntry;
use App\Models\SwitchRibGroup;
use App\Models\SwitchRoute;
use App\Models\SwitchRoutingInstance;
use App\Models\SwitchVoipInterface;
use App\Models\Vlan;
use App\Services\Capabilities\DeviceCapabilities;
use App\Services\Netconf\AnalyzerConfigurator;
use App\Services\Netconf\ArchivalWriter;
use App\Services\Netconf\BgpConfigurator;
use App\Services\Netconf\DeviceSettingsWriter;
use App\Services\Netconf\FirewallConfigurator;
use App\Services\Netconf\IpAddressConfigurator;
use App\Services\Netconf\LinkAggregationConfigurator;
use App\Services\Netconf\LoginUserConfigurator;
use App\Services\Netconf\NetconfTarget;
use App\Services\Netconf\PolicyStatementConfigurator;
use App\Services\Netconf\PortConfigurator;
use App\Services\Netconf\PrefixListConfigurator;
use App\Services\Netconf\RibGroupConfigurator;
use App\Services\Netconf\RouteConfigurator;
use App\Services\Netconf\RoutingInstanceConfigurator;
use App\Services\Netconf\RstpConfigurator;
use App\Services\Netconf\SecureAccessPortConfigurator;
use App\Services\Netconf\SnmpCommunityConfigurator;
use App\Services\Netconf\SnmpIdentityWriter;
use App\Services\Netconf\StormControlConfigurator;
use App\Services\Netconf\VlanConfigurator;
use App\Services\Netconf\VoipConfigurator;
use App\Services\Provisioning\ConfigurationPusher;
use App\Services\Switching\SwitchAddressCandidateService;
use App\Support\Credentials\PasswordGenerator;

/**
 * Cihaza yazan bütün yolların doğrulama senaryoları.
 *
 * Her senaryo üretim servisini GERÇEK veriyle çağırır; belgeler cihaza
 * gider ve "commit check" ile doğrulanır (bkz. VerifyDeviceWritesCommand).
 * Amaç panelin ürettiği Junos'un cihazca kabul edildiğini kanıtlamaktır —
 * kendi beklentimizle değil, cihazın cevabıyla.
 *
 * Kullanılan adlar bilerek geçicidir ("dogrulama-*"); hiçbiri commit
 * edilmediği için cihazda kalmaz.
 */
class DeviceWriteScenarios
{
    /**
     * @return array<string, callable|Scenario>
     */
    public function all(NetworkSwitch $switch): array
    {
        $port = $this->port($switch);
        $ae = $switch->ports()->where('name', 'ae0')->first();
        $vlan = $switch->vlans()->first() ?? new Vlan(['name' => 'dogrulama', 'vlan_tag' => 3998]);
        $vlan->switch_id = $switch->id;

        return [
            ...$this->ports($switch, $port),
            ...$this->vlans($switch, $vlan),
            ...$this->addresses($switch, $port),
            ...$this->routes($switch),
            ...$this->routingInstances($switch),
            ...$this->ribGroups($switch),
            ...$this->prefixLists($switch),
            ...$this->firewall($switch),
            ...$this->policies($switch),
            ...$this->bgp($switch),
            ...$this->aggregation($switch, $ae),
            ...$this->device($switch),
            ...$this->deviceSettings($switch),
            ...$this->loginUsers($switch),
            ...$this->rstp($switch, $port),
            ...$this->stormControl($switch),
            ...$this->portMode($switch, $port),
            ...$this->voip($switch),
            ...$this->mirroring($switch),
            ...$this->secureAccessPort($switch),
            ...$this->hostile($switch, $port),
        ];
    }

    /**
     * Fırtına denetimi.
     *
     * Arayüz adını LEHÇE seçer. ELS öncesinde "all" sanal adı kullanılır:
     * cihaz, anahtarlama ailesinde olmayan bir porta ayar verilmesini
     * "Interface ... not found" ile reddediyor ve bu senaryonun ölçmek
     * istediği şey ŞEMA, port durumu değil. ELS ise "all"ı reddediyor
     * ("invalid interface type in 'all'", test4'te ölçüldü), orada gerçek
     * bir anahtarlama portu seçilir.
     *
     * @return array<string, callable|Scenario>
     */
    private function stormControl(NetworkSwitch $switch): array
    {
        $storm = app(StormControlConfigurator::class);
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();

        $arayuz = $lehce->supportsAllKeyword()
            ? 'all'
            : $switch->ports()
                ->where('interface_kind', PortType::Physical)
                ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
                ->orderBy('if_index')
                ->value('name');

        if ($arayuz === null) {
            $atla = static function (): never {
                throw new ScenarioNotApplicable(
                    'Bu lehçe "all" kısayolunu kabul etmiyor ve cihazda anahtarlama portu yok.',
                );
            };

            return [
                'firtina · yüzde eşiği' => $atla,
                'firtina · kbps eşiği' => $atla,
                'firtina · trafik türü muafiyeti' => $atla,
                'firtina · korunan ham satır' => $atla,
                'firtina · eşik aşılınca kapat' => fn () => $storm->applyShutdown($switch, true),
                'firtina · kapatmayı geri al' => fn () => $storm->applyShutdown($switch, false),
            ];
        }

        return [
            'firtina · yüzde eşiği' => fn () => $storm->apply($switch, $arayuz, ['level' => 80]),
            'firtina · kbps eşiği' => fn () => $storm->apply($switch, $arayuz, ['bandwidth' => 10000]),
            'firtina · trafik türü muafiyeti' => fn () => $storm->apply($switch, $arayuz, [
                'level' => 70,
                'no_broadcast' => true,
                'no_multicast' => true,
            ]),
            'firtina · korunan ham satır' => fn () => $storm->apply($switch, $arayuz, [
                'level' => 60,
                'residual_xml' => ['<no-unknown-unicast/>'],
            ]),
            'firtina · eşik aşılınca kapat' => fn () => $storm->applyShutdown($switch, true),
            'firtina · kapatmayı geri al' => fn () => $storm->applyShutdown($switch, false),
        ];
    }

    /**
     * Portun anahtarlama ile yönlendirme arasında GİDİP GELMESİ.
     *
     * "Access seçerseniz IP yapılandırması silinir" uyarısı tek başına
     * yetmez: kaydeden kullanıcının geri dönüş yolu da olmalı. Bu senaryolar
     * iki yönün de cihazca kabul edildiğini kanıtlar.
     *
     * @return array<string, callable|Scenario>
     */
    private function portMode(NetworkSwitch $switch, SwitchPort $port): array
    {
        $ports = app(PortConfigurator::class);
        $filtre = $switch->firewallFilters()->where('family', FirewallFamily::Inet)->value('name');

        $senaryolar = [
            'port · yönlendirmeye çevir (adressiz inet)' => fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Routed,
                'enabled' => true,
            ]),
            'port · anahtarlamaya geri çevir' => fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Access,
                'enabled' => true,
                'vlans' => [],
            ]),
        ];

        $blok = app(SwitchAddressCandidateService::class)->groupedOptions($switch);
        $adres = collect($blok)->flatMap(fn (array $liste): array => array_keys($liste))->first();

        if ($adres !== null) {
            // Adres port ekranından da verilebiliyor; aynı commit'te yazılır.
            $senaryolar['port · yönlendirme + adres (tek commit)'] = fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Routed,
                'enabled' => true,
                'ip_address' => app(SwitchAddressCandidateService::class)->cidrFor($switch, (string) $adres),
            ]);
        }

        if ($filtre !== null) {
            $senaryolar['port · filtre bağlaması (giriş + çıkış)'] = fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Routed,
                'enabled' => true,
                'input_filter' => $filtre,
                'output_filter' => $filtre,
            ]);

            $senaryolar['port · filtre bağlamasını kaldır'] = fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Routed,
                'enabled' => true,
                'input_filter' => null,
                'output_filter' => null,
            ]);
        }

        return $senaryolar;
    }

    /**
     * VoIP (ses) VLAN'ı.
     *
     * Arayüz adı "access-ports" seçilir: cihaz, anahtarlama ailesinde olmayan
     * porta ayar vermeyi reddediyor ve burada ölçülmek istenen ŞEMA.
     * VLAN adı cihazda TANIMLI olmalı; tanımsız ad şemaya değil eksik tanıma
     * itiraz eden bir hata üretir.
     *
     * GERÇEK port da denenir. Ekran iki ad SINIFI sunuyor (sanal ad ve gerçek
     * port); yalnızca biri denenirse rapor yeşil çıkar ama diğerini hiç
     * doğrulamaz — "all" hatası tam olarak böyle görülmedi
     * (bkz. .ai/rules/netconf.md → "ekranın SUNDUĞU her seçenek").
     *
     * @return array<string, callable|Scenario>
     */
    private function voip(NetworkSwitch $switch): array
    {
        /*
         * Cihazın kuralı: ses VLAN'ı, portun ETİKETSİZ VLAN'ından FARKLI
         * olmalı ("VOIP vlan and the data vlan configured on access
         * interface ... should be different"). Panel bunu zaten reddediyor;
         * senaryo o yasağı delerse raporda panel hatası gibi görünür.
         */
        $kullanilan = $switch->ports()
            ->with('vlans')
            ->get()
            ->flatMap(fn (SwitchPort $p): array => $p->vlans
                ->where('pivot.is_untagged', true)
                ->pluck('name')
                ->all())
            ->unique()
            ->all();

        $vlan = $switch->vlans()
            ->when($kullanilan !== [], fn ($q) => $q->whereNotIn('name', $kullanilan))
            ->orderBy('name')
            ->first();

        if ($vlan === null) {
            return [
                'voip · ses vlanı' => static function (): never {
                    throw new ScenarioNotApplicable(
                        'Cihazdaki her VLAN bir access portunun etiketsiz VLAN’ı; ses VLAN’ı olarak kullanılamaz.',
                    );
                },
            ];
        }

        $voip = app(VoipConfigurator::class);
        $ad = (string) $vlan->name;

        return [
            'voip · ses vlanı' => fn () => $voip->apply($switch, 'access-ports', ['vlan_name' => $ad]),
            'voip · vlan + iletim sınıfı' => fn () => $voip->apply($switch, 'access-ports', [
                'vlan_name' => $ad,
                'forwarding_class' => 'expedited-forwarding',
            ]),
            'voip · korunan ham satır' => fn () => $voip->apply($switch, 'access-ports', [
                'vlan_name' => $ad,
                'residual_xml' => ['<forwarding-class>best-effort</forwarding-class>'],
            ]),
            'voip · ayarı sil' => fn () => $voip->delete(
                $switch,
                new SwitchVoipInterface(['interface_name' => 'access-ports']),
            ),
            ...$this->voipGercekPort($switch, $ad),
        ];
    }

    /**
     * Ekranın sunduğu ikinci ad sınıfı: gerçek bir anahtarlama portu.
     *
     * @return array<string, callable|Scenario>
     */
    private function voipGercekPort(NetworkSwitch $switch, string $vlanAdi): array
    {
        $port = $switch->ports()
            ->where('interface_kind', PortType::Physical)
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->orderBy('if_index')
            ->value('name');

        if ($port === null) {
            return [
                'voip · gerçek port' => static function (): never {
                    throw new ScenarioNotApplicable('Cihazda anahtarlama modunda port yok.');
                },
            ];
        }

        $voip = app(VoipConfigurator::class);

        return [
            'voip · gerçek port' => fn () => $voip->apply($switch, $port.'.0', ['vlan_name' => $vlanAdi]),
        ];
    }

    /**
     * Port aynalama (analyzer).
     *
     * Şema geçerli bulundu; reddedilme SEMANTİKTİ ("output interface should be
     * configured with family ethernet-switching"), bu yüzden hedef anahtarlama
     * modundaki bir porttan seçilir.
     *
     * "ölçüm:" ile başlayan senaryolarda reddedilme bir hata değil CEVAPTIR:
     * panelin uydurmadığı kısıtların cihazda var olup olmadığını söyler.
     *
     * @return array<string, callable|Scenario>
     */
    private function mirroring(NetworkSwitch $switch): array
    {
        /*
         * FİZİKSEL port seçilir. Cihaz iki kuralı ölçtürdü:
         *   - "Aggregated interface cannot be configured as Analyzer monitor
         *     interface" — ae arayüzü olmaz.
         *   - "Analyzer output interface should not be added to any vlan" —
         *     çıkış portu hiçbir VLAN'a üye olmamalı.
         * Senaryonun ölçmek istediği ŞEMA, cihazın bu semantik kuralları
         * değil; ikisi de panelde ayrıca doğrulanır.
         */
        $anahtarlama = $switch->ports()
            ->where('interface_kind', PortType::Physical)
            ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
            ->whereDoesntHave('vlans')
            ->orderBy('if_index')
            ->pluck('name');

        /*
         * Çoğu cihazda VLAN'sız anahtarlama portu YOKTUR, bu yüzden
         * senaryo çoğu zaman üretilmez. Şema yine de ölçüldü: aynı özel
         * adayda çıkış portunun VLAN üyeliği kaldırılıp analyzer eklendi ve
         * commit check geçti (bkz. .ai/rules/junos.md). Uygun port bulunan
         * bir cihazda bu senaryolar kendiliğinden çalışır.
         */
        if ($anahtarlama->count() < 2) {
            return [];
        }

        $analyzer = app(AnalyzerConfigurator::class);
        $mantiksal = static fn (string $ad): string => str_contains($ad, '.') ? $ad : "{$ad}.0";

        $kaynak = $mantiksal((string) $anahtarlama->first());
        $hedef = $mantiksal((string) $anahtarlama->last());
        $vlan = (string) ($switch->vlans()->value('name') ?? 'default');

        return [
            'aynalama · port kaynağı + port hedefi' => fn () => $analyzer->apply($switch, 'dogrulama-ayna', [
                'ingress_interfaces' => [$kaynak],
                'egress_interfaces' => [$kaynak],
                'output_interface' => $hedef,
            ]),
            'aynalama · oran + kayıp önceliği' => fn () => $analyzer->apply($switch, 'dogrulama-ayna', [
                'ingress_interfaces' => [$kaynak],
                'output_interface' => $hedef,
                'ratio' => 2,
                'loss_priority' => 'low',
            ]),
            'aynalama · vlan kaynağı' => fn () => $analyzer->apply($switch, 'dogrulama-ayna', [
                'ingress_vlans' => [$vlan],
                'output_interface' => $hedef,
            ]),

            // ÖLÇÜM: çıkış VLAN'ının yazımı (name mi düz metin mi) doğrulanmadı.
            'aynalama · ölçüm: çıkış VLAN (uzak aynalama)' => fn () => $analyzer->apply($switch, 'dogrulama-ayna', [
                'ingress_interfaces' => [$kaynak],
                'output_vlan' => $vlan,
            ]),

            // ÖLÇÜM: Junos'un oran üst sınırı panelde uydurulmadı.
            'aynalama · ölçüm: oran 2048' => fn () => $analyzer->apply($switch, 'dogrulama-ayna', [
                'ingress_interfaces' => [$kaynak],
                'output_interface' => $hedef,
                'ratio' => 2048,
            ]),
        ];
    }

    /**
     * DHCP snooping ve port güvenliği.
     *
     * "vlan all { examine-dhcp; }" ELS ÖNCESİNDE commit check ile kabul
     * edildi; ELS ise "all" adını ayrılmış sözcük sayıyor (test4). Adları
     * bu yüzden LEHÇE seçer — yoksa ELS'te sekiz senaryo da kırmızı yanar
     * ve panelde hata yokken varmış gibi görünür.
     *
     * @return array<string, callable|Scenario>
     */
    private function secureAccessPort(NetworkSwitch $switch): array
    {
        $secure = app(SecureAccessPortConfigurator::class);
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();

        $vlanAdi = $lehce->supportsAllKeyword()
            ? 'all'
            : $switch->vlans()->orderBy('vlan_tag')->value('name');

        $portAdi = $lehce->supportsAllKeyword()
            ? 'all'
            : $switch->ports()
                ->where('interface_kind', PortType::Physical)
                ->whereIn('port_mode', [PortMode::Access->value, PortMode::Trunk->value])
                ->orderBy('if_index')
                ->value('name');

        if ($vlanAdi === null || $portAdi === null) {
            $atla = static function (): never {
                throw new ScenarioNotApplicable(
                    'Bu lehçe "all" kısayolunu kabul etmiyor ve cihazda kullanılabilir VLAN/port yok.',
                );
            };

            return array_fill_keys([
                'snooping · vlan examine-dhcp',
                'snooping · vlan tam koruma',
                'snooping · ölçüm: mac-move-limit',
                'port güvenliği · dhcp-trusted',
                'port güvenliği · no-dhcp-trusted',
                'port güvenliği · ölçüm: mac-limit shutdown',
                'port güvenliği · ölçüm: allowed-mac listesi',
            ], $atla);
        }

        return [
            'snooping · vlan examine-dhcp' => fn () => $secure->applyVlan($switch, $vlanAdi, [
                'examine_dhcp' => true,
            ]),
            'snooping · vlan tam koruma' => fn () => $secure->applyVlan($switch, $vlanAdi, [
                'examine_dhcp' => true,
                'arp_inspection' => true,
                'ip_source_guard' => true,
            ]),

            // ÖLÇÜM: mac-move-limit + action yazımı doğrulanmadı.
            'snooping · ölçüm: mac-move-limit' => fn () => $secure->applyVlan($switch, $vlanAdi, [
                'examine_dhcp' => true,
                'mac_move_limit' => 5,
                'mac_move_action' => 'drop',
            ]),

            'port güvenliği · dhcp-trusted' => fn () => $secure->applyInterface($switch, $portAdi, [
                'dhcp_trusted' => true,
            ]),
            'port güvenliği · no-dhcp-trusted' => fn () => $secure->applyInterface($switch, $portAdi, [
                'dhcp_trusted' => false,
            ]),

            // ÖLÇÜM: mac-limit + action ve allowed-mac yazımları doğrulanmadı.
            'port güvenliği · ölçüm: mac-limit shutdown' => fn () => $secure->applyInterface($switch, $portAdi, [
                'mac_limit' => 4,
                'mac_limit_action' => 'shutdown',
            ]),
            'port güvenliği · ölçüm: allowed-mac listesi' => fn () => $secure->applyInterface($switch, $portAdi, [
                'allowed_macs' => ['00:11:22:33:44:55', '00:11:22:33:44:66'],
            ]),
        ];
    }

    /**
     * Cihazın kendi ayarları ve SNMP community'leri.
     *
     * @return array<string, callable|Scenario>
     */
    private function deviceSettings(NetworkSwitch $switch): array
    {
        $settings = app(DeviceSettingsWriter::class);
        $communities = app(SnmpCommunityConfigurator::class);
        $mevcut = $switch->snmpCommunities()->first();

        $senaryolar = [
            'cihaz ayarı · ad + saat + dns' => fn () => $settings->apply($switch, [
                'hostname' => $switch->hostname,
                'time_zone' => 'Europe/Istanbul',
                'name_servers' => ['8.8.8.8', '1.1.1.1'],
            ]),
            'cihaz ayarı · ntp + syslog' => fn () => $settings->apply($switch, [
                'ntp_servers' => ['192.0.2.51'],
                'syslog_hosts' => ['192.0.2.51'],
            ]),
            'cihaz ayarı · saat dilimini sil' => fn () => $settings->apply($switch, ['time_zone' => null]),
            'snmp · kimlik + konum + iletişim' => fn () => app(SnmpIdentityWriter::class)
                ->rename($switch, $switch->hostname, 'Lokasyon-A', 'teknik@ornek.test'),
            'snmp community · ekle' => fn () => $communities->create($switch, 'dogrulama', 'read-only', ['198.18.20.0/24']),
            'cihaz ayarı · alarm (kırmızı/sarı)' => fn () => $settings->apply($switch, [
                'alarm_ethernet_link_down' => 'red',
                'alarm_management_link_down' => 'yellow',
            ]),
            'cihaz ayarı · alarm sil' => fn () => $settings->apply($switch, [
                'alarm_ethernet_link_down' => null,
                'alarm_management_link_down' => null,
            ]),
        ];

        if ($mevcut !== null) {
            $senaryolar['snmp community · güncelle'] = fn () => $communities->update($switch, $mevcut, 'read-only', []);
            $senaryolar['snmp community · sil'] = fn () => $communities->delete($switch, $mevcut);
        }

        return $senaryolar;
    }

    /**
     * Cihaz üzerindeki yerel giriş hesapları.
     *
     * Panelin bağlandığı hesaba DOKUNULMAZ: senaryolar commit atmasa da
     * o hesabı konu eden bir belge üretmek, aynı kodun yanlışlıkla gerçek
     * yazıcıyla çalıştırıldığı gün switchi yönetilemez bırakır.
     *
     * @return array<string, callable|Scenario>
     */
    private function loginUsers(NetworkSwitch $switch): array
    {
        $users = app(LoginUserConfigurator::class);

        $senaryolar = [
            'hesap · ekle (salt okunur)' => fn () => $users->create(
                $switch,
                'dogrulama',
                JunosUserClass::ReadOnly->value,
                'Dogrulama Hesabi',
                null,
                null,
            ),
            'hesap · ekle (uid + parola)' => fn () => $users->create(
                $switch,
                'dogrulama2',
                JunosUserClass::Operator->value,
                null,
                60000,
                'Dogrulama123!',
            ),
        ];

        /*
         * Parola değiştirme turunun ürettiği belgeler. Cihaza commit
         * ATILMAZ (harness yalnızca "commit check" yapar); sınanan şey
         * Junos'un bu biçimleri kabul edip etmediğidir.
         */
        $senaryolar['parola · kurtarma hesabi'] = fn () => $users->create(
            $switch,
            (string) config('credentials.rotation.recovery_username'),
            JunosUserClass::SuperUser->value,
            null,
            null,
            app(PasswordGenerator::class)->generate(),
        );

        $panel = $switch->loginUsers->first(fn (SwitchLoginUser $user): bool => $user->is_panel_account);

        if ($panel !== null) {
            $senaryolar['parola · panel hesabi'] = fn () => $users->update(
                $switch,
                $panel,
                (string) $panel->class,
                $panel->full_name,
                app(PasswordGenerator::class)->generate(),
            );
        }

        $silinebilir = $switch->loginUsers->first(fn (SwitchLoginUser $user): bool => ! $user->is_panel_account);

        if ($silinebilir !== null) {
            $senaryolar['hesap · güncelle'] = fn () => $users->update(
                $switch,
                $silinebilir,
                JunosUserClass::ReadOnly->value,
                'Guncellendi',
                null,
            );
            $senaryolar['hesap · sil'] = fn () => $users->delete($switch, $silinebilir);
        }

        return $senaryolar;
    }

    /**
     * Spanning tree.
     *
     * @return array<string, callable|Scenario>
     */
    private function rstp(NetworkSwitch $switch, SwitchPort $port): array
    {
        $rstp = app(RstpConfigurator::class);
        $mevcut = $switch->rstpInterfaces()->first();

        $senaryolar = [
            'rstp · köprü (açık + öncelik)' => fn () => $rstp->applyBridge($switch, true, '8k', true),
            'rstp · köprü (kapalı)' => fn () => $rstp->applyBridge($switch, false, null, false),
            /*
             * Anahtarlama modundaki bir port seçilir: cihaz routed ve
             * yapılandırılmamış portlarda spanning tree'yi reddediyor.
             */
            'rstp · arayüz (edge + maliyet)' => fn () => $rstp->applyInterface(
                $switch,
                (string) ($switch->ports()->whereIn('port_mode', ['access', 'trunk'])->value('name') ?? 'all'),
                true,
                false,
                20000,
                128,
            ),
            'rstp · arayüz (all)' => fn () => $rstp->applyInterface($switch, 'all', false, true, null, null),
        ];

        if ($mevcut !== null) {
            $senaryolar['rstp · arayüz sil'] = fn () => $rstp->deleteInterface($switch, $mevcut);
        }

        return $senaryolar;
    }

    /**
     * SINIRLARI ZORLAYAN senaryolar.
     *
     * Amaç kırmak: XML özel karakterleri, CDATA sonlandırıcı, çok uzun
     * değerler, uç sayılar. Panelin ürettiği belge her durumda GEÇERLİ XML
     * olmalı; cihaz "syntax error" derse kaçış yolu eksik demektir.
     *
     * @return array<string, callable|Scenario>
     */
    private function hostile(NetworkSwitch $switch, SwitchPort $port): array
    {
        $ports = app(PortConfigurator::class);
        $pusher = app(ConfigurationPusher::class);
        $target = NetconfTarget::fromSwitch($switch);

        return [
            'sınır · açıklamada XML karakterleri' => fn () => $ports->apply($switch, $port, [
                'description' => 'a<b>&c"d\'e',
            ]),
            'sınır · açıklamada CDATA sonlandırıcı' => fn () => $ports->apply($switch, $port, [
                'description' => 'kapat ]]> ac',
            ]),
            'sınır · 255 karakterlik açıklama' => fn () => $ports->apply($switch, $port, [
                'description' => str_repeat('u', 255),
            ]),
            'sınır · config metninde CDATA sonlandırıcı' => fn () => $pusher->push(
                $target,
                'set interfaces '.$port->name.' description "kapat ]]> ac"',
                'merge',
                'manual',
                null,
            ),
            'sınır · config metninde XML karakterleri' => fn () => $pusher->push(
                $target,
                'set interfaces '.$port->name.' description "a<b>&c"',
                'merge',
                'manual',
                null,
            ),
            'sınır · mtu alt sınır (256)' => fn () => $ports->apply($switch, $port, ['mtu' => 256]),
            'sınır · mtu üst sınır (9216)' => fn () => $ports->apply($switch, $port, ['mtu' => 9216]),
        ];
    }

    /** Yazma için güvenli port: ae üyesi olmayan, adresi olmayan bir port. */
    private function port(NetworkSwitch $switch): SwitchPort
    {
        return $switch->ports()
            ->whereNull('aggregate_port_id')
            ->where('name', 'like', 'ge-%')
            ->orderByDesc('if_index')
            ->first() ?? new SwitchPort(['name' => 'ge-0/0/22']);
    }

    /**
     * Cihazın kabul ettiği tablo türü; FBF'nin tercih ettiği "forwarding"
     * varsa o, yoksa ölçülmüş ilk tür. Hiç ölçülmemişse "forwarding".
     */
    private function desteklenenTabloTuru(NetworkSwitch $switch): ?string
    {
        $olculen = app(DeviceCapabilities::class)->values($switch, 'routing.instance_types');

        if ($olculen === null) {
            return 'forwarding';
        }

        return in_array('forwarding', $olculen, true) ? 'forwarding' : ($olculen[0] ?? null);
    }

    /**
     * Cihazın kabul ettiği en yüksek hız; ölçüm yoksa lehçenin en yükseği.
     * Hiçbiri yoksa null döner ve senaryo otomatik anlaşmayı ölçer.
     */
    private function desteklenenHiz(NetworkSwitch $switch): ?string
    {
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();
        $hizlar = array_map(strval(...), array_keys($lehce->speedValues()));

        $olculen = app(DeviceCapabilities::class)->values($switch, 'port.speeds');

        if ($olculen !== null) {
            $hizlar = array_values(array_intersect($hizlar, $olculen));
        }

        return $hizlar === [] ? null : (string) max(array_map(intval(...), $hizlar));
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function ports(NetworkSwitch $switch, SwitchPort $port): array
    {
        $ports = app(PortConfigurator::class);

        /*
         * Hız değeri MODELE bağlı: EX2300 "1g"yi reddediyor (test4'te
         * ölçüldü). Sabit yazmak, panelde hata yokken kırmızı satır
         * üretirdi — ölçülmüş kümeden seçilir.
         */
        $hiz = $this->desteklenenHiz($switch);

        return [
            'port · access + hız + açıklama' => fn () => $ports->apply($switch, $port, [
                'description' => 'dogrulama',
                'enabled' => true,
                'speed' => $hiz,
                'autonegotiation' => $hiz === null,
                'mode' => PortMode::Access,
                'vlans' => [],
                'untagged' => null,
                'native_vlan_tag' => null,
            ]),
            'port · trunk + etiketsiz vlan' => fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Trunk,
                'vlans' => $switch->vlans()->pluck('name')->take(2)->all(),
                'untagged' => $switch->vlans()->value('name'),
                'native_vlan_tag' => $switch->vlans()->value('vlan_tag'),
            ]),
            /*
             * Üyeliğin İKİNCİ YOLU: "vlans <ad> interface <port>.0". Cihaz iki
             * yolu tek üyelik sayıyor, bu yüzden panel yazarken vlans tarafını
             * da temizler. Olmayan satırı silmek cihazda hata değildir —
             * doğrulanan tam olarak budur.
             */
            'port · vlans tarafını temizle' => fn () => $ports->apply($switch, $port, [
                'mode' => PortMode::Access,
                'vlans' => $switch->vlans()->pluck('name')->take(1)->all(),
                'untagged' => $switch->vlans()->value('name'),
                'native_vlan_tag' => null,
            ]),
            'port · mtu yaz' => fn () => $ports->apply($switch, $port, ['mtu' => 9216]),
            'port · mtu sil' => fn () => $ports->apply($switch, $port, ['mtu' => null]),
            'port · kapat' => fn () => $ports->apply($switch, $port, ['enabled' => false]),
            'port · otomatik hız' => fn () => $ports->apply($switch, $port, [
                'speed' => null,
                'autonegotiation' => true,
            ]),
            'port · full duplex' => fn () => $ports->apply($switch, $port, [
                'speed' => null,
                'autonegotiation' => true,
                'duplex' => 'full',
            ]),
            'port · half duplex + 100 Mbps' => fn () => $ports->apply($switch, $port, [
                'speed' => '100',
                'autonegotiation' => false,
                'duplex' => 'half',
            ]),
            'port · duplex sil' => fn () => $ports->apply($switch, $port, ['duplex' => null]),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function vlans(NetworkSwitch $switch, Vlan $vlan): array
    {
        $vlans = app(VlanConfigurator::class);

        return [
            'vlan · ekle (açıklamalı)' => fn () => $vlans->create($switch, 'dogrulama', 3998, null, [], 'Dogrulama'),
            'vlan · ekle + l3 arayüz' => fn () => $vlans->create($switch, 'dogrulama2', 3997, 'vlan.3997', [], null),
            'vlan · güncelle' => fn () => $vlans->update($switch, $vlan, $vlan->vlan_tag, $vlan->l3_interface, [], 'Guncellendi'),

            /*
             * L3 arayüzü kaldırılınca "interfaces vlan unit N" de gider.
             * Birim bırakılsaydı hiçbir VLAN'a bağlı olmayan öksüz bir arayüz
             * kalırdı; cihaz bu silmeyi kabul ediyor mu, ölçülür.
             */
            'vlan · l3 arayüzünü kaldır' => fn () => $vlans->update($switch, $vlan, $vlan->vlan_tag, null, [], null),
            /*
             * Etiket ZORUNLU DEĞİL: cihaz "vlans <ad>" satırını vlan-id
             * olmadan kabul ediyor ve erişim switchlerinde böyle VLAN'lar
             * bulunabilir. Boş etiket cihazdaki vlan-id satırını SİLER.
             */
            'vlan · etiketsiz ekle' => fn () => $vlans->create($switch, 'dogrulama3', null, null, [], null),

            /*
             * ELS, L3 arayüzü TAŞIYAN bir VLAN'ın etiketini kaldırtmıyor
             * ("l3-interface can be configured only under vlans with
             * 'vlan-id'/'vlan-tags'") ve panel bunu zaten reddediyor
             * (JunosDialect::requiresVlanIdForL3). Senaryo o yasağı delip
             * cihaza yazsaydı raporda panel hatası gibi görünürdü; L3
             * arayüzü VARSA etiket kaldırma denenmez.
             */
            ...$this->vlanEtiketKaldirma($switch, $vlan),

            'vlan · sil' => fn () => $vlans->delete($switch, $vlan),
        ];
    }

    /**
     * @return array<string, callable>
     */
    private function vlanEtiketKaldirma(NetworkSwitch $switch, Vlan $vlan): array
    {
        $lehce = ($switch->schema_profile ?? JunosSchemaProfile::PreEls)->dialect();

        if ($lehce->requiresVlanIdForL3() && filled($vlan->l3_interface)) {
            return [
                'vlan · etiketi kaldır' => static function (): never {
                    throw new ScenarioNotApplicable(
                        'Bu lehçede L3 arayüzü taşıyan VLAN etiketsiz bırakılamaz; panel de izin vermiyor.',
                    );
                },
            ];
        }

        $vlans = app(VlanConfigurator::class);

        return [
            'vlan · etiketi kaldır' => fn () => $vlans->update($switch, $vlan, null, $vlan->l3_interface, [], null),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function addresses(NetworkSwitch $switch, SwitchPort $port): array
    {
        $addresses = app(IpAddressConfigurator::class);
        $mevcut = $switch->ipAddresses()->whereNotNull('switch_port_id')->first();

        $senaryolar = [
            'ip · porta adres ver' => fn () => $addresses->add($switch, $port->name.'.0', '192.0.2.1/30'),

            /*
             * Loopback: BGP local-address ve router-id için tipik arayüz.
             * lo0'da ethernet-switching ailesi YOKTUR; onu silmeye çalışan
             * bir yükleme cihazca reddedilir.
             */
            'ip · loopbacke adres ver' => fn () => $addresses->add($switch, 'lo0.0', '192.0.2.9/32'),

            // IPv6 "inet6" ailesinin altına gitmeli; "inet" altına yazılırsa
            // cihaz reddeder.
            'ip · ipv6 adres ver' => fn () => $addresses->add($switch, $port->name.'.0', '2001:db8::1/64'),
        ];

        if ($mevcut !== null) {
            $senaryolar['ip · adres değiştir'] = fn () => $addresses->replace(
                $switch,
                $mevcut,
                $mevcut->interface_name,
                '192.0.2.5/30',
            );
            $senaryolar['ip · adres sil'] = fn () => $addresses->remove($switch, $mevcut);
        }

        return $senaryolar;
    }

    /**
     * Yönlendirme tabloları (FBF). Biçimler test1'de ölçüldü.
     *
     * @return array<string, callable|Scenario>
     */
    private function routingInstances(NetworkSwitch $switch): array
    {
        $tablolar = app(RoutingInstanceConfigurator::class);
        $mevcut = $switch->routingInstances()->first()
            ?? new SwitchRoutingInstance(['name' => 'dogrulama-tablo']);

        /*
         * Tür MODELE bağlı: EX2300 "forwarding"i reddediyor (test4'te
         * ölçüldü) ve FBF orada virtual-router ile kurulmak zorunda.
         * Ölçülmüş kümeden seçilir; sabit yazmak olmayan bir panel hatası
         * gösterirdi.
         */
        $tur = $this->desteklenenTabloTuru($switch);

        if ($tur === null) {
            $atla = static function (): never {
                throw new ScenarioNotApplicable('Cihaz panelin kurduğu tablo türlerinden hiçbirini kabul etmiyor.');
            };

            return array_fill_keys([
                'tablo · oluştur',
                'tablo · açıklamayı temizle',
                'tablo · sil',
            ], $atla);
        }

        return [
            'tablo · oluştur' => fn () => $tablolar->apply($switch, 'dogrulama-tablo', [
                'instance_type' => $tur,
                'description' => 'Dogrulama',
            ]),
            'tablo · açıklamayı temizle' => fn () => $tablolar->apply($switch, 'dogrulama-tablo', [
                'instance_type' => $tur,
                'description' => null,
            ]),
            'tablo · sil' => fn () => $tablolar->delete($switch, $mevcut),
        ];
    }

    /**
     * RIB grupları — FBF'nin üçüncü parçası. Biçimler test1'de ölçüldü.
     *
     * @return array<string, callable|Scenario>
     */
    private function ribGroups(NetworkSwitch $switch): array
    {
        $gruplar = app(RibGroupConfigurator::class);
        $mevcut = $switch->ribGroups()->first() ?? new SwitchRibGroup(['name' => 'dogrulama-grup']);

        /*
         * Bağlama, grubun CİHAZDA tanımlı olmasını ister. Her senaryo kendi
         * özel adayında çalışıp atıldığı için bir önceki senaryonun yarattığı
         * grup burada YOKTUR; "dogrulama-grup" yazmak "ribgroup not defined
         * for INET" verir ve raporda panel hatası gibi görünürdü.
         */
        $tanimli = $switch->ribGroups()->value('name');

        $baglama = $tanimli === null
            ? [
                'rib grubu · arayüz yollarını bağla' => static function (): never {
                    throw new ScenarioNotApplicable('Cihazda tanımlı bir rib grubu yok; bağlama ölçülemez.');
                },
            ]
            : [
                'rib grubu · arayüz yollarını bağla' => fn () => $gruplar->applyInterfaceRoutes($switch, $tanimli),
            ];

        return [
            'rib grubu · oluştur' => fn () => $gruplar->apply($switch, 'dogrulama-grup', [
                'import_ribs' => ['inet.0'],
            ]),
            ...$baglama,
            'rib grubu · bağı kaldır' => fn () => $gruplar->applyInterfaceRoutes($switch, null),
            'rib grubu · sil' => fn () => $gruplar->delete($switch, $mevcut),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function routes(NetworkSwitch $switch): array
    {
        $routes = app(RouteConfigurator::class);
        $mevcut = $switch->routes()->first() ?? new SwitchRoute([
            'destination' => '192.0.2.0',
            'prefix_length' => 24,
            'next_hop' => '198.18.8.37',
        ]);
        $mevcut->switch_id = $switch->id;

        return [
            'route · ekle' => fn () => $routes->create($switch, '192.0.2.0', 24, ['next_hops' => ['198.18.8.37']]),
            'route · reject' => fn () => $routes->create($switch, '192.168.222.0', 24, ['next_hops' => ['Reject']]),
            'route · discard (atlamasız)' => fn () => $routes->create($switch, '198.51.100.0', 24, ['next_hops' => ['Discard']]),
            'route · receive' => fn () => $routes->create($switch, '198.51.100.44', 30, ['next_hops' => ['Receive']]),

            // Yedekli çıkış: iki atlama aynı route'ta (ECMP).
            'route · ecmp (iki atlama)' => fn () => $routes->create($switch, '198.51.100.48', 30, [
                'next_hops' => ['198.18.8.37', '198.18.8.38'],
            ]),

            // Floating static: yedek atlama kendi preference'ıyla.
            'route · qualified-next-hop + preference' => fn () => $routes->create($switch, '198.51.100.52', 30, [
                'next_hops' => ['198.18.8.37'],
                'qualified_next_hops' => [['name' => '198.18.8.38', 'preference' => 250, 'metric' => 5]],
            ]),

            'route · preference + metric + tag' => fn () => $routes->create($switch, '198.51.100.56', 30, [
                'next_hops' => ['198.18.8.37'],
                'preference' => 120, 'metric' => 9, 'tag' => 4242,
            ]),

            'route · seçenekler (retain, no-readvertise, passive)' => fn () => $routes->create($switch, '198.51.100.60', 30, [
                'next_hops' => ['198.18.8.37'],
                'flags' => ['retain', 'no-readvertise', 'passive'],
            ]),

            'route · community' => fn () => $routes->create($switch, '198.51.100.64', 30, [
                'next_hops' => ['Discard'],
                'communities' => ['65000:100'],
            ]),

            // IPv6: cihazda "rib inet6.0" altına yazılır.
            'route · IPv6' => fn () => $routes->create($switch, '2001:db8:1::', 48, [
                'rib' => 'inet6.0',
                'next_hops' => ['2001:db8::1'],
            ]),

            /*
             * ARTIK XML: panelin tanımadığı bir satır, okunduğu gibi geri
             * yazılıyor mu? Cihaz kabul etmezse bütün "hiçbir şey kaybolmaz"
             * sözü boşa çıkar.
             */
            'route · korunan ham satır geri yazılır' => fn () => $routes->create($switch, '198.51.100.68', 30, [
                'next_hops' => ['198.18.8.37'],
                'residual_xml' => ['<no-retain/>'],
            ]),

            'route · değiştir' => fn () => $routes->replace($switch, $mevcut, '192.0.2.0', 24, ['next_hops' => ['198.18.8.37']]),
            'route · sil' => fn () => $routes->delete($switch, $mevcut),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function prefixLists(NetworkSwitch $switch): array
    {
        $lists = app(PrefixListConfigurator::class);
        $list = $switch->prefixLists()->first() ?? new SwitchPrefixList(['name' => 'dogrulama']);
        $list->switch_id = $switch->id;
        $entry = $list->entries()->first() ?? new SwitchPrefixListEntry([
            'address' => '192.0.2.0',
            'prefix_length' => 24,
        ]);

        return [
            'prefix list · oluştur' => fn () => $lists->createList($switch, 'dogrulama-liste'),
            'prefix list · giriş ekle' => fn () => $lists->addEntry($switch, $list, '192.0.2.0/24'),

            /*
             * IPv6 girdi: cihaz kabul ediyor ama listenin ailesi KULLANILDIĞI
             * YERE bağlı — "family inet" bir filtreden atıf alan listeye v6
             * eklemek reddediliyor (ölçüldü). Bu yüzden senaryo BAĞIMSIZ bir
             * listeye yazar; panelin doğrulaması da aynı kuralı biliyor.
             */
            'prefix list · IPv6 giriş (bağımsız liste)' => fn () => $lists->addEntry(
                $switch,
                new SwitchPrefixList(['name' => 'dogrulama-v6', 'switch_id' => $switch->id]),
                '2001:db8::/32',
            ),

            /*
             * apply-path: listeyi cihaz DİNAMİK doldurur. Panel bunu
             * okuyamasaydı listeyi "boş" gösterir ve salt okunur ilan
             * ederdi.
             */
            'prefix list · apply-path' => fn () => $lists->updateSettings(
                $switch,
                $list,
                ['system radius-server <*>'],
            ),

            'prefix list · giriş sil' => fn () => $lists->removeEntry($switch, $list, $entry),
            'prefix list · sil (referanslıyken)' => Scenario::expectsRejection(
                'Firewall terimi bu listeye atıf yapıyor; cihaz silinmesine izin vermemeli.',
                fn () => $lists->deleteList($switch, $list),
            ),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function firewall(NetworkSwitch $switch): array
    {
        $filters = app(FirewallConfigurator::class);
        $filter = $switch->firewallFilters()->with('terms')->first();

        $terim = [
            'name' => 'dogrulama',
            'source_addresses' => ['192.0.2.0/24'],
            'destination_addresses' => [],
            'source_prefix_lists' => [],
            'destination_prefix_lists' => [],
            'protocols' => ['tcp'],
            'ports' => [],
            'source_ports' => [],
            'destination_ports' => ['443'],
            'icmp_types' => [],
            'action' => FirewallAction::Discard,
            'count_name' => null,
            'is_logged' => false,
        ];

        $senaryolar = [
            'firewall · filtre oluştur' => fn () => $filters->create($switch, IpFamily::Inet, 'dogrulama-filtre', [$terim]),

            // Filtre SEVİYESİNDEKİ ayar: arayüz başına sayaç için gerekir.
            'firewall · interface-specific' => fn () => $filters->create(
                $switch,
                IpFamily::Inet,
                'dogrulama-ifspec',
                [$terim + ['count_name' => 'dogrulama_sayac']],
                ['interface_specific' => true],
            ),

            // Genişletilmiş koşullar: hepsi test1'de tek tek ölçüldü.
            'firewall · genişletilmiş koşullar' => fn () => $filters->create(
                $switch,
                IpFamily::Inet,
                'dogrulama-genis',
                [array_merge($terim, [
                    'tcp_flags' => 'syn',
                    'dscps' => ['ef'],
                    'packet_lengths' => ['1400'],
                    'ttls' => ['10-20'],
                    'is_fragment' => true,
                ])],
            ),

            // Sonlandırmayan eylem: sıradaki terime geç.
            'firewall · then next term' => fn () => $filters->create(
                $switch,
                IpFamily::Inet,
                'dogrulama-next',
                [
                    ['name' => 'a', 'goes_to_next_term' => true],
                    $terim + ['name' => 'b'],
                ],
            ),

            // IPv6 filtresi: ayrı aile, ayrı koşullar.
            'firewall · IPv6 filtresi' => fn () => $filters->create(
                $switch,
                IpFamily::Inet6,
                'dogrulama-v6',
                [['name' => 'a', 'next_headers' => ['tcp'], 'action' => FirewallAction::Accept]],
            ),

            // ethernet-switching ailesi: L2 filtresi.
            'firewall · ethernet-switching filtresi' => fn () => $filters->create(
                $switch,
                FirewallFamily::EthernetSwitching,
                'dogrulama-l2',
                [['name' => 'a', 'action' => FirewallAction::Accept]],
            ),

            // Policer: ayrı nesne, terimlerden atıf alır.
            'firewall · policer oluştur' => fn () => $filters->createPolicer($switch, 'dogrulama-policer', [
                'bandwidth_limit' => '1m',
                'burst_size_limit' => '15k',
                'is_discard' => true,
            ]),

            /*
             * ARTIK XML: panelin tanımadığı satır okunduğu gibi geri
             * yazılıyor mu? Cihaz kabul etmezse "hiçbir şey kaybolmaz" sözü
             * boşa çıkar.
             */
            'firewall · korunan ham satır' => fn () => $filters->create(
                $switch,
                IpFamily::Inet,
                'dogrulama-artik',
                [$terim],
                ['residual_xml' => ['<interface-specific/>']],
            ),
        ];

        if ($filter !== null) {
            $senaryolar['firewall · filtre güncelle'] = fn () => $filters->update($switch, $filter, [$terim]);
            $senaryolar['firewall · filtre sil (arayüze bağlıyken)'] = Scenario::expectsRejection(
                'Filtre bir arayüze bağlı; cihaz silinmesine izin vermemeli.',
                fn () => $filters->delete($switch, $filter),
            );
        }

        return $senaryolar;
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function policies(NetworkSwitch $switch): array
    {
        $policies = app(PolicyStatementConfigurator::class);
        $statement = $switch->policyStatements()->with('terms')->first();
        $community = $switch->communities()->first();

        $terim = [
            'name' => 'dogrulama',
            'match_prefix_lists' => $switch->prefixLists()->pluck('name')->take(1)->all(),
            'match_type' => PrefixMatchType::OrLonger,
            'action' => PolicyAction::Accept,
            'community_delete' => null,
        ];

        $senaryolar = [
            'politika · oluştur' => fn () => $policies->create($switch, 'dogrulama-politika', [$terim]),
            'community · oluştur' => fn () => $policies->createCommunity($switch, 'dogrulama-community', ['65000:100']),

            /*
             * POLİTİKANIN VARSAYILAN EYLEMİ: terimlerden sonra gelen
             * "then reject". Bütün BGP süzgeçlerinin standart biçimi;
             * okunmazsa güncellemede silinir ve politika "gerisini
             * reddet"ten "gerisini de al"a döner.
             */
            'politika · varsayılan eylem (then reject)' => fn () => $policies->create(
                $switch,
                'dogrulama-varsayilan',
                [$terim],
                ['action' => PolicyAction::Reject],
            ),

            // route-filter: eşleşme türleriyle. Koşullar match_conditions'ta.
            'politika · route-filter orlonger' => fn () => $policies->create($switch, 'dogrulama-rf', [[
                'name' => 'a',
                'match_conditions' => PolicyMatch::blank() + [
                    'route_filters' => [['address' => '10.0.0.0/8', 'match_type' => 'orlonger']],
                ],
                'action' => PolicyAction::Accept,
            ]]),

            'politika · route-filter prefix-length-range' => fn () => $policies->create($switch, 'dogrulama-rf2', [[
                'name' => 'a',
                'match_conditions' => PolicyMatch::blank() + [
                    'route_filters' => [[
                        'address' => '10.0.0.0/8',
                        'match_type' => 'prefix-length-range',
                        'value' => '/24-/32',
                    ]],
                ],
                'action' => PolicyAction::Accept,
            ]]),

            // as-path tanımı ve ona dayanan terim.
            'politika · as-path tanımı' => fn () => $policies->createAsPath($switch, 'dogrulama-aspath', '.* 65000'),

            'politika · korunan ham satır' => fn () => $policies->create(
                $switch,
                'dogrulama-artik',
                [$terim],
                ['residual_xml' => ['<then><reject/></then>']],
            ),
        ];

        if ($statement !== null) {
            $senaryolar['politika · güncelle'] = fn () => $policies->update($switch, $statement, [$terim]);
            $senaryolar['politika · sil (referanslıyken)'] = Scenario::expectsRejection(
                'Politikaya atıf yapan bir yapılandırma var; cihaz silinmesine izin vermemeli.',
                fn () => $policies->delete($switch, $statement),
            );
        }

        if ($community !== null) {
            $senaryolar['community · güncelle'] = fn () => $policies->updateCommunity($switch, $community, ['65000:200']);
            $senaryolar['community · sil'] = fn () => $policies->deleteCommunity($switch, $community);
        }

        return $senaryolar;
    }

    /**
     * Cihazın KENDİ AS'inden farklı bir dış komşu AS'i.
     *
     * Aynısı verilirse cihaz reddediyor: "external peer's AS must not be the
     * same as the local AS". Sabit bir numara yazmak, o numaraya sahip bir
     * switchte panelde hata yokken kırmızı satır üretir.
     */
    private function disKomsuAsn(NetworkSwitch $switch, string $tercih): string
    {
        return (string) $switch->autonomous_system === $tercih
            ? (string) ((int) $tercih + 9)
            : $tercih;
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function bgp(NetworkSwitch $switch): array
    {
        $bgp = app(BgpConfigurator::class);
        $group = $switch->bgpGroups()->first() ?? new BgpGroup(['name' => 'dogrulama']);
        $group->switch_id = $switch->id;
        $neighbor = $switch->bgpNeighbors()->first();

        /*
         * DIŞ komşunun AS'i cihazın KENDİ AS'inden farklı olmalı; cihaz
         * aksini reddediyor ("external peer's AS must not be the same as
         * the local AS"). Sabit 65001 yazmak, AS'i 65001 olan bir switchte
         * birden çok senaryoyu kırmızı yakar — panelde hata yokken.
         */
        $komsuAsn = $this->disKomsuAsn($switch, '65001');

        $ayarlar = [
            'name' => 'dogrulama-grup',
            'type' => BgpGroupType::External,
            'peer_asn' => $komsuAsn,
            'local_address' => $switch->uplink_ip ?? '198.18.8.38',
            'local_asn' => null,
            'authentication_key' => 'dogrulama-anahtar',
            'hold_time' => 30,
            'cluster' => null,
            'graceful_restart' => BgpGracefulRestart::Enabled,
            'remove_private' => true,
            'as_override' => true,
            'advertise_inactive' => true,
            'multipath' => true,
            'damping' => true,
            'out_delay' => 15,
            'bfd_minimum_interval' => 300,
            'allow_prefixes' => [],
            'multihop' => true,
            'multihop_ttl' => 5,
            'log_updown' => true,
            'passive' => true,
            'import_policies' => [],
            'export_policies' => [],
            'families' => [[
                'family' => IpFamily::Inet,
                'nlri' => BgpNlri::Any,
                'prefix_limit_maximum' => 200,
                'teardown' => true,
                'teardown_threshold' => 90,
                'idle_timeout_forever' => false,
                'idle_timeout_minutes' => 30,
            ]],
        ];

        $senaryolar = [
            'bgp · as numarası' => fn () => $bgp->setAutonomousSystem($switch, (string) ($switch->autonomous_system ?? '65000')),
            'bgp · grup oluştur (tam)' => fn () => $bgp->createGroup($switch, $ayarlar),
            'bgp · grup: dinamik komşu' => fn () => $bgp->createGroup($switch, [
                ...$ayarlar,
                'name' => 'dogrulama-dinamik',
                'authentication_key' => null,
                'allow_prefixes' => ['192.0.2.0/24'],
            ]),
            'bgp · grup güncelle' => fn () => $bgp->updateGroup($switch, $group, $ayarlar),
            /*
             * Cihazda "deactivate": nesne silinmez, çalışmaz. İşaret
             * özniteliktedir; geri açmak active="active" ile olur.
             */
            'bgp · grubu devre dışı bırak' => fn () => $bgp->updateGroup(
                $switch, $group, $ayarlar + ['is_inactive' => true],
            ),
            'bgp · grubu geri aç' => fn () => $bgp->updateGroup(
                $switch, $group, $ayarlar + ['is_inactive' => false],
            ),

            'bgp · grup sil' => fn () => $bgp->deleteGroup($switch, $group),
            'bgp · komşu ekle' => fn () => $bgp->createNeighbor($switch, $group, [
                'address' => '192.0.2.9',
                'description' => 'dogrulama',
                'peer_asn' => $this->disKomsuAsn($switch, '65002'),
                'local_address' => null,
                'authentication_key' => 'komsu-anahtar',
                'hold_time' => 45,
                'passive' => true,
                'multihop' => true,
                'multihop_ttl' => 2,
                'import_policies' => [],
                'export_policies' => [],
            ]),
        ];

        if ($neighbor !== null) {
            $senaryolar['bgp · komşu güncelle'] = fn () => $bgp->updateNeighbor($switch, $neighbor, [
                'description' => 'dogrulama',
                'peer_asn' => null,
                'local_address' => null,
                'authentication_key' => null,
                'hold_time' => null,
                'passive' => false,
                'multihop' => false,
                'multihop_ttl' => null,
                'import_policies' => [],
                'export_policies' => [],
            ]);
            $senaryolar['bgp · komşu sil'] = fn () => $bgp->deleteNeighbor($switch, $neighbor);
        }

        return $senaryolar;
    }

    /**
     * @return array<string, callable|Scenario>
     */
    /**
     * ae ayarları yalnızca GERÇEK bir toplama arayüzünde ölçülebilir.
     *
     * ae0 yoksa fiziksel porta düşülmez: cihaz haklı olarak "syntax error"
     * verir ve rapora panel hatası gibi geçerdi. Ön koşul yoksa senaryo
     * gerekçesiyle atlanır.
     *
     * @return array<string, callable|Scenario>
     */
    private function aggregation(NetworkSwitch $switch, ?SwitchPort $ae): array
    {
        $lag = app(LinkAggregationConfigurator::class);

        $sayim = [
            'toplama · ae sayısı' => fn () => $lag->setDeviceCount($switch, (int) ($switch->aggregated_device_count ?: 4)),
        ];

        if ($ae === null) {
            $atla = static function (): never {
                throw new ScenarioNotApplicable('Cihazda ae0 tanımlı değil; ae ayarı ölçülemez.');
            };

            return $sayim + [
                'toplama · ae ayarları' => $atla,
                'toplama · ae sil' => $atla,
            ];
        }

        return $sayim + [
            'toplama · ae ayarları' => fn () => $lag->apply($switch, $ae, [
                'lacp_mode' => LacpMode::Active,
                'lacp_periodic' => LacpPeriodic::Fast,
                'minimum_links' => 1,
                'link_speed' => null,
            ], null, ['description' => 'dogrulama']),
            'toplama · ae sil' => fn () => $lag->delete($switch, $ae),
        ];
    }

    /**
     * @return array<string, callable|Scenario>
     */
    private function device(NetworkSwitch $switch): array
    {
        $archival = app(ArchivalWriter::class);

        return [
            'snmp · ad yaz' => fn () => app(SnmpIdentityWriter::class)->rename($switch, $switch->hostname),
            'yedek · aç' => function () use ($archival, $switch): void {
                $kopya = $switch->replicate();
                $kopya->id = $switch->id;
                $kopya->archival_enabled = true;
                // Gerçek adres depoda durmaz; ortamda verilmişse o, yoksa örnek bir adres.
                $kopya->archival_site = config('archival.site') ?: 'ftp://yedek:ornek-parola@192.0.2.51/sw1/';
                $archival->apply($kopya);
            },
            'yedek · kapat' => function () use ($archival, $switch): void {
                $kopya = $switch->replicate();
                $kopya->id = $switch->id;
                $kopya->archival_enabled = false;
                $archival->apply($kopya);
            },
        ];
    }
}
