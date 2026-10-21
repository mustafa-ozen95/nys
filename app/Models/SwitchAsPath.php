<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\PreservesDeviceLines;
use Database\Factories\SwitchAsPathFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cihazdaki bir as-path tanımı (policy-options as-path <ad> { path "..."; }).
 *
 * Politika terimleri "from as-path <ad>" ile buna atıfta bulunur; community'de
 * olduğu gibi tanımsız bir ada atıf commit'i düşürür. Bu yüzden terim
 * formundaki as-path listesi serbest metin değil, buradan doldurulur.
 *
 * path bir DÜZENLİ İFADEDİR ve Junos'un kendi as-path söz dizimini kullanır:
 * ".* 65000" = "yolun sonu 65000 olan her duyuru".
 */
class SwitchAsPath extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<SwitchAsPathFactory> */
    use HasFactory;

    use HasUuid;
    use PreservesDeviceLines;

    protected $fillable = [
        'switch_id',
        'name',
        'path',
        'residual_xml',
    ];

    protected function casts(): array
    {
        return ['residual_xml' => 'array'];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }

    /**
     * @return list<list<string>|null>
     */
    protected function residualSources(): array
    {
        return [$this->residual_xml];
    }
}
