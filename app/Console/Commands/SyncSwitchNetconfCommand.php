<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesSwitchSync;
use App\Jobs\SyncSwitchNetconfJob;
use Illuminate\Console\Command;

/**
 * SNMP'nin veremediği yapılandırma verisini cihazdan NETCONF ile okur:
 * routing tablosu ve prefix list'ler.
 *
 * Yalnızca okuma yapılır.
 */
class SyncSwitchNetconfCommand extends Command
{
    use DispatchesSwitchSync;

    protected $signature = 'network:sync-netconf
        {switch? : Switch adı ya da yönetim IP’si}
        {--force : Değişim algısını atla ve her şeyi yeniden oku}';

    protected $description = 'Route ve prefix list bilgisini NETCONF ile eşitler';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        // Oturum kurmak cihazda mgd fork'lar; switchler arasına boşluk konur.
        return $this->dispatchForSwitches(
            fn (int $switchId, int $delay) => SyncSwitchNetconfJob::dispatch($switchId, $force)
                ->delay(now()->addSeconds($delay)),
            delayStep: 15,
        );
    }
}
