<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tek bir çalışma zamanı ayarı.
 *
 * Anahtar, üzerine bindirilen config yoludur ("zabbix.url"); değer JSON
 * metnidir. Tip çevrimi ve şifreleme SettingsRepository'de yapılır — model
 * yalnızca satırı taşır.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
