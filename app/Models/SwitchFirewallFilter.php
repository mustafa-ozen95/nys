<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FirewallFamily;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchFirewallFilterFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cihazdaki bir firewall filtresi.
 *
 * Panelin en tehlikeli yazma yolu buradan geçer; yetkisi yalnızca
 * kendi yetkisindedir (switch_firewall kaynağı); "cihaza yaz" onu
 * KAPSAMAZ ve yetki switch bazında verilir.
 *
 * is_manageable HER ZAMAN true'dur ve sütun geriye dönük uyum için duruyor:
 * panelin tanımadığı satırlar residual_xml'de ham hâlleriyle saklanıp geri
 * yazıldığı için "yarısını anlayıp geri yazma" tehlikesi yoktur.
 */
class SwitchFirewallFilter extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<SwitchFirewallFilterFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_id',
        'family',
        'name',
        'interface_specific',
        'is_manageable',
        'bindings',
        'residual_xml',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'family' => FirewallFamily::class,
            'interface_specific' => 'boolean',
            'is_manageable' => 'boolean',
            'bindings' => 'array',
            'residual_xml' => 'array',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * Filtre her güncellemede silinip yeniden yazılır (terim sırası anlamlı);
     * korunan satırlar ham hâlleriyle geri basılır. Kullanıcı neyin
     * taşındığını bilmeli; filtre bu yüzden salt okunur sayılmaz, korunan
     * satırlar ekranda gösterilir.
     *
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml];
    }

    /** @return HasMany<SwitchFirewallTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(SwitchFirewallTerm::class, 'switch_firewall_filter_id')->orderBy('position');
    }

    /**
     * Filtrenin nerede kullanıldığı: "ge-0/0/23.0 çıkış".
     *
     * Bir filtreyi düzenlemeden önce nerede bağlı olduğunu görmek şarttır;
     * bağlı olduğu yeri bilmeden yapılan değişiklik hangi trafiği
     * etkileyeceğini de bilmez.
     *
     * @return Attribute<string, never>
     */
    protected function bindingSummary(): Attribute
    {
        return Attribute::get(function (): string {
            $bindings = $this->bindings ?? [];

            if ($bindings === []) {
                return 'Hiçbir arayüze bağlı değil';
            }

            return implode(', ', array_map(
                static fn (array $binding): string => $binding['interface']
                    .' '.($binding['direction'] === 'input' ? 'giriş' : 'çıkış'),
                $bindings,
            ));
        });
    }

    /**
     * Bağlı olmayan filtre hiçbir trafiği etkilemez; düzenlemesi de risksizdir.
     *
     * @return Attribute<bool, never>
     */
    protected function isBound(): Attribute
    {
        return Attribute::get(fn (): bool => ($this->bindings ?? []) !== []);
    }
}
