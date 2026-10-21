<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeVariant;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchFirewallPolicerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihazdaki bir firewall policer'ı: terimin çağırdığı hız sınırı.
 *
 * Cihazda "firewall policer <ad>" olarak, filtrenin DIŞINDA durur; bu yüzden
 * filtreye değil switche bağlıdır. Terim onu "then policer <ad>" ile çağırır
 * ve tanımsız bir ada atıf commit'i düşürür — form yalnızca envanterde olan
 * policer'ı seçtirir.
 */
class SwitchFirewallPolicer extends Model
{
    /** @use HasFactory<SwitchFirewallPolicerFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_id',
        'name',
        'filter_specific',
        'bandwidth_limit',
        'bandwidth_percent',
        'burst_size_limit',
        'is_discard',
        'forwarding_class',
        'loss_priority',
        'residual_xml',
    ];

    protected function casts(): array
    {
        return [
            'filter_specific' => 'boolean',
            'bandwidth_percent' => 'integer',
            'is_discard' => 'boolean',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Ekrandaki tek satırlık özet: "1m · 15k · düşür".
     *
     * Blade hesap yapmaz; özet burada kurulur.
     *
     * @return Attribute<string, never>
     */
    protected function summary(): Attribute
    {
        return Attribute::get(function (): string {
            $parcalar = array_filter([
                $this->bandwidth_limit !== null ? "hız {$this->bandwidth_limit}" : null,
                $this->bandwidth_percent !== null ? "hız %{$this->bandwidth_percent}" : null,
                $this->burst_size_limit !== null ? "burst {$this->burst_size_limit}" : null,
                $this->filter_specific ? 'filtre başına' : null,
                $this->is_discard ? 'aşanı düşür' : null,
                $this->forwarding_class !== null ? "sınıf {$this->forwarding_class}" : null,
                $this->loss_priority !== null ? "kayıp önceliği {$this->loss_priority}" : null,
            ]);

            return $parcalar === [] ? 'Sınır tanımsız' : implode(' · ', $parcalar);
        });
    }

    /**
     * Aşan pakete ne olduğunu söyleyen rozet. Blade hesap yapmaz.
     *
     * @return Attribute<BadgeVariant, never>
     */
    protected function badge(): Attribute
    {
        return Attribute::get(fn (): BadgeVariant => $this->is_discard ? BadgeVariant::Red : BadgeVariant::Yellow);
    }

    /** @return Attribute<string, never> */
    protected function stateLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->is_discard ? 'aşanı düşür' : 'işaretle');
    }

    /**
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml];
    }
}
