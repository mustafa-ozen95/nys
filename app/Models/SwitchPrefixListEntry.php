<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpFamily;
use App\Models\Concerns\HasUuid;
use Database\Factories\SwitchPrefixListEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchPrefixListEntry extends Model
{
    /** @use HasFactory<SwitchPrefixListEntryFactory> */
    use HasFactory;

    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'switch_prefix_list_id',
        'address',
        'prefix_length',
        'family',
    ];

    protected function casts(): array
    {
        return [
            'family' => IpFamily::class,
            'prefix_length' => 'integer',
        ];
    }

    /** @return Attribute<string, never> */
    protected function cidr(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->address}/{$this->prefix_length}");
    }

    /** @return BelongsTo<SwitchPrefixList, $this> */
    public function prefixList(): BelongsTo
    {
        return $this->belongsTo(SwitchPrefixList::class, 'switch_prefix_list_id');
    }
}
