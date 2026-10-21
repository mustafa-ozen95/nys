<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityChannel;
use App\Enums\ConnectionProtocol;
use App\Models\ConsoleSession;
use App\Services\ActivityRecorder;
use App\Services\Remote\TtydManager;
use Illuminate\Console\Command;

/**
 * Sesi kesilen uzak oturumları kapatır.
 *
 * KAPANIŞ HABERİ HER ZAMAN GELMEZ. Kullanıcı düğmeye basarsa, pencereyi
 * kapatırsa ya da sekmeden çıkarsa tarayıcı haber veriyor; ama tarayıcı
 * çökerse, makine uykuya girerse ya da ağ koparsa kimse haber veremez.
 * O durumda denetim kaydında "açıldı" satırı olur, "kapandı" satırı olmaz —
 * yani bir oturumun ne zaman bittiği kayıtta HİÇ yazmaz.
 *
 * ttyd zaten `--once` ile başlatılıyor, yani tarayıcı düşünce süreç kendi
 * kapanır; burada asıl yapılan İZİ KAPATMAK, süreci öldürmek değil.
 */
class ConsoleCleanCommand extends Command
{
    protected $signature = 'ag:konsol-temizle';

    protected $description = 'Sesi kesilen uzak oturumları kapatır ve kayda geçirir';

    public function handle(TtydManager $ttyd, ActivityRecorder $recorder): int
    {
        /*
         * Sınır: canlı tutma aralığının birkaç katı. Tarayıcı dört dakikada
         * bir ses veriyor; iki tur kaçırmak ağ kesintisi olabilir, üç tur
         * kaçırmak gitmiş olmaktır.
         */
        $sinir = (int) config('remote.session.ttl') + 120;

        $oturumlar = ConsoleSession::query()->sessizKalan($sinir)->with('subject')->get();

        foreach ($oturumlar as $oturum) {
            if ($oturum->port !== null) {
                $ttyd->stop($oturum->port);
            }

            $oturum->update(['closed_at' => now(), 'closed_reason' => 'dustu']);

            /*
             * Kayıt DÜŞEN KİŞİNİN adına yazılır: isteği yapan yok, sahibi
             * var. recordAs bunun için duruyor.
             */
            $recorder->recordAs(
                $oturum->user_id,
                null,
                null,
                $oturum->subject,
                'session_closed',
                "Uzak oturum kapandı (bağlantı düştü): {$oturum->label}",
                $oturum->protocol === ConnectionProtocol::Ssh ? ActivityChannel::Ssh : ActivityChannel::Rdp,
            );
        }

        $this->info($oturumlar->count().' düşen oturum kapatıldı.');

        return self::SUCCESS;
    }
}
