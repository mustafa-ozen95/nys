<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchCommitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchCommit extends Model
{
    /** @use HasFactory<SwitchCommitFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'switch_id',
        'sequence_number',
        'committed_by',
        'comment',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'committed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<NetworkSwitch, $this> */
    public function switch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class);
    }
}
