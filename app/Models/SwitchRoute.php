<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpFamily;
use App\Enums\RouteFlag;
use App\Enums\RouteProtocol;
use App\Models\Concerns\HasUuid;
use App\Support\Netconf\ResidualXml;
use Database\Factories\SwitchRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchRoute extends Model
{
    /** @use HasFactory<SwitchRouteFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'destination',
        'prefix_length',
        'next_hop',
        'next_hops',
        'qualified_next_hops',
        'rib',
        'routing_instance',
        'preference',
        'metric',
        'tag',
        'tag2',
        'flags',
        'communities',
        'next_table',
        'residual_xml',
        'unmanageable_reason',
        'protocol',
        'family',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'next_hops' => 'array',
            'qualified_next_hops' => 'array',
            'flags' => 'array',
            'communities' => 'array',
            'residual_xml' => 'array',
            'preference' => 'integer',
            'metric' => 'integer',
            'protocol' => RouteProtocol::class,
            'family' => IpFamily::class,
            'prefix_length' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Panelde gösterilmeyen ama KORUNAN satırların adları.
     *
     * Kullanıcı neyin taşındığını bilmeli: "bu route'ta panelin göstermediği
     * iki satır var ve kaydedince aynen korunacak".
     *
     * @return Attribute<list<string>, never>
     */
    protected function preservedLines(): Attribute
    {
        return Attribute::get(fn (): array => ResidualXml::names($this->residual_xml));
    }

    /** @return Attribute<string, never> */
    protected function cidr(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->destination}/{$this->prefix_length}");
    }

    /**
     * YOL NE YAPIYOR: yönlendir, düşür, reddet ya da yerel al.
     *
     * Junos bunu ayrı bir alanda tutmaz; "next-hop" değerinin kendisi
     * Discard/Reject/Receive olabilir. Yazma tarafı bu eşlemeyi zaten
     * biliyor (SwitchRouteRequest::nextHops); okuma tarafı da bilmeliydi ve
     * bir süre formun Blade'inde hesaplanıyordu. Aynı sözlüğün iki ucu artık
     * iki yerde duruyor ama ikisi de KOD; ekran yalnızca soruyor.
     *
     * @return Attribute<string, never>
     */
    protected function action(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            strcasecmp((string) $this->next_hop, 'Reject') === 0 => 'reject',
            strcasecmp((string) $this->next_hop, 'Receive') === 0 => 'receive',
            $this->next_hop === null,
            strcasecmp((string) $this->next_hop, 'Discard') === 0 => 'discard',
            default => 'next-hop',
        });
    }

    /**
     * Cihazdan okunan bayrakların TANIDIĞIMIZ olanları.
     *
     * Ekran her bayrağı enum'a çevirip tanımayanı elemek zorundaydı ve bunu
     * Blade içinde yapıyordu. Tanıma işi modelin: görünüm hazır listeyi basar.
     *
     * @return Attribute<list<RouteFlag>, never>
     */
    protected function knownFlags(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_filter(
            array_map(RouteFlag::tryFrom(...), $this->flags ?? []),
        )));
    }

    /**
     * Formda düzenlenebilir atlama adresleri.
     *
     * Düşür/reddet/yerel al durumunda atlama YOKTUR: oradaki değer bir adres
     * değil, eylemin kendisidir ve adres kutusuna basılırsa kullanıcı onu
     * gerçek bir sonraki atlama sanır.
     *
     * @return Attribute<list<string>, never>
     */
    protected function editableNextHops(): Attribute
    {
        return Attribute::get(fn (): array => $this->action !== 'next-hop'
            ? []
            : array_values(array_filter($this->next_hops ?: [$this->next_hop])));
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('destination', 'like', "%{$value}%")
                ->orWhere('next_hop', 'like', "%{$value}%")
        ));
    }
}
