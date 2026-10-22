<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\CenterClient;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Console\Command;

/**
 * Merkezdeki rol ve durum değişikliklerini yerel hesaplara taşır.
 *
 * NEDEN VAR: erişimi merkezde kalkan biri, buradaki oturumu açık kaldıkça
 * erişmeye devam edebilir. Panelde gezinen kişi için bunu ara katman zaten
 * yapıyor (bkz. VerifyIdentityStatus); bu komut hiç uğramayan hesapları da
 * kapatır ve toplu bir eşitleme gerektiğinde elle çalıştırılır.
 *
 * MERKEZE TEK İSTEK atılır: bütün kimlikler bir arada sorulur.
 */
class IdentitySyncCommand extends Command
{
    protected $signature = 'identity:sync';

    protected $description = 'Merkezdeki rol ve durum değişikliklerini yerel hesaplara uygular';

    public function handle(CenterClient $center, IdentitySyncer $syncer): int
    {
        if (! $center->isOpen()) {
            $this->warn('Kimlik Yönetim Merkezi yapılandırılmamış; eşitleme yapılmadı.');

            return self::SUCCESS;
        }

        $counter = $syncer->sync();

        $this->info(
            "{$counter['sorulan']} kimlik soruldu; {$counter['kapatilan']} hesap kapatıldı, "
            ."{$counter['acilan']} hesap açıldı, {$counter['rol_degisen']} hesabın rolü güncellendi."
        );

        return self::SUCCESS;
    }
}
