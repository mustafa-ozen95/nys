<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchCommunityFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihazdaki bir BGP community tanımı.
 *
 * Politika terimleri buna atıfta bulunur; tanımsız bir ada atıf commit'i
 * düşürür.
 *
 * invert-match tanımın anlamını TERSİNE çevirir: "üyelerden hiçbirini
 * taşımayan" demektir. Panel bu satırı okumadığı sürece güncellemede siliyor
 * ve eşleşme sessizce tersine dönüyordu.
 */
class SwitchCommunity extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<SwitchCommunityFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_id',
        'name',
        'members',
        'invert_match',
        'residual_xml',
    ];

    protected function casts(): array
    {
        return [
            'members' => 'array',
            'invert_match' => 'boolean',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function memberList(): Attribute
    {
        return Attribute::get(function (): string {
            $liste = implode(', ', $this->members ?? []);

            return $this->invert_match ? "{$liste} (invert-match)" : $liste;
        });
    }

    /**
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml];
    }
}
