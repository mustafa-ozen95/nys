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
    protected $signature = 'network:log-seal {--confirm : Mührü yaz (yoksa yalnızca neyin mühürleneceğini gösterir)}';

    protected $description = 'Bilinen eski zincir kırıklarını imzalı mühürle dondurur';

    public function handle(ActivityChain $chain): int
    {
        if (! $this->option('confirm')) {
            $result = $chain->verify();
            $this->line("Şu an kırık: {$result['broken_id']} ilk bozuk; mühürlenecek sayı --confirm ile yazılırken gösterilir.");
            $this->line('Yazmak için: network:log-seal --confirm');

            return self::SUCCESS;
        }

        $result = $chain->seal('Bilinen eski kırıklar; satırlar değiştirilmedi');

        foreach ($result['chains'] as $name => $count) {
            $this->line("  {$name}: {$count} kırık satır mühürlendi");
        }

        $this->info("Toplam {$result['total']} satır mühürlendi.");

        return self::SUCCESS;
    }
}
