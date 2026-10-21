<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FirewallAction;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchFirewallTermFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firewall filtresinin bir terimi. Sıra anlamlıdır (bkz. position).
 *
 * Koşul ve eylem kümesi Junos'un pratik yüzeyini kapsar; geri kalan uzun
 * kuyruk ham XML olarak saklanır ve geri yazılırken aynen basılır
 * (residual_xml, from_residual_xml, then_residual_xml). Üçü ayrı durur çünkü
 * gövde yazarken üç ayrı yerde yeniden kuruluyor: terimin kendisi, "from" ve
 * "then".
 */
class SwitchFirewallTerm extends Model
{
    /** @use HasFactory<SwitchFirewallTermFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    /**
     * Ekrandaki özetin kurulduğu koşul listeleri: alan => etiket.
     *
     * @var array<string, string>
     */
    private const CONDITION_LABELS = [
        'source_addresses' => 'kaynak',
        'destination_addresses' => 'hedef',
        'source_prefix_lists' => 'kaynak listesi',
        'destination_prefix_lists' => 'hedef listesi',
        'protocols' => 'protokol',
        'next_headers' => 'sonraki başlık',
        'ports' => 'port',
        'source_ports' => 'kaynak port',
        'destination_ports' => 'hedef port',
        'icmp_types' => 'icmp',
        'icmp_codes' => 'icmp kodu',
        'dscps' => 'dscp',
        'traffic_classes' => 'trafik sınıfı',
        'precedences' => 'öncelik',
        'packet_lengths' => 'paket boyu',
        'ttls' => 'ttl',
        'ip_options' => 'ip seçeneği',
        'fragment_flags' => 'parça bayrağı',
        'interfaces' => 'arayüz',
        'ether_types' => 'ether türü',
        'source_mac_addresses' => 'kaynak MAC',
        'destination_mac_addresses' => 'hedef MAC',
    ];

    protected $fillable = [
        'switch_firewall_filter_id',
        'name',
        'position',

        // from
        'source_addresses',
        'destination_addresses',
        'source_prefix_lists',
        'source_prefix_list_excepts',
        'destination_prefix_lists',
        'destination_prefix_list_excepts',
        'protocols',
        'ports',
        'source_ports',
        'destination_ports',
        'icmp_types',
        'icmp_codes',
        'tcp_flags',
        'is_tcp_initial',
        'is_tcp_established',
        'dscps',
        'precedences',
        'packet_lengths',
        'ttls',
        'ip_options',
        'fragment_flags',
        'is_fragment',
        'is_first_fragment',
        'interfaces',
        'next_headers',
        'traffic_classes',
        'ether_types',
        'source_mac_addresses',
        'destination_mac_addresses',

        // then
        'action',
        'goes_to_next_term',
        'routing_instance',
        'policer',
        'forwarding_class',
        'loss_priority',
        'dscp',
        'analyzer',
        'is_port_mirrored',
        'is_sampled',
        'is_syslogged',
        'count_name',
        'is_logged',

        // korunan ham satırlar
        'residual_xml',
        'from_residual_xml',
        'then_residual_xml',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'source_addresses' => 'array',
            'destination_addresses' => 'array',
            'source_prefix_lists' => 'array',
            'source_prefix_list_excepts' => 'array',
            'destination_prefix_lists' => 'array',
            'destination_prefix_list_excepts' => 'array',
            'protocols' => 'array',
            'ports' => 'array',
            'source_ports' => 'array',
            'destination_ports' => 'array',
            'icmp_types' => 'array',
            'icmp_codes' => 'array',
            'is_tcp_initial' => 'boolean',
            'is_tcp_established' => 'boolean',
            'dscps' => 'array',
            'precedences' => 'array',
            'packet_lengths' => 'array',
            'ttls' => 'array',
            'ip_options' => 'array',
            'fragment_flags' => 'array',
            'is_fragment' => 'boolean',
            'is_first_fragment' => 'boolean',
            'interfaces' => 'array',
            'next_headers' => 'array',
            'traffic_classes' => 'array',
            'ether_types' => 'array',
            'source_mac_addresses' => 'array',
            'destination_mac_addresses' => 'array',
            'action' => FirewallAction::class,
            'goes_to_next_term' => 'boolean',
            'is_port_mirrored' => 'boolean',
            'is_sampled' => 'boolean',
            'is_syslogged' => 'boolean',
            'is_logged' => 'boolean',
            'residual_xml' => 'array',
            'from_residual_xml' => 'array',
            'then_residual_xml' => 'array',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<SwitchFirewallFilter, $this> */
    public function filter(): BelongsTo
    {
        return $this->belongsTo(SwitchFirewallFilter::class, 'switch_firewall_filter_id');
    }

    /**
     * Korunan satırlar ÜÇ yerde durur ve kullanıcı için hepsi tek bir
     * gerçektir: "bu terimde panelin göstermediği N satır var".
     *
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml, $this->from_residual_xml, $this->then_residual_xml];
    }

    /**
     * Terimin ekrandaki tek satırlık özeti.
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $kosullar = $this->conditionSummary();

            // Koşulsuz terim HER ŞEYE uyar; genellikle zinciri kapatan sonuncudur.
            $parcalar = [$kosullar === [] ? 'her şey' : implode(' · ', $kosullar)];

            return implode(' · ', [...$parcalar, ...$this->actionSummary()]);
        });
    }

    /**
     * @return list<string>
     */
    private function conditionSummary(): array
    {
        $kosullar = [];

        foreach (self::CONDITION_LABELS as $alan => $etiket) {
            $deger = $this->listed($etiket, $this->{$alan});

            if ($deger !== null) {
                $kosullar[] = $deger;
            }
        }

        return [...$kosullar, ...array_filter([
            $this->tcp_flags !== null ? "tcp bayrağı {$this->tcp_flags}" : null,
            $this->is_tcp_initial ? 'tcp ilk paket' : null,
            $this->is_tcp_established ? 'tcp kurulu' : null,
            $this->is_fragment ? 'parçalı' : null,
            $this->is_first_fragment ? 'ilk parça' : null,
        ])];
    }

    /**
     * @return list<string>
     */
    private function actionSummary(): array
    {
        return array_values(array_filter([
            $this->count_name !== null ? "say: {$this->count_name}" : null,
            $this->is_logged ? 'günlüğe yaz' : null,
            $this->is_syslogged ? 'syslog' : null,
            $this->policer !== null ? "policer {$this->policer}" : null,
            $this->forwarding_class !== null ? "sınıf {$this->forwarding_class}" : null,
            $this->loss_priority !== null ? "kayıp önceliği {$this->loss_priority}" : null,
            $this->dscp !== null ? "dscp {$this->dscp}" : null,
            $this->routing_instance !== null ? "tablo {$this->routing_instance}" : null,
            $this->analyzer !== null ? "analyzer {$this->analyzer}" : null,
            $this->is_port_mirrored ? 'port aynala' : null,
            $this->is_sampled ? 'örnekle' : null,
            $this->goes_to_next_term ? 'sonraki terime geç' : ($this->action?->label() ?? 'eylem yok'),
        ]));
    }

    /**
     * @param  ?array<int, string|int>  $values
     */
    private function listed(string $label, ?array $values): ?string
    {
        return $values === null || $values === []
            ? null
            : $label.' '.implode('/', $values);
    }
}
