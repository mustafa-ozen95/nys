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
        $sinir = CarbonImmutable::now()->subHours((int) config('activity.notifications.window_hours'));
        $gorulen = $user->notifications_seen_at;

        $kayitlar = $this->writes($user, $sinir)
            ->merge($this->failures($user, $sinir))
            ->sortByDesc('at')
            ->take(self::LIMIT)
            ->values();

        return [
            'items' => $kayitlar->map(fn (array $kayit): array => [
                ...$kayit,
                'at' => $kayit['at']->toIso8601String(),
                'ago' => $kayit['at']->diffForHumans(),
                'unseen' => $gorulen === null || $kayit['at']->greaterThan($gorulen),
            ])->all(),
            'unseen' => $kayitlar->filter(
                fn (array $kayit): bool => $gorulen === null || $kayit['at']->greaterThan($gorulen),
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
            ->where(function ($sorgu) use ($user): void {
                $sorgu->whereNull('switch_id')
                    ->orWhereIn('switch_id', NetworkSwitch::query()->visibleTo($user)->select('id'));
            })
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->toBase()
            ->map(fn (DeviceOperation $islem): array => [
                'id' => 'islem:'.$islem->uuid,
                'kind' => 'failure',
                'title' => $islem->summary ?? $islem->operation,
                'switch' => $islem->switch?->hostname ?? $islem->target,
                'switch_uuid' => $islem->switch?->uuid,
                'actor' => null,
                'detail' => $islem->device_message === null
                    ? null
                    : $this->firstLine($islem->device_message),
                'label' => $islem->status === 'warning' ? 'Cihaz uyardı' : 'Başarısız',
                'badge' => ($islem->status === 'warning' ? BadgeVariant::Yellow : BadgeVariant::Red)->value,
                'at' => CarbonImmutable::parse($islem->created_at),
            ]);
    }

    /**
     * Cihaz metinleri çok satırlı olabiliyor; bildirimde tek satır durur.
     */
    private function firstLine(string $text): string
    {
        $tek = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($tek) > 160 ? mb_substr($tek, 0, 157).'…' : $tek;
    }
}
