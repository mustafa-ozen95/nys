<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailLocale;
use App\Models\Concerns\AuthorizesAccess;
use App\Models\Concerns\HasUuid;
use Database\Factories\AnnouncementRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnouncementRecipient extends Model
{
    use AuthorizesAccess;

    /** @use HasFactory<AnnouncementRecipientFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'label',
        'email',
        'locale',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'locale' => MailLocale::class,
            'is_active' => 'boolean',
        ];
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
