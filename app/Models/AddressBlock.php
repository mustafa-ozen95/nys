<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressConnectionType;
use App\Enums\AddressPurpose;
use App\Enums\IpFamily;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Support\Net\Cidr;
use Database\Factories\AddressBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddressBlock extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<AddressBlockFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'parent_id',
        'network',
        'prefix_length',
        'range_start',
        'range_end',
        'family',
        'purpose',
        'description',
        'notes',
        'is_subnetted',
        'core_switch_id',
        'access_switch_id',
        'connection_type',
        'vlan_id',
        'port_name',
        'next_hop',
        'switch_prefix_list_id',
        'is_announced',
    ];

    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'connection_type' => AddressConnectionType::class,
            'purpose' => AddressPurpose::class,
            'prefix_length' => 'integer',
            'is_subnetted' => 'boolean',
            'is_announced' => 'boolean',
        ];
    }

    /** @return Attribute<string, never> */
    protected function cidr(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->network}/{$this->prefix_length}");
    }

    public function toCidr(): Cidr
    {
        return Cidr::make($this->network, $this->prefix_length);
    }

    /**
     * Blok bir switch VLAN'ına veya portuna bağlanmışsa kullanımdadır.
     *
     * @return Attribute<bool, never>
     */
    protected function isAssigned(): Attribute
    {
        return Attribute::get(fn (): bool => $this->connection_type !== null);
    }

    /**
     * Atamanın hedefi: "VLAN 30" veya "ge-0/0/5".
     *
     * @return Attribute<?string, never>
     */
    protected function assignmentTarget(): Attribute
    {
        return Attribute::get(fn (): ?string => match ($this->connection_type) {
            // Etiket varsa o gösterilir; etiketsiz VLAN adıyla anılır.
            AddressConnectionType::Vlan => $this->vlan === null
                ? null
                : 'VLAN '.($this->vlan->vlan_tag ?? $this->vlan->name),
            AddressConnectionType::Port => $this->port_name,
            AddressConnectionType::NextHop => $this->next_hop,
            AddressConnectionType::PrefixList => $this->prefixList?->name,
            default => null,
        });
    }

    /** @return BelongsTo<AddressBlock, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<AddressBlock, $this> */
    public function subnets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Bloğun üzerinde durduğu omurga switch. Blok router üzerindeyse boştur.
     *
     * @return BelongsTo<NetworkSwitch, $this>
     */
    public function coreSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'core_switch_id');
    }

    /**
     * Bloğun route edildiği erişim switchi.
     *
     * @return BelongsTo<NetworkSwitch, $this>
     */
    /**
     * Bloğun girdiği prefix list. Liste omurga switchte durur ve cihazdan
     * eşitlenir; erişim switchiyle ilgisi yoktur.
     *
     * @return BelongsTo<SwitchPrefixList, $this>
     */
    /**
     * Bloğun bağlandığı VLAN.
     *
     * Bağ ETİKETLE değil kayıtla kurulur: etiket tekildir ama kalıcı değil,
     * ayrıca cihazda etiketsiz VLAN da geçerlidir.
     *
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    public function prefixList(): BelongsTo
    {
        return $this->belongsTo(SwitchPrefixList::class, 'switch_prefix_list_id');
    }

    public function accessSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'access_switch_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeAnnounced(Builder $query): void
    {
        $query->where('is_announced', true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeAssigned(Builder $query): void
    {
        $query->whereNotNull('connection_type');
    }

    /**
     * Başka bir yere ATANABİLİR bloklar.
     *
     * Bir blok bir switchin VLAN'ına, portuna ya da route'una bağlandığı anda
     * TÜKENİR: o aralık artık o cihazdadır ve ikinci bir yere verilmesi
     * çakışma demektir. Sahibi olan switchin kendi ekranında görünmeye devam
     * eder — orada düzenlenebilmeli.
     *
     * Bölünmüş blok da listelenmez: o bir havuzdur, adres taşımaz; atanacak
     * olan alt ağlarıdır.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAssignable(Builder $query, ?NetworkSwitch $switch = null): void
    {
        $query->where('is_subnetted', false)
            ->where(function (Builder $q) use ($switch): void {
                $q->whereNull('connection_type');

                if ($switch !== null) {
                    $q->orWhere('core_switch_id', $switch->getKey())
                        ->orWhere('access_switch_id', $switch->getKey());
                }
            });
    }

    /** @param  Builder<$this>  $query */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Verilen aralıkla kesişen bloklar. Çakışma kontrolü uygulamada değil
     * veritabanında yapılır.
     *
     * Aralık PAKETLENMİŞ BAYT'tır (inet_pton): 128 bit adres tamsayıya
     * sığmıyor. AİLE de süzülür — bir IPv4 bloğu bir IPv6 bloğuyla çakışamaz
     * ve baytları karşılaştırmak anlamsız olurdu.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOverlapping(Builder $query, string $start, string $end, IpFamily $family): void
    {
        $query->where('family', $family)
            ->where('range_start', '<=', $end)
            ->where('range_end', '>=', $start);
    }

    /**
     * Bloğu bir Cidr değerine çevirir; matematik tek yerde kalsın.
     */
    public function cidrValue(): Cidr
    {
        return Cidr::make((string) $this->network, (int) $this->prefix_length);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('network', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%")
        ));
    }
}
