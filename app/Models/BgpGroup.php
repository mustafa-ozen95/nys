<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BgpGracefulRestart;
use App\Enums\BgpGroupType;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use Database\Factories\BgpGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cihazdaki bir BGP peer grubu.
 *
 * Junos'ta komşu daima bir gruba bağlıdır, o yüzden grup panelin de giriş
 * noktasıdır: tür, politika ve prefix sınırı burada durur, komşular altına
 * asılır.
 */
class BgpGroup extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<BgpGroupFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'type',
        'peer_asn',
        'local_asn',
        'local_address',
        'authentication_key',
        'hold_time',
        'cluster',
        'graceful_restart',
        'remove_private',
        'as_override',
        'advertise_inactive',
        'multipath',
        'damping',
        'out_delay',
        'bfd_minimum_interval',
        'allow_prefixes',
        'multihop',
        'multihop_ttl',
        'log_updown',
        'passive',
        'import_policies',
        'export_policies',
        'description',
        'is_inactive',
    ];

    /**
     * Oturum anahtarı kimlik taşır: diziye ve JSON'a çıkmaz.
     *
     * @var list<string>
     */
    protected $hidden = [
        'authentication_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => BgpGroupType::class,
            'graceful_restart' => BgpGracefulRestart::class,

            // Junos "$9$..." biçimi çözülebilir; düz metin kadar korunur.
            'authentication_key' => 'encrypted',
            'hold_time' => 'integer',
            'out_delay' => 'integer',
            'bfd_minimum_interval' => 'integer',
            'remove_private' => 'boolean',
            'as_override' => 'boolean',
            'advertise_inactive' => 'boolean',
            'multipath' => 'boolean',
            'damping' => 'boolean',
            'allow_prefixes' => 'array',
            'multihop' => 'boolean',
            'multihop_ttl' => 'integer',
            'log_updown' => 'boolean',
            'passive' => 'boolean',
            'import_policies' => 'array',
            'export_policies' => 'array',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /** @return HasMany<BgpNeighbor, $this> */
    public function neighbors(): HasMany
    {
        return $this->hasMany(BgpNeighbor::class);
    }

    /** @return HasMany<BgpGroupFamily, $this> */
    public function families(): HasMany
    {
        return $this->hasMany(BgpGroupFamily::class);
    }

    /**
     * Cihazda görünen hâliyle politika zinciri: "prefixes-from-musteri1".
     *
     * @return Attribute<string, never>
     */
    protected function importPolicyList(): Attribute
    {
        return Attribute::get(fn (): string => implode(', ', $this->import_policies ?? []));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function exportPolicyList(): Attribute
    {
        return Attribute::get(fn (): string => implode(', ', $this->export_policies ?? []));
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('peer_asn', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%")
                ->orWhereHas('neighbors', fn (Builder $n) => $n->where('address', 'like', "%{$value}%"))
        ));
    }
}
