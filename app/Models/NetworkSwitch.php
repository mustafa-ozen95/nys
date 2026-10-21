<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialProtocol;
use App\Enums\JunosSchemaProfile;
use App\Enums\MeasurementSource;
use App\Enums\PortType;
use App\Enums\SwitchLocation;
use App\Enums\SwitchRole;
use App\Enums\SwitchStatus;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsObservations;
use App\Support\Format\Duration;
use Database\Factories\NetworkSwitchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class NetworkSwitch extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<NetworkSwitchFactory> */
    use HasFactory;

    use HasUuid;
    use RecordsObservations;
    use SoftDeletes;

    /**
     * Sınıf adı PHP'de "Switch" olamadığı için NetworkSwitch'tir. Eloquent
     * bundan "network_switches" ve "network_switch_id" türetir; tablo adı ve
     * yabancı anahtar bu yüzden tek yerden düzeltilir.
     */
    protected $table = 'switches';

    protected $fillable = [
        'hostname',
        'management_ip',
        'uplink_ip',
        'autonomous_system',
        'aggregated_device_count',
        'interface_routes_rib_group',
        'model',
        'serial_number',
        'junos_version',
        'schema_profile',
        'storm_control_shutdown',
        'role',
        'location',
        'status',
        'zabbix_host_id',
        'measurement_source',
        'archival_enabled',
        'archival_site',
        'snmp_location',
        'snmp_contact',
        'time_zone',
        'name_servers',
        'ntp_servers',
        'syslog_hosts',
        'rstp_configured',
        'rstp_enabled',
        'rstp_bridge_priority',
        'rstp_bpdu_block_on_edge',
        'alarm_ethernet_link_down',
        'alarm_management_link_down',
        'uptime_seconds',
        'cpu_usage',
        'memory_usage',
        'temperature',
        'last_sync_at',
    ];

    /**
     * Yedek adresi kimlik taşır (ftp://kullanici:parola@...): diziye ve
     * JSON'a çıkmaz.
     *
     * @var list<string>
     */
    protected $hidden = [
        'archival_site',
        'snmp_location',
        'snmp_contact',
        'time_zone',
        'name_servers',
        'ntp_servers',
        'syslog_hosts',
        'rstp_configured',
        'rstp_enabled',
        'rstp_bridge_priority',
        'rstp_bpdu_block_on_edge',
        'alarm_ethernet_link_down',
        'alarm_management_link_down',
    ];

    protected function casts(): array
    {
        return [
            'schema_profile' => JunosSchemaProfile::class,

            /*
             * Cihazın ÖLÇÜLMÜŞ yetenekleri: yetenek anahtarı => kabul edilen
             * değerler. Toplu atamada YOKTUR; yalnızca sonda yazar
             * (bkz. App\Services\Capabilities\CapabilityProbe).
             */
            'device_capabilities' => 'array',

            'storm_control_shutdown' => 'boolean',
            'role' => SwitchRole::class,
            'location' => SwitchLocation::class,
            'status' => SwitchStatus::class,
            'measurement_source' => MeasurementSource::class,
            'archival_enabled' => 'boolean',

            /*
             * Yedek adresi kimlik içerir (ftp://kullanici:parola@...):
             * şifreli saklanır ve hiçbir listede basılmaz.
             */
            'archival_site' => 'encrypted',
            'name_servers' => 'array',
            'ntp_servers' => 'array',
            'lldp_interfaces' => 'array',
            'lldp_med_interfaces' => 'array',
            'igmp_snooping_vlans' => 'array',
            'clock_offset_seconds' => 'integer',
            'clock_checked_at' => 'datetime',
            'syslog_hosts' => 'array',
            'rstp_configured' => 'boolean',
            'rstp_enabled' => 'boolean',
            'rstp_bpdu_block_on_edge' => 'boolean',
            'aggregated_device_count' => 'integer',
            'uptime_seconds' => 'integer',
            'cpu_usage' => 'integer',
            'memory_usage' => 'integer',
            'temperature' => 'integer',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * Cihaza yazılacak yedek adresi.
     *
     * Adres cihaza özgüdür; girilmemişse .env'deki varsayılan sunucu
     * kullanılır. Böylece tek sunuculu kurulumda her switche ayrı ayrı
     * adres girmek gerekmez, farklı sahadaki cihaz da kendi sunucusunu
     * gösterebilir.
     *
     * @return Attribute<?string, never>
     */
    protected function archivalTarget(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->archival_site ?: (config('archival.site') ?: null));
    }

    public function getForeignKey(): string
    {
        return 'switch_id';
    }

    /**
     * Cihazın çalışma süresi, okunur biçimde.
     *
     * Değer SyncSwitchStatusJob ile okunur; kaynağı ayarlarda seçilir.
     * Biçimlendirme burada durur; Blade hesap yapmaz.
     *
     * @return Attribute<string, never>
     */
    protected function uptimeForHumans(): Attribute
    {
        return Attribute::get(fn (): string => $this->uptime_seconds === null
            ? '—'
            : Duration::format($this->uptime_seconds));
    }

    /** @return HasMany<SwitchLoginUser, $this> */
    public function loginUsers(): HasMany
    {
        return $this->hasMany(SwitchLoginUser::class, 'switch_id');
    }

    /**
     * Parola değiştirme turları, yenisi başta.
     *
     * @return HasMany<SwitchPasswordRotation, $this>
     */
    public function passwordRotations(): HasMany
    {
        return $this->hasMany(SwitchPasswordRotation::class, 'switch_id')->latest('id');
    }

    /**
     * En son parola değiştirme turu.
     *
     * @return HasOne<SwitchPasswordRotation, $this>
     */
    public function latestPasswordRotation(): HasOne
    {
        return $this->hasOne(SwitchPasswordRotation::class, 'switch_id')->latestOfMany();
    }

    /** @return HasMany<SwitchSnmpCommunity, $this> */
    public function snmpCommunities(): HasMany
    {
        return $this->hasMany(SwitchSnmpCommunity::class, 'switch_id');
    }

    /** @return HasMany<SwitchRstpInterface, $this> */
    /**
     * Fırtına denetimi ayarları.
     *
     * @return HasMany<SwitchStormControl, $this>
     */
    public function stormControls(): HasMany
    {
        return $this->hasMany(SwitchStormControl::class, 'switch_id');
    }

    /**
     * Kuyruğa alınmış cihaz yazmaları.
     *
     * @return HasMany<SwitchWriteJob, $this>
     */
    public function writeJobs(): HasMany
    {
        return $this->hasMany(SwitchWriteJob::class, 'switch_id');
    }

    public function rstpInterfaces(): HasMany
    {
        return $this->hasMany(SwitchRstpInterface::class, 'switch_id');
    }

    /**
     * DHCP snooping / erişim güvenliği: VLAN kuralları.
     *
     * @return HasMany<SwitchSecureVlan, $this>
     */
    public function secureVlans(): HasMany
    {
        return $this->hasMany(SwitchSecureVlan::class, 'switch_id');
    }

    /**
     * DHCP snooping / erişim güvenliği: arayüz kuralları.
     *
     * @return HasMany<SwitchSecureInterface, $this>
     */
    public function secureInterfaces(): HasMany
    {
        return $this->hasMany(SwitchSecureInterface::class, 'switch_id');
    }

    /**
     * Port aynalama (analyzer) oturumları.
     *
     * @return HasMany<SwitchAnalyzer, $this>
     */
    public function analyzers(): HasMany
    {
        return $this->hasMany(SwitchAnalyzer::class, 'switch_id');
    }

    /**
     * VoIP (ses) VLAN ayarları.
     *
     * @return HasMany<SwitchVoipInterface, $this>
     */
    public function voipInterfaces(): HasMany
    {
        return $this->hasMany(SwitchVoipInterface::class, 'switch_id');
    }

    /** @return HasMany<SwitchCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(SwitchCredential::class);
    }

    /** @return HasOne<SwitchCredential, $this> */
    public function sshCredential(): HasOne
    {
        return $this->hasOne(SwitchCredential::class)->where('protocol', CredentialProtocol::Ssh->value);
    }

    /**
     * NETCONF kimliği. Tanımlı değilse NetconfClient SSH kimliğine düşer —
     * çoğu kurulumda aynı hesap kullanılır, ama port 830'dur.
     *
     * @return HasOne<SwitchCredential, $this>
     */
    public function netconfCredential(): HasOne
    {
        return $this->hasOne(SwitchCredential::class)->where('protocol', CredentialProtocol::Netconf->value);
    }

    /** @return HasOne<SwitchCredential, $this> */
    public function snmpCredential(): HasOne
    {
        return $this->hasOne(SwitchCredential::class)->where('protocol', CredentialProtocol::SnmpV2c->value);
    }

    /** @return HasMany<SwitchPort, $this> */
    public function ports(): HasMany
    {
        return $this->hasMany(SwitchPort::class);
    }

    /**
     * Toplama arayüzleri (ae0, ae1...).
     *
     * İç içe rotalar bunun üzerinden çözülür: hem switche ait olması hem de
     * gerçekten bir ae olması böyle zorunlu kılınır.
     *
     * @return HasMany<SwitchPort, $this>
     */
    public function aggregates(): HasMany
    {
        return $this->hasMany(SwitchPort::class)->where('interface_kind', PortType::Aggregated->value);
    }

    /** @return HasMany<Vlan, $this> */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    /** @return HasMany<SwitchIpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(SwitchIpAddress::class);
    }

    /** @return HasMany<SwitchRibGroup, $this> */
    public function ribGroups(): HasMany
    {
        return $this->hasMany(SwitchRibGroup::class, 'switch_id');
    }

    /** @return HasMany<SwitchRoutingInstance, $this> */
    public function routingInstances(): HasMany
    {
        return $this->hasMany(SwitchRoutingInstance::class, 'switch_id');
    }

    /** @return HasMany<SwitchRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(SwitchRoute::class);
    }

    /** @return HasMany<SwitchPrefixList, $this> */
    public function prefixLists(): HasMany
    {
        return $this->hasMany(SwitchPrefixList::class);
    }

    /** @return HasMany<SwitchCommit, $this> */
    public function commits(): HasMany
    {
        return $this->hasMany(SwitchCommit::class);
    }

    /** @return HasMany<SwitchFirewallFilter, $this> */
    public function firewallFilters(): HasMany
    {
        return $this->hasMany(SwitchFirewallFilter::class, 'switch_id');
    }

    /**
     * Firewall policer'ları: terimlerin çağırdığı hız sınırları.
     *
     * Cihazda filtrenin DEĞİL, "firewall" düğümünün doğrudan altındadırlar;
     * bu yüzden filtreye değil switche bağlıdırlar.
     *
     * @return HasMany<SwitchFirewallPolicer, $this>
     */
    public function firewallPolicers(): HasMany
    {
        return $this->hasMany(SwitchFirewallPolicer::class, 'switch_id');
    }

    /** @return HasMany<SwitchCommunity, $this> */
    public function communities(): HasMany
    {
        return $this->hasMany(SwitchCommunity::class, 'switch_id');
    }

    /** @return HasMany<SwitchPolicyStatement, $this> */
    public function policyStatements(): HasMany
    {
        return $this->hasMany(SwitchPolicyStatement::class, 'switch_id');
    }

    /**
     * policy-options as-path tanımları. Politika terimi "from as-path <ad>"
     * ile bunlara atıfta bulunur; tanımsız ada atıf commit'i düşürür.
     *
     * @return HasMany<SwitchAsPath, $this>
     */
    public function asPaths(): HasMany
    {
        return $this->hasMany(SwitchAsPath::class, 'switch_id');
    }

    /** @return HasMany<BgpGroup, $this> */
    public function bgpGroups(): HasMany
    {
        return $this->hasMany(BgpGroup::class, 'switch_id');
    }

    /**
     * Komşular gruplara asılıdır; switch üzerinden doğrudan erişim, durum
     * eşitlemesi ve listeleme için gerekir (grup gezmeden tek sorgu).
     *
     * @return HasMany<BgpNeighbor, $this>
     */
    public function bgpNeighbors(): HasMany
    {
        return $this->hasMany(BgpNeighbor::class, 'switch_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('hostname', 'like', "%{$value}%")
                ->orWhere('management_ip', 'like', "%{$value}%")
                ->orWhere('uplink_ip', 'like', "%{$value}%")
        ));
    }

    /**
     * Önce ilgilenilmesi gereken switchler. Sıra enum'da tanımlıdır; Query
     * Builder'da CASE karşılığı olmadığı için tek raw ifade burada durur ve
     * değerler bağlanarak geçilir (MySQL ve SQLite'ta aynı çalışır).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrderBySeverity(Builder $query): void
    {
        $cases = collect(SwitchStatus::cases())
            ->sortBy(fn (SwitchStatus $status): int => $status->severity());

        $expression = 'case '.$cases
            ->map(fn (SwitchStatus $status): string => 'when status = ? then '.$status->severity())
            ->implode(' ').' else '.count(SwitchStatus::cases()).' end';

        $query->orderByRaw($expression, $cases->pluck('value')->all());
    }

    /**
     * Omurgalar üstte.
     *
     * Bir omurga kesintisi altındaki bütün erişim switchlerini etkiler;
     * listeye bakan kişi önce onu görmeli. Rolü BİLİNMEYEN switch en sona
     * düşer — uydurulmuş bir sıra, yanlış bir sıradır.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrderByRole(Builder $query): void
    {
        $roller = collect(SwitchRole::cases())->sortBy(
            fn (SwitchRole $rol): int => $rol->listingOrder(),
        );

        $ifade = 'case '.$roller
            ->map(fn (SwitchRole $rol): string => 'when role = ? then '.$rol->listingOrder())
            ->implode(' ').' else '.count(SwitchRole::cases()).' end';

        $query->orderByRaw($ifade, $roller->pluck('value')->all());
    }

    /** @param  Builder<$this>  $query */
    public function scopeOnline(Builder $query): void
    {
        $query->where('status', SwitchStatus::Online);
    }
}
