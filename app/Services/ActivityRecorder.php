<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityChannel;
use App\Services\Activity\ActivityChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Etkinlik kaydının tek yazım noktası. Controller'lar kayıt biçimini
 * tekrarlamaz.
 *
 * Yazma doğrudan modele değil hash zincirine yapılır: kayıt sonradan
 * değiştirilirse ya da silinirse zincir kırılır ve doğrulama bunu söyler.
 */
class ActivityRecorder
{
    public function __construct(
        private Request $request,
        private ActivityChain $chain,
    ) {}

    /**
     * İşlemin gerekçesi. Okuma isteklerinde ve gerekçe istenmeyen uçlarda
     * boştur.
     */
    private function reason(): ?string
    {
        $reason = trim((string) $this->request->input('reason'));

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    public function created(Model $subject, string $description): void
    {
        $this->record($subject, 'created', $description);
    }

    public function updated(Model $subject, string $description): void
    {
        $this->record($subject, 'updated', $description);
    }

    public function deleted(Model $subject, string $description): void
    {
        $this->record($subject, 'deleted', $description);
    }

    /**
     * İSTEK OLMADAN kayıt yazar.
     *
     * Cihaza yazma kuyruğa alındığında sonucu bir iş öğreniyor ve o işin
     * isteği yok. Kullanıcı, gerekçe ve IP isteğin anındaki hâliyle işe
     * taşınır ve buraya olduğu gibi verilir; yoksa cihazdaki değişikliğin
     * kaydı sahipsiz kalırdı.
     */
    public function recordAs(
        ?int $userId,
        ?string $reason,
        ?string $ipAddress,
        ?Model $subject,
        string $action,
        string $description,
        ActivityChannel $channel = ActivityChannel::Crud,
    ): void {
        $this->chain->append([
            'user_id' => $userId,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'channel' => $channel,
            'action' => $action,
            'description' => $description,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'ip_address' => $ipAddress,
        ]);
    }

    public function record(
        ?Model $subject,
        string $action,
        string $description,
        ActivityChannel $channel = ActivityChannel::Crud,
    ): void {
        $this->chain->append([
            'user_id' => $this->request->user()?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'channel' => $channel,
            'action' => $action,
            'description' => $description,

            // Kullanıcının yazdığı gerekçe. Onay penceresinden gelir ve
            // hash zincirine girdiği için sonradan değiştirilemez.
            'reason' => $this->reason(),
            'ip_address' => $this->request->ip(),
        ]);
    }
}
