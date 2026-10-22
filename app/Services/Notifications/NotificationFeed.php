<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\BadgeVariant;
use App\Enums\SwitchWriteStatus;
use App\Models\DeviceOperation;
use App\Models\NetworkSwitch;
use App\Models\SwitchWriteJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Panelin bildirim akışı: OLAN BİTEN.
 *
 * İki kaynak birleşir ve ikisi de zaten kayıtlıydı, yalnızca görünmüyordu:
 *
 *   switch_write_jobs  — kullanıcıların başlattığı cihaz yazmaları
 *   device_operations  — cihazın reddettiği ya da uyardığı her işlem
 *                        (eşitleme, ölçüm, sonda dahil)
 *
 * HERKESE görünür. Cihazda olan bir şey, onu başlatan kişinin değil AĞIN
 * olayıdır: bir switche yazma yapıldığını ya da eşitlemenin saatlerdir
 * düştüğünü ekibin tamamı görmeli. Aksi hâlde saatlerce düşen bir
 * eşitlemenin hatası yalnızca kimsenin bakmadığı bir listede kalır.
 *
 * "Okundu" ise kişiye özeldir; biri baktı diye ötekinin sayacı sıfırlanmaz.
 */
class NotificationFeed
{
    /**
     * Akışta en fazla kaç kayıt döner. Bildirim alanı bir arşiv değil;
     * geçmişin tamamı kayıt ekranlarında duruyor.
     */
    private const LIMIT = 30;

    /**
     * @return array{items: list<array<string, mixed>>, unseen: int, generated_at: string}
     */
    public function forUser(User $user): array
    {
        $limit = CarbonImmutable::now()->subHours((int) config('activity.notifications.window_hours'));
        $seen = $user->notifications_seen_at;

        $records = $this->writes($user, $limit)
            ->merge($this->failures($user, $limit))
            ->sortByDesc('at')
            ->take(self::LIMIT)
            ->values();

        return [
            'items' => $records->map(fn (array $record): array => [
                ...$record,
                'at' => $record['at']->toIso8601String(),
                'ago' => $record['at']->diffForHumans(),
                'unseen' => $seen === null || $record['at']->greaterThan($seen),
            ])->all(),
            'unseen' => $records->filter(
                fn (array $record): bool => $seen === null || $record['at']->greaterThan($seen),
            )->count(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * Tamamlanmış cihaz yazmaları.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function writes(User $user, CarbonImmutable $since): Collection
    {
        return SwitchWriteJob::query()
            ->with(['switch', 'user'])
            ->whereIn('switch_id', NetworkSwitch::query()->visibleTo($user)->select('id'))
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $since)
            ->latest('finished_at')
            ->limit(self::LIMIT)
            ->get()
            ->toBase()
            ->map(fn (SwitchWriteJob $is): array => [
                'id' => 'yazma:'.$is->uuid,
                'kind' => 'write',
                'title' => $is->summary,
                'switch' => $is->switch?->hostname,
                'switch_uuid' => $is->switch?->uuid,
                'actor' => $is->user?->name,
                'detail' => $is->status === SwitchWriteStatus::Failed
                    ? $is->message
                    : ($is->diff === null ? null : $this->firstLine($is->diff)),
                'label' => $is->status->label(),
                'badge' => $is->status->badge()->value,
                'at' => CarbonImmutable::parse($is->finished_at),
            ]);
    }

    /**
     * Cihazın reddettiği ya da uyardığı işlemler.
     *
     * Yazma işlerinin BAŞARISIZLARI iki kaynakta da görünürdü; burada
     * yalnızca yazma DIŞI işlemler alınır (eşitleme, ölçüm, sonda) —
     * aynı olay iki satır olmasın.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function failures(User $user, CarbonImmutable $since): Collection
    {
        return DeviceOperation::query()
            ->with('switch')
            ->where('status', '!=', 'ok')
            ->whereNotIn('operation', ['write', 'rollback'])
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($user): void {
                $query->whereNull('switch_id')
                    ->orWhereIn('switch_id', NetworkSwitch::query()->visibleTo($user)->select('id'));
            })
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->toBase()
            ->map(fn (DeviceOperation $operation): array => [
                'id' => 'islem:'.$operation->uuid,
                'kind' => 'failure',
                'title' => $operation->summary ?? $operation->operation,
                'switch' => $operation->switch?->hostname ?? $operation->target,
                'switch_uuid' => $operation->switch?->uuid,
                'actor' => null,
                'detail' => $operation->device_message === null
                    ? null
                    : $this->firstLine($operation->device_message),
                'label' => $operation->status === 'warning' ? 'Cihaz uyardı' : 'Başarısız',
                'badge' => ($operation->status === 'warning' ? BadgeVariant::Yellow : BadgeVariant::Red)->value,
                'at' => CarbonImmutable::parse($operation->created_at),
            ]);
    }

    /**
     * Cihaz metinleri çok satırlı olabiliyor; bildirimde tek satır durur.
     */
    private function firstLine(string $text): string
    {
        $single = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($single) > 160 ? mb_substr($single, 0, 157).'…' : $single;
    }
}
