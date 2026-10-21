<?php

declare(strict_types=1);

namespace App\Services\Switching;

use App\Enums\AddressPurpose;
use App\Enums\CredentialProtocol;
use App\Enums\JunosUserClass;
use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Models\NetworkSwitch;
use App\Services\Addressing\AddressCandidateService;
use App\Support\Format\Duration;
use Carbon\CarbonInterval;

/**
 * Switch ekranlarının hazır verisini üretir.
 *
 * Blade yalnızca basar: biçimlendirme, varsayılan değer ve seçenek listeleri
 * burada hazırlanır.
 */
class SwitchViewService
{
    public function __construct(private AddressCandidateService $candidates) {}

    /**
     * @return array<string, mixed>
     */
    public function detail(NetworkSwitch $switch): array
    {
        $ssh = $switch->credentials->firstWhere('protocol', CredentialProtocol::Ssh);
        $snmp = $switch->credentials->firstWhere('protocol', CredentialProtocol::SnmpV2c);
        $netconf = $switch->credentials->firstWhere('protocol', CredentialProtocol::Netconf);

        return [
            'switch' => $switch,
            'uptime' => $switch->uptime_seconds === null ? null : Duration::format($switch->uptime_seconds),
            'lastSync' => $switch->last_sync_at?->diffForHumans(),
            // Cihaza yazılacak SNMP adı için öneri: envanterdeki switch adı.
            'snmpName' => $switch->hostname,
            'access' => [
                'SSH kullanıcısı' => $ssh?->username,
                'SSH portu' => $ssh?->port,
                'SSH parolası' => $ssh?->password === null ? 'Tanımlı değil' : 'Kayıtlı',
                'SNMP community' => $snmp?->community === null ? 'Tanımlı değil' : 'Kayıtlı',
                'SNMP portu' => $snmp?->port,
                'NETCONF' => $netconf === null ? 'Tanımlı değil' : 'Kayıtlı (port '.$netconf->port.')',
            ],
            'inventory' => [
                'Seri numarası' => $switch->serial_number,
                // Ölçümün nereden geldiği görünmeli: yedeğe düşüldüğünde
                // kullanıcı bunu sessizce değil, ekranda öğrenmeli.
                'Ölçüm kaynağı' => $switch->measurement_source?->label() ?? 'Henüz ölçülmedi',
                'Arayüz' => $switch->ports->count(),
                'VLAN' => $switch->vlans->count(),
                'Zabbix host' => $switch->zabbix_host_id ?? 'Eşleşmedi',
            ],
        ];
    }

    /**
     * Bir protokolün ekrandaki hâli.
     *
     * NULL "cihazda tanımlı değil", liste "tanımlı ve şu kapsamda" demektir;
     * ikisini karıştırmak açık bir protokolü kapalı göstermek olurdu.
     *
     * @param  list<string>|null  $scope
     * @return array{enabled: bool, scope: string}
     */
    private function protocolScope(?array $scope): array
    {
        return [
            'enabled' => $scope !== null,
            'scope' => implode(PHP_EOL, $scope ?? []),
        ];
    }

    /**
     * Cihaz saatinin panelden sapması — EŞİĞİ AŞARSA.
     *
     * Cihaz saati yanlışsa (ör. pil bitmiş, NTP sunucusu tanımlı ama
     * erişilemiyor) commit geçmişi ve günlük sıralaması yanıltıcı olur.
     * Panel bunu göstermezse sapma yalnızca commit geçmişindeki tarihlerden
     * fark edilir, o da kolayca gözden kaçar.
     *
     * Eşiğin altındaki fark GÖSTERİLMEZ: birkaç saniyelik sapma normaldir ve
     * her ekrana uyarı koymak uyarıyı değersizleştirir.
     *
     * @return array{seconds: int, label: string, checked_at: ?string}|null
     */
    private function clockDrift(NetworkSwitch $switch): ?array
    {
        $sapma = $switch->clock_offset_seconds;
        $esik = (int) config('measurement.clock_drift_threshold');

        if ($sapma === null || abs($sapma) < $esik) {
            return null;
        }

        return [
            'seconds' => $sapma,
            'label' => CarbonInterval::seconds(abs($sapma))->cascade()->forHumans(short: true, parts: 2),
            'checked_at' => $switch->clock_checked_at?->diffForHumans(),
        ];
    }

