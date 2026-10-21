<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpFamily;
use App\Models\Concerns\HasUuid;
use App\Support\Net\Cidr;
use Database\Factories\SwitchIpAddressFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchIpAddress extends Model
{
    /** @use HasFactory<SwitchIpAddressFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'switch_port_id',
        'vlan_id',
        'interface_name',
        'address',
        'prefix_length',
        'family',
    ];

    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'prefix_length' => 'integer',
        ];
    }

    /** @return Attribute<string, never> */
    protected function cidr(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->address}/{$this->prefix_length}");
    }

    public function toCidr(): Cidr
    {
        return Cidr::make($this->address, $this->prefix_length);
    }

    /**
     * Adresin durduğu VLAN. Fiziksel portta duruyorsa boştur.
     *
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    /**
     * Adresin nereye bağlı olduğu; formda hangi alanın açılacağını belirler.
     * Ne VLAN, ne port, ne loopback ise me0.0 gibi bir arayüzdür.
     */
    protected function attachment(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->vlan_id !== null => 'vlan',
            $this->switch_port_id !== null => 'port',
            str_starts_with($this->interface_name, 'lo0.') => 'loopback',
            default => 'other',
        });
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class, 'switch_port_id');
    }
}
