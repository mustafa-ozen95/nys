<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminStatus;
use App\Enums\Duplex;
use App\Enums\LacpMode;
use App\Enums\LacpPeriodic;
use App\Enums\PortMode;
use App\Enums\PortStatus;
use App\Enums\PortType;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsObservations;
use Database\Factories\SwitchPortFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SwitchPort extends Model
{
    /** @use HasFactory<SwitchPortFactory> */
    use HasFactory;

    use HasUuid;
    use RecordsObservations;

    protected $fillable = [
        'switch_id',
        'name',
        'if_index',
        'description',
        'interface_kind',
        'admin_status',
        'oper_status',
        'speed_config',
        'duplex_config',
        'speed_mbps',
        'duplex',
        'is_poe_enabled',
        'is_autonegotiation_enabled',
        'port_mode',
        'last_polled_at',
        'aggregate_port_id',
        'lacp_mode',
        'lacp_periodic',
        'minimum_links',
        'link_speed',
        'mtu',
    ];

    protected function casts(): array
    {
        return [
            'if_index' => 'integer',
            'admin_status' => AdminStatus::class,
            'oper_status' => PortStatus::class,
            'duplex' => Duplex::class,
            'port_mode' => PortMode::class,
            'interface_kind' => PortType::class,
            'lacp_mode' => LacpMode::class,
            'lacp_periodic' => LacpPeriodic::class,
            'minimum_links' => 'integer',
            'mtu' => 'integer',
            'speed_mbps' => 'integer',
            'is_poe_enabled' => 'boolean',
            'is_autonegotiation_enabled' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * Portun cihazda açık olup olmadığı. Blade enum karşılaştırması yapmasın
     * diye burada durur.
     *
     * @return Attribute<bool, never>
     */
    protected function isEnabled(): Attribute
    {
        return Attribute::get(fn (): bool => $this->admin_status === AdminStatus::Up);
    }

    /**
     * Access modunda mı. Formda VLAN alanının hangi biçimde çizileceğini
     * belirler; Blade enum karşılaştırması yapmasın diye burada durur.
     *
     * @return Attribute<bool, never>
     */
    protected function isAccess(): Attribute
    {
        return Attribute::get(fn (): bool => $this->port_mode !== PortMode::Trunk);
    }

    /**
     * Toplama arayüzü mü (ae0 gibi)? Blade enum karşılaştırması yapmasın diye
     * burada durur.
     *
     * @return Attribute<bool, never>
     */
    protected function isAggregate(): Attribute
    {
        return Attribute::get(fn (): bool => $this->interface_kind === PortType::Aggregated);
    }

    /**
     * Toplama arayüzünün cihazdaki sırası: "ae3" -> 3.
     *
     * device-count bu sıradan BÜYÜK olmalı; değilse cihaz arayüzü hiç
     * yaratmaz ve yapılandırma sessizce çalışmaz.
     *
     * @return Attribute<?int, never>
     */
    protected function aggregateIndex(): Attribute
    {
        return Attribute::get(fn (): ?int => preg_match('/^ae(\d+)$/', $this->name, $m) === 1
            ? (int) $m[1]
            : null);
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /**
     * Portun üye olduğu toplama arayüzü.
     *
     * @return BelongsTo<SwitchPort, $this>
     */
    public function aggregate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'aggregate_port_id');
    }

    /**
     * Toplama arayüzünün üye portları.
     *
     * @return HasMany<SwitchPort, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(self::class, 'aggregate_port_id');
    }

    /** @return BelongsToMany<Vlan, $this> */
    public function vlans(): BelongsToMany
    {
        return $this->belongsToMany(Vlan::class, 'switch_port_vlan')->withPivot('is_untagged');
    }

    /** @return HasMany<SwitchIpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(SwitchIpAddress::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeAggregated(Builder $query): void
    {
        $query->where('interface_kind', PortType::Aggregated);
    }

    /** @param  Builder<$this>  $query */
    public function scopePhysical(Builder $query): void
    {
        $query->where('interface_kind', PortType::Physical);
    }

    /** @param  Builder<$this>  $query */
    public function scopeUp(Builder $query): void
    {
        $query->where('oper_status', PortStatus::Up);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%")
        ));
    }
}
