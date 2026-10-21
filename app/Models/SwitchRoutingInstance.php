<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoutingInstanceType;
use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchRoutingInstanceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cihazdaki bir yönlendirme tablosu (routing-instance).
 *
 * Kimliği ADIDIR: firewall terimi ("then routing-instance <ad>") ve rib-group
 * tanımları hep adla atıfta bulunur.
 */
class SwitchRoutingInstance extends Model
{
    /** @use HasFactory<SwitchRoutingInstanceFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'instance_type',
        'description',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instance_type' => RoutingInstanceType::class,
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Bu tablonun içindeki route'lar.
     *
     * @return HasMany<SwitchRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(SwitchRoute::class, 'switch_id', 'switch_id')
            ->where('routing_instance', $this->name);
    }

    /**
     * Panelin göstermediği, ham hâliyle korunan satır sayısı.
     *
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => $this->residual_xml ?? []);
    }
}
