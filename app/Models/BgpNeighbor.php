<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BgpState;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Support\Format\Duration;
use Database\Factories\BgpNeighborFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir BGP komşusu.
 *
 * Durum ve sayaçlar $fillable DIŞINDADIR: onları yalnızca cihazdan okuyan
 * eşitleme yazar. Forma açılsalardı panel, kullanıcının yazdığı bir durumu
 * cihazın gerçeği gibi gösterirdi — eski bgp_sessions tablosunun hatası
 * tam olarak buydu.
 */
class BgpNeighbor extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<BgpNeighborFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'bgp_group_id',
        'switch_id',
        'address',
        'description',
        'peer_asn',
        'local_address',

        /*
         * Junos grup ayarlarının hemen hepsini komşu seviyesinde de kabul
         * eder ve komşudaki değer grubunkini EZER. Sağlayıcı başına ayrı
         * MD5 anahtarı en yaygın kullanımdır.
         */
        'authentication_key',
        'hold_time',
        'passive',
        'multihop',
        'multihop_ttl',
        'import_policies',
        'export_policies',
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

    /**
     * Cihazdan okunan alanlar. Eşitleme bunları doğrudan yazar.
     *
     * @var list<string>
     */
    public const STATE_FIELDS = [
        'state',
        'active_prefixes',
        'received_prefixes',
        'accepted_prefixes',
        'flap_count',
        'state_changed_seconds',
    ];

    protected function casts(): array
    {
        return [
            'state' => BgpState::class,

            // Junos "$9$..." biçimi çözülebilir; düz metin kadar korunur.
            'authentication_key' => 'encrypted',
            'hold_time' => 'integer',
            'passive' => 'boolean',
            'multihop' => 'boolean',
            'multihop_ttl' => 'integer',
            'import_policies' => 'array',
            'export_policies' => 'array',
            'active_prefixes' => 'integer',
            'received_prefixes' => 'integer',
            'accepted_prefixes' => 'integer',
            'flap_count' => 'integer',
            'state_changed_seconds' => 'integer',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<BgpGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BgpGroup::class, 'bgp_group_id');
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Komşunun geçerli AS'i: kendi değeri yoksa grubunki.
     *
     * @return Attribute<?string, never>
     */
    protected function effectivePeerAsn(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->peer_asn ?? $this->group?->peer_asn);
    }

    /**
     * Oturumda GERÇEKTEN geçerli olan değerler.
     *
     * Junos'ta komşudaki değer grubunkini ezer; boş bırakılan alanda grubun
     * değeri geçerlidir. Bu kararı okuyan her yer (müşteri configi, ekran)
     * kendi başına hesaplasaydı biri güncellenmeden kalırdı.
     *
     * @return Attribute<bool, never>
     */
    protected function effectiveMultihop(): Attribute
    {
        return Attribute::get(fn (): bool => $this->multihop || (bool) $this->group?->multihop);
    }

    /** @return Attribute<?int, never> */
    protected function effectiveMultihopTtl(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->multihop
            ? $this->multihop_ttl
            : $this->group?->multihop_ttl);
    }

    /** @return Attribute<bool, never> */
    protected function effectivePassive(): Attribute
    {
        return Attribute::get(fn (): bool => $this->passive || (bool) $this->group?->passive);
    }

    /** @return Attribute<?int, never> */
    protected function effectiveHoldTime(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->hold_time ?? $this->group?->hold_time);
    }

    /** @return Attribute<?string, never> */
    protected function effectiveAuthenticationKey(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->authentication_key ?? $this->group?->authentication_key);
    }

    /**
     * Komşuda ayrıntı sayılan alanlardan biri dolu mu?
     *
     * Formda ayrıntılar katlanmış durur: komşu eklemek günlük iş, hold-time
     * ile multihop'a dokunmak değil. Ama dolu bir ayrıntı katlı kalırsa ekran
     * cihazdaki gerçeği saklamış olur; grup o durumda AÇIK açılır.
     */
    public function hasDetailSettings(): bool
    {
        return filled($this->local_address)
            || filled($this->authentication_key)
            || $this->hold_time !== null
            || $this->passive
            || $this->multihop
            || filled($this->import_policies)
            || filled($this->export_policies);
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function stateChangedFor(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->state_changed_seconds === null
            ? null
            : Duration::format($this->state_changed_seconds));
    }

    /** @param  Builder<$this>  $query */
    public function scopeEstablished(Builder $query): void
    {
        $query->where('state', BgpState::Established->value);
    }
}
