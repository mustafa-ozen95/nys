<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Activity\ActivityChain;
use Illuminate\Console\Command;

/**
 * Bilinen eski zincir kırıklarını mühürler (bkz. ActivityChain::seal).
 *
 * Satırlara DOKUNMAZ; yalnızca o anki kırık satırların parmak izini imzalı
 * dosyaya yazar. Yeniden çalıştırmak mührü bugünkü kırıkla değiştirir; bu
 * yüzden onay istenir.
 */
class SealActivityChainCommand extends Command
{
    protected $signature = 'network:log-seal {--onay : Mührü yaz (yoksa yalnızca neyin mühürleneceğini gösterir)}';

    protected $description = 'Bilinen eski zincir kırıklarını imzalı mühürle dondurur';

    public function handle(ActivityChain $chain): int
    {
        if (! $this->option('onay')) {
            $sonuc = $chain->verify();
            $this->line("Şu an kırık: {$sonuc['broken_id']} ilk bozuk; mühürlenecek sayı --onay ile yazılırken gösterilir.");
            $this->line('Yazmak için: network:log-seal --onay');

            return self::SUCCESS;
        }

        $sonuc = $chain->seal('Bilinen eski kırıklar; satırlar değiştirilmedi');

        foreach ($sonuc['chains'] as $ad => $adet) {
            $this->line("  {$ad}: {$adet} kırık satır mühürlendi");
        }

        $this->info("Toplam {$sonuc['total']} satır mühürlendi.");

        return self::SUCCESS;
    }
}
