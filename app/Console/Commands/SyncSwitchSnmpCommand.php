<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DispatchesSwitchSync;
use App\Jobs\SyncSwitchCommitsJob;
use App\Jobs\SyncSwitchIpAddressesJob;
use App\Jobs\SyncSwitchVlansJob;
use Illuminate\Console\Command;

/**
 * Zabbix'in vermediği verileri cihazdan SNMP ile okur: IP adresleri, ve
 * NETCONF kimliği tanımlı olmayan cihazlar için VLAN'lar ile commit geçmişi.
 *
 * VLAN ve commit işleri NETCONF'lu cihazlarda kendiliğinden atlanır; o veriyi
 * NETCONF daha doğru okuyor ve iki kaynağın aynı satırı yazması karışıklık
 * üretir. Bu komut onlar için yalnızca yedek yoldur.
 *
 * Commit geçmişi burada DEĞİLDİR: SNMP karşılığı (jnxCmCfgChgEventTable)
 * açıklama alanı taşımaz ve zamanı cihazın çalışma süresinden hesaplar, yani
 * cihazın saati yanlışsa zaman da yanlış çıkar. NETCONF mutlak zaman damgası
 * ve commit açıklamasını doğrudan veriyor; geçmiş network:sync-netconf ile
 * okunur. İkisi birden çalışsaydı aynı satırı farklı zamanlarla ezerlerdi.
 *
 * Yalnızca okuma yapılır.
 */
class SyncSwitchSnmpCommand extends Command
{
    use DispatchesSwitchSync;

    protected $signature = 'network:sync-snmp {switch? : Switch adı ya da yönetim IP’si}';

    protected $description = 'IP adresi, VLAN ve commit geçmişini SNMP ile eşitler';

    public function handle(): int
    {
        // SNMP yavaştır: switchler arasına boşluk konur, üç iş sıraya girer.
        return $this->dispatchForSwitches(function (int $switchId, int $delay): void {
            SyncSwitchIpAddressesJob::dispatch($switchId)->delay(now()->addSeconds($delay));
            SyncSwitchVlansJob::dispatch($switchId)->delay(now()->addSeconds($delay + 10));
            SyncSwitchCommitsJob::dispatch($switchId)->delay(now()->addSeconds($delay + 20));
        }, delayStep: 30);
    }
}
