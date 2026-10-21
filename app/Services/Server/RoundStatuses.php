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
    public function icin(Collection $sunucular): array
    {
        $harita = [];

        foreach ($sunucular as $sunucu) {
            $durum = $this->durum($sunucu);

            if ($durum !== null) {
                $harita[(string) $sunucu->uuid] = $durum;
            }
        }

        return $harita;
    }

    /**
     * Bu sunucuda süren bir tur var mı? Yoksa null.
     *
     * @return array<string, mixed>|null
     */
    private function durum(Server $sunucu): ?array
    {
        $tur = $sunucu->latestPasswordRotation;
        $bekleyen = ServerPasswordRotateJob::bekleyen((int) $sunucu->id);

        /*
         * Kuyruğa alındı ama işçi henüz başlamadı: tur kaydı yok, iş var.
         * Kayıt izden SONRA açıldıysa iş başlamış demektir ve iz düşer.
         */
        $baslamamis = $bekleyen !== null
            && ($tur === null || $tur->created_at?->timestamp < $bekleyen);

        if ($baslamamis) {
            return [
                'yuzde' => 4,
                'adim' => 0,
                'toplam' => PasswordRotationStage::ADIM_SAYISI,
                'metin' => 'Sıraya alındı, işçi bekleniyor…',
                'durum' => PasswordRotationStatus::Pending->value,
            ];
        }

        if ($tur === null
            || ! in_array($tur->status, [PasswordRotationStatus::Pending, PasswordRotationStatus::Running], true)) {
            return null;
        }

        ServerPasswordRotateJob::iziSil((int) $sunucu->id);

        $asama = $tur->stage ?? PasswordRotationStage::Started;
        $adim = $asama->step();

        return [
            'yuzde' => (int) round($adim / PasswordRotationStage::ADIM_SAYISI * 100),
            'adim' => $adim,
            'toplam' => PasswordRotationStage::ADIM_SAYISI,
            'metin' => $asama->running(),
            'durum' => $tur->status->value,
        ];
    }
}
