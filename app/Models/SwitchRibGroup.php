<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchRibGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir RIB grubu: hangi yönlendirme tablolarına kopyalanacağını söyler.
 *
 * Kimliği ADIDIR; "interface-routes rib-group inet <ad>" ona adla atıfta
 * bulunur.
 */
class SwitchRibGroup extends Model
{
    /** @use HasFactory<SwitchRibGroupFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'name',
        'import_ribs',
        'residual_xml',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'import_ribs' => 'array',
            'residual_xml' => 'array',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }
}
