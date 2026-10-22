<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Update\UpdateCheck;
use Illuminate\Console\Command;

/**
 * Panelin yeni sürümü var mı: GitHub'daki son başarılı imaj derlemesiyle
 * karşılaştırır. YALNIZCA BAKAR; güncellemeyi sunucudan yapın.
 *
 * Zamanlayıcı her sabah çağırır; sonuç Ayarlar → Güncelleme'de ve yeni
 * sürüm varsa yöneticinin uyarı şeridinde görünür. Elle de çalıştırılabilir:
 *
 *     php artisan update:check
 */
class UpdateCheckCommand extends Command
{
    protected $signature = 'update:check';

    protected $description = 'Yeni sürüm var mı: GitHub\'daki son başarılı imaj derlemesiyle karşılaştırır (yalnızca bakar)';

    public function handle(UpdateCheck $audit): int
    {
        $result = $audit->check('günlük tur');

        $message = $result['durum'].': '.($result['aciklama'] ?? '');
        $result['durum'] === 'hata' ? $this->components->error($message) : $this->components->info($message);

        return $result['durum'] === 'hata' ? self::FAILURE : self::SUCCESS;
    }
}
