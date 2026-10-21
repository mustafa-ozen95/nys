<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use App\Support\Netconf\PolicyMatch;
use App\Support\Netconf\PolicyThen;
use Database\Factories\SwitchPolicyStatementFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cihazdaki bir policy-statement.
 *
 * BGP grubunun import/export alanı buradaki adlara atıfta bulunur; cihazda
 * tanımlı olmayan bir ada atıf commit'i düşürür.
 *
 * default_match / default_then politikanın KENDİ from/then'idir: terimlerin
 * hepsi düştüğünde uygulanan varsayılan davranış. "then reject;" bütün BGP
 * süzgeçlerinin standart biçimidir ve panel onu okumadığı sürece güncellemede
 * siliyordu.
 *
 * is_manageable artık HEP true'dur: modellenmeyen satırlar residual_xml ile
 * korunduğu için hiçbir politika salt okunur kalmak zorunda değil. Sütun
 * geriye dönük uyumluluk için duruyor.
 */
class SwitchPolicyStatement extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<SwitchPolicyStatementFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_id',
        'name',
        'default_match',
        'default_then',
        'residual_xml',
        'is_manageable',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'is_manageable' => 'boolean',
            'default_match' => 'array',
            'default_then' => 'array',
            'residual_xml' => 'array',
            'is_inactive' => 'boolean',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /** @return HasMany<SwitchPolicyTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(SwitchPolicyTerm::class)->orderBy('position');
    }

    /**
     * Politikanın varsayılan davranışının tek satırlık özeti.
     *
     * Ekranda ayrı bir satır olarak durur: "gerisini reddet" ile "gerisini de
     * al" arasındaki fark buradan okunur.
     *
     * @return Attribute<string, never>
     */
    protected function defaultSummary(): Attribute
    {
        return Attribute::get(function (): string {
            $kosul = PolicyMatch::summary($this->default_match);
            $eylem = PolicyThen::summary($this->default_then);

            if ($kosul === [] && $eylem === []) {
                return 'Varsayılan davranış tanımlı değil; terimler düşerse politika sessiz kalır.';
            }

            $parcalar = [$kosul === [] ? 'her şey' : implode(', ', $kosul)];
            $parcalar[] = $eylem === [] ? 'eylem yok' : implode(', ', $eylem);

            return implode(' · ', $parcalar);
        });
    }

    /**
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [
            $this->residual_xml,
            $this->default_match['residual'] ?? null,
            $this->default_then['residual'] ?? null,
        ];
    }
}
