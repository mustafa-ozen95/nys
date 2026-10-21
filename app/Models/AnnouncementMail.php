<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\AnnouncementMailFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gönderilen bir anons talebi maili.
 */
class AnnouncementMail extends Model
{
    /** @use HasFactory<AnnouncementMailFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'user_id',
        'recipient_label',
        'recipient_email',
        'locale',
        'subject',
        'body',
        'prefixes',
        'asn',
        'downstream_asn',
        'status',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => MailStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markSent(): void
    {
        $this->update(['status' => MailStatus::Sent, 'sent_at' => now(), 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => MailStatus::Failed, 'error' => $error]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('recipient_email', 'like', "%{$value}%")
                ->orWhere('recipient_label', 'like', "%{$value}%")
                ->orWhere('prefixes', 'like', "%{$value}%")
                ->orWhere('asn', 'like', "%{$value}%")
        ));
    }
}
