<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchRstpInterfaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RSTP'nin bir arayüz için ayarları.
 *
 * Arayüz adı port tablosuna bağlanmaz: Junos "all" diye sanal bir ad da
 * kabul eder ve o adın portlar arasında karşılığı yoktur.
 *
 * "edge" bir portu uç nokta sayar ve dinleme/öğrenme aşamalarını atlatır;
 * yanlış porta verilirse ağ döngüsü o portta anında oluşur. Bu yüzden
 * yazma ayrı yetki ister ve onay penceresi switch adı yazdırır.
 */
class SwitchRstpInterface extends Model
{
    /** @use HasFactory<SwitchRstpInterfaceFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'interface_name',
        'edge',
        'no_root_port',
        'cost',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'edge' => 'boolean',
            'no_root_port' => 'boolean',
            'cost' => 'integer',
            'priority' => 'integer',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_id');
    }
}
