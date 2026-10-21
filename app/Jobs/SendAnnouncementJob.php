<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AnnouncementRequestMail;
use App\Models\AnnouncementMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Anons talebini tek bir alıcıya gönderir.
 *
 * Her alıcı ayrı iş: biri başarısız olursa diğerleri etkilenmez ve yalnızca
 * başarısız olan yeniden denenir. Sonuç kayda işlenir, böylece geçmiş
 * listesinde gönderimin akıbeti görünür.
 */
class SendAnnouncementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $mailId)
    {
        /*
         * Kuyruk AÇIKÇA seçilir. Seçilmezse iş "default" kuyruğuna düşer ve
         * onu dinleyen hiçbir işçi yoktur: mail alınır, hiç işlenmez.
         */
        $this->onQueue(config('announcements.queue'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $record = AnnouncementMail::find($this->mailId);

        if ($record === null) {
            return;
        }

        Mail::to($record->recipient_email)
            ->send(new AnnouncementRequestMail($record->subject, $record->body));

        $record->markSent();
    }

    public function failed(?Throwable $exception): void
    {
        AnnouncementMail::find($this->mailId)?->markFailed(
            $exception?->getMessage() ?? 'Bilinmeyen hata.',
        );
    }
}
