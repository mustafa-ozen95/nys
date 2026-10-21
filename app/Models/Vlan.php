<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\VlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Vlan extends Model
{
    /** @use HasFactory<VlanFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'description',
        'vlan_tag',
        'l3_interface',
    ];

    protected function casts(): array
    {
        return [
            'vlan_tag' => 'integer',
        ];
    }

    /**
     * VLAN'a üye portların adları.
     *
     * Listede sayının yanında hangi portlar olduğu da görünsün diye tek
     * dizge olarak verilir; ports ilişkisi önceden yüklenmiş olmalı.
     */
    protected function portList(): Attribute
    {
        return Attribute::get(fn (): string => $this->ports->pluck('name')->implode(', '));
    }

    /**
     * Listelerde görünen ad: "100 · ofis", etiketsizse "etiketsiz · V27".
     *
     * Etiket ZORUNLU DEĞİLDİR (cihaz "vlans <ad>" satırını vlan-id olmadan
     * kabul ediyor); biçim tek yerde durmazsa etiketsiz VLAN ekranlarda
     * " · V27" diye başsız görünürdü.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => ($this->vlan_tag ?? 'etiketsiz').' · '.$this->name);
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /** @return BelongsToMany<SwitchPort, $this> */
    public function ports(): BelongsToMany
    {
        return $this->belongsToMany(SwitchPort::class, 'switch_port_vlan')->withPivot('is_untagged');
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('vlan_tag', $value)
        ));
    }
}
