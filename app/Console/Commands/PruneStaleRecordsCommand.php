<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\DeviceOperation;
use App\Models\SwitchPortSample;
use App\Services\Activity\ActivityChain;
use Illuminate\Console\Command;

/**
 * Süresi dolmuş kayıtları siler.
 *
 * İki tablo sınırsız büyür: etkinlik kayıtları (her oturum açma, her CRUD,
 * cihaza yazılan her satır) ve trafik örnekleri (ölçüm cihazdan alınıyorsa
 * port başına her turda bir satır). Saklama süresi ayar ekranından gelir;
 * sıfır verilirse o tabloya dokunulmaz.
 *
 * Silme parça parça yapılır: tek bir DELETE milyonlarca satırı kilitleyip
 * paneli bekletmemeli.
 */
class PruneStaleRecordsCommand extends Command
{
    protected $signature = 'network:prune';

    protected $description = 'Süresi dolmuş denetim/erişim kayıtlarını ve trafik örneklerini siler';

    /** Tek DELETE'te silinecek en fazla satır. */
    private const CHUNK = 1000;

    public function handle(ActivityChain $chain): int
    {
        $denetim = $this->pruneChain($chain, ActivityChain::AUDIT, 'maintenance.activity_retention_days');
        $erisim = $this->pruneChain($chain, ActivityChain::ACCESS, 'maintenance.access_retention_days');
        $samples = $this->pruneSamples();
        $islemler = $this->pruneDeviceOperations();

        $this->info(
            "Denetim kaydı: {$denetim} satır, erişim kaydı: {$erisim} satır, "
            ."trafik örneği: {$samples} satır, cihaz işlemi: {$islemler} satır silindi."
        );

        return self::SUCCESS;
    }

    /**
     * Bir zincirin süresi dolmuş satırlarını siler ve o zincire yeni bir
     * çapa bırakır.
     *
     * Silme her zaman zincirin BAŞINDAN yapılır; ortadan satır silmek kalan
     * ilk satırın "önceki"sini ortada bırakır ve doğrulama zinciri kırık
     * gösterirdi. İki zincir bu yüzden ayrıdır: erişim kayıtları kendi
     * başından budanırken denetim zinciri hiç etkilenmez.
     */
    private function pruneChain(ActivityChain $chain, string $name, string $configKey): int
    {
        $days = (int) config($configKey);

        if ($days <= 0) {
            return 0;
        }

        $sinir = now()->subDays($days);

        $sonSilinecek = ActivityLog::query()
            ->where('chain', $name)
            ->where('created_at', '<', $sinir)
            ->latest('id')
            ->value('hash');

        $silinen = $this->deleteInChunks(
            fn (): int => ActivityLog::query()
                ->where('chain', $name)
                ->where('created_at', '<', $sinir)
                ->limit(self::CHUNK)
                ->delete(),
        );

        if ($silinen > 0) {
            $chain->anchorTo($sonSilinecek, $name);
            $chain->forgetTailIfChainEmpty($name);
        }

        return $silinen;
    }

    /**
     * Cihaz işlem kaydı hash zincirinde DEĞİLDİR: ayrıntı burada, olayın
     * kendisi zincirdeki etkinlik kaydında durur. Bu yüzden ortadan silmek
     * bir şeyi kırmaz ve çapaya gerek yoktur.
     */
    private function pruneDeviceOperations(): int
    {
        $days = (int) config('activity.device_operations.retention_days');

        if ($days <= 0) {
            return 0;
        }

        $sinir = now()->subDays($days);

        return $this->deleteInChunks(
            fn (): int => DeviceOperation::query()
                ->where('created_at', '<', $sinir)
                ->limit(self::CHUNK)
                ->delete(),
        );
    }

    private function pruneSamples(): int
    {
        $days = (int) config('measurement.sample_retention_days');

        if ($days <= 0) {
            /*
             * Sıfır "örnek tutma" demektir; kapatıldığında birikmiş olanlar da
             * temizlenir, aksi hâlde tablo sonsuza kadar öylece kalırdı.
             */
            return $this->deleteInChunks(
                fn (): int => SwitchPortSample::query()->limit(self::CHUNK)->delete(),
            );
        }

        return $this->deleteInChunks(
            fn (): int => SwitchPortSample::query()
                ->where('sampled_at', '<', now()->subDays($days))
                ->limit(self::CHUNK)
                ->delete(),
        );
    }

    /**
     * @param  \Closure(): int  $delete
     */
    private function deleteInChunks(\Closure $delete): int
    {
        $total = 0;

        do {
            $deleted = $delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