    /**
     * Yedek adresi kimlik taşır: ekrana DEĞERİ değil, tanımlı olup olmadığı
     * çıkar. Blade karar vermez, burada karar verilir.
     *
     * @return array<string, mixed>
     */
    public function settings(NetworkSwitch $switch): array
    {
        return [
            'snmpName' => $switch->hostname,
            'archivalCustom' => $switch->archival_site !== null,
            'archivalTargetKnown' => $switch->archival_target !== null,

            // Cihazdan okunan community'ler; adları ekranda maskeli durur.
            'communities' => $switch->snmpCommunities()->orderBy('id')->get(),

            'authorizationOptions' => [
                'read-only' => 'Yalnızca okuma',
                'read-write' => 'Okuma ve yazma',
            ],

            /*
             * Liste alanları çok satırlı kutuda gösterilir; Junos'ta sıra
             * anlamlıdır (ilk yanıt veren kullanılır).
             */
            'nameServers' => $switch->name_servers ?? [],
            'ntpServers' => $switch->ntp_servers ?? [],
            'ntpBootServer' => $switch->ntp_boot_server,

            /*
             * Protokol anahtarları: açık mı ve kapsamı ne. Blade karar
             * vermez; null "kapalı", liste "açık" demektir.
             */
            'protocols' => [
                'lldp' => $this->protocolScope($switch->lldp_interfaces),
                'lldp_med' => $this->protocolScope($switch->lldp_med_interfaces),
                'igmp_snooping' => $this->protocolScope($switch->igmp_snooping_vlans),
            ],
            'clockDrift' => $this->clockDrift($switch),
            'syslogHosts' => $switch->syslog_hosts ?? [],

            /*
             * Cihaz üzerindeki yerel giriş hesapları. Parola SAKLANMAZ;
             * yalnızca tanımlı olup olmadığı gösterilir.
             */
            'loginUsers' => $switch->loginUsers()->orderBy('name')->get(),
            'userClassOptions' => collect(JunosUserClass::cases())
                ->mapWithKeys(fn (JunosUserClass $sinif): array => [$sinif->value => $sinif->label()])
                ->all(),

            /*
             * Şasi alarmları. Boş seçenek satırı cihazdan siler; cihaz
             * kendi varsayılanına döner.
             */
            'alarmOptions' => [
                '' => 'Cihaz varsayılanı',
                'ignore' => 'Yok say',
                'yellow' => 'Sarı (uyarı)',
                'red' => 'Kırmızı (kritik)',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function form(?NetworkSwitch $switch): array
    {
        $ssh = $switch?->credentials->firstWhere('protocol', CredentialProtocol::Ssh);
        $snmp = $switch?->credentials->firstWhere('protocol', CredentialProtocol::SnmpV2c);
        $netconf = $switch?->credentials->firstWhere('protocol', CredentialProtocol::Netconf);

        return [
            'switch' => $switch,
            'roleOptions' => $this->options(SwitchRole::cases()),
            'locationOptions' => $this->options(SwitchLocation::cases()),
            'sshUsername' => $ssh?->username ?? 'admin',
            'sshPort' => $ssh?->port ?? 22,
            'snmpPort' => $snmp?->port ?? 161,
            // NETCONF kimliği yoksa switch yalnızca görüntülenebilir.
            'netconfEnabled' => $netconf !== null,
            'netconfUsername' => $netconf?->username,
            'netconfPort' => $netconf?->port ?? config('netconf.port'),

            /*
             * Adresler Adresleme'den seçilir. Elle giriş açıktır ve bu bir
             * istisnadır: yeni bir cihaz envantere alınırken yönetim adresi
             * henüz Adresleme'de tanımlı olmayabilir. Kayıtlı bir adres
             * listede yoksa bileşen zaten elle giriş kutusunu açar.
             */
            'managementOptions' => $this->candidates->hostOptions([AddressPurpose::Management]),
            'uplinkOptions' => $this->candidates->hostOptions([
                AddressPurpose::Uplink,
                AddressPurpose::Peering,
            ]),
        ];
    }

    /**
     * @param  array<int, SwitchRole|SwitchLocation>  $cases
     * @return array<string, string>
     */
    private function options(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (SwitchRole|SwitchLocation $case): array => [$case->value => $case->label()])
            ->all();
    }
}
