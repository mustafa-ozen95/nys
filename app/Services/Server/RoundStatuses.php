<?php

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\PasswordRotationStage;
use App\Enums\PasswordRotationStatus;
use App\Jobs\ServerPasswordRotateJob;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Süren parola turlarının "sunucu => ilerleme" eşlemesi.
 *
 * EKRAN İLE UÇ AYNI KAYNAKTAN OKUR. Liste sayfası ilk çizimde bunu kullanıyor,
 * yoklama ucu da; ikisi ayrı yerde hesaplansaydı biri değiştiğinde diğeri
 * sessizce eskir ve çubuk sayfa yenilenince zıplardı.
 *
 * Biçim switch turlarıyla AYNIDIR: ekranda aynı betik çiziyor
 * (resources/js/components/rotation-progress.js).
 */
class RoundStatuses
{
    /**
     * @param  Collection<int, Server>  $sunucular
     * @return array<string, array<string, mixed>>
     */
    public function for(Collection $servers): array
    {
        $map = [];

        foreach ($servers as $server) {
            $status = $this->status($server);

            if ($status !== null) {
                $map[(string) $server->uuid] = $status;
            }
        }

        return $map;
    }

    /**
     * Bu sunucuda süren bir tur var mı? Yoksa null.
     *
     * @return array<string, mixed>|null
     */
    private function status(Server $server): ?array
    {
        $round = $server->latestPasswordRotation;
        $pending = ServerPasswordRotateJob::pending((int) $server->id);

        /*
         * Kuyruğa alındı ama işçi henüz başlamadı: tur kaydı yok, iş var.
         * Kayıt izden SONRA açıldıysa iş başlamış demektir ve iz düşer.
         */
        $notStarted = $pending !== null
            && ($round === null || $round->created_at?->timestamp < $pending);

        if ($notStarted) {
            return [
                'yuzde' => 4,
                'adim' => 0,
                'toplam' => PasswordRotationStage::STEP_COUNT,
                'metin' => 'Sıraya alındı, işçi bekleniyor…',
                'durum' => PasswordRotationStatus::Pending->value,
            ];
        }

        if ($round === null
            || ! in_array($round->status, [PasswordRotationStatus::Pending, PasswordRotationStatus::Running], true)) {
            return null;
        }

        ServerPasswordRotateJob::traceDelete((int) $server->id);

        $stage = $round->stage ?? PasswordRotationStage::Started;
        $step = $stage->step();

        return [
            'yuzde' => (int) round($step / PasswordRotationStage::STEP_COUNT * 100),
            'adim' => $step,
            'toplam' => PasswordRotationStage::STEP_COUNT,
            'metin' => $stage->running(),
            'durum' => $round->status->value,
        ];
    }
}
