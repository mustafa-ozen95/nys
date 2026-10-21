<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir arayüzün tek bir andaki trafik ölçümü.
 *
 * Yalnızca ölçüm cihazdan okunduğunda yazılır; izleme sistemi kullanılırken
 * geçmiş orada olduğu için bu tablo boş kalır.
 */
class SwitchPortSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'switch_port_id',
        'sampled_at',
        'in_bps',
        'out_bps',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'immutable_datetime',
            'in_bps' => 'integer',
            'out_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<SwitchPort, $this> */
    public function port(): BelongsTo
    {
        return $this->belongsTo(SwitchPort::class, 'switch_port_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeBetween(Builder $query, int $from, int $to): void
    {
        $query->whereBetween('sampled_at', [
            (new \DateTimeImmutable)->setTimestamp($from),
            (new \DateTimeImmutable)->setTimestamp($to),
        ]);
    }
}
