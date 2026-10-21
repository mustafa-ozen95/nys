<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Activity\ActivityChain;
use Illuminate\Console\Command;

/**
 * Etkinlik kaydının değiştirilmediğini doğrular.
 *
 * Her satır kendinden öncekinin özetini taşır; bir satır sonradan
 * değiştirilir ya da silinirse ondan sonraki özetler tutmaz. Komut zinciri
 * baştan yürür ve kırılmanın olduğu ilk satırı bildirir.
 *
 * Sonuç önbelleğe yazılır; kayıtlar ekranı bütünlük rozetini oradan okur ve
 * her açılışta yüz bin satırı yeniden özetlemez.
 */
class VerifyActivityChainCommand extends Command
{
    protected $signature = 'network:log-verify';

    protected $description = 'Etkinlik kaydı zincirinin bozulmadığını doğrular';

    public function handle(ActivityChain $chain): int
    {
        $sonuc = $chain->verify();

        if ($sonuc['ok']) {
            $this->info("Zincir sağlam: {$sonuc['checked']} kayıt doğrulandı.");

            if (($sonuc['acknowledged'] ?? 0) > 0) {
                $this->line("  {$sonuc['acknowledged']} kayıt BİLİNEN ESKİ KIRIK olarak mühürlü ve değişmemiş (bkz. network:log-seal).");
            }

            if ($sonuc['unprotected'] > 0) {
                $this->line("  {$sonuc['unprotected']} kayıt zincirden önce yazılmış; onlar doğrulanamaz.");
            }

            return self::SUCCESS;
        }

        // Çıkış kodu sıfırdan farklıdır: zamanlanmış çalıştırmada sessiz
        // kalmamalı, izleme bunu yakalayabilmeli.
        $this->error("ZİNCİR KIRIK. İlk bozuk kayıt: #{$sonuc['broken_id']} ({$sonuc['checked']} kayıt tarandı).");

        return self::FAILURE;
    }
}
